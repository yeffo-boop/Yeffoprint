<?php
/**
 * Customer-facing "Order now / Accept & pay" for Web Design packages
 * that have a Checkout Price set. Creates a pending WooCommerce order
 * with the package's linked product and returns the standard
 * get_checkout_payment_url() — same pay path Manual Order Creator uses.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Order_Controller {

	private const NAMESPACE         = 'yeffoprint-core/v1';
	private const RATE_LIMIT_WINDOW = 900;
	private const RATE_LIMIT_MAX    = 5;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/web-design-order', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'create' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'guest_or_nonced_write' ],
		] );
	}

	public function create( \WP_REST_Request $request ) {
		if ( '' !== (string) $request->get_param( 'website' ) ) {
			return rest_ensure_response( [ 'success' => true ] );
		}

		$rate_limited = $this->check_rate_limit();
		if ( is_wp_error( $rate_limited ) ) {
			return $rate_limited;
		}

		$package_id = absint( $request->get_param( 'package_id' ) );
		$package    = get_post( $package_id );
		if ( ! $package || 'yp_web_design_pkg' !== $package->post_type || 'publish' !== $package->post_status ) {
			return new \WP_Error( 'yeffoprint_invalid_package', __( 'That package is not available.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$checkout_price = (float) get_post_meta( $package_id, YeffoPrint_Web_Design_Package_Meta::CHECKOUT_PRICE, true );
		$product_id     = (int) get_post_meta( $package_id, YeffoPrint_Web_Design_Package_Product::META_LINKED_PRODUCT, true );
		$product        = $product_id ? wc_get_product( $product_id ) : false;

		if ( $checkout_price <= 0 || ! $product || 'publish' !== $product->get_status() ) {
			return new \WP_Error(
				'yeffoprint_package_not_orderable',
				__( 'This package still needs a custom quote — please use Get a Quote instead.', 'yeffoprint-core' ),
				[ 'status' => 400 ]
			);
		}

		$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'yeffoprint_missing_name', __( 'Please enter your name.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'yeffoprint_invalid_email', __( 'Please enter a valid email address.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$order = wc_create_order( [
			'status'      => 'pending',
			'customer_id' => is_user_logged_in() ? get_current_user_id() : 0,
		] );

		if ( is_wp_error( $order ) ) {
			return new \WP_Error( 'yeffoprint_order_create_failed', __( 'Could not start checkout. Please try again or request a quote.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		$order->set_billing_first_name( $name );
		$order->set_billing_email( $email );
		$order->add_product( $product, 1 );
		$order->set_created_via( 'web-design-order-now' );
		YeffoPrint_Web_Design_Project_Meta::mark_order( $order );
		$order->add_order_note(
			sprintf(
				/* translators: %s: package title */
				__( 'Customer self-serve Web Design order for package “%s”.', 'yeffoprint-core' ),
				$package->post_title
			)
		);
		$order->calculate_totals( true );
		$order->save();

		// Best-effort invoice email so they have the link later too.
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$mails = WC()->mailer()->get_emails();
			if ( isset( $mails['WC_Email_Customer_Invoice'] ) ) {
				$mails['WC_Email_Customer_Invoice']->trigger( $order->get_id() );
			}
		}

		return rest_ensure_response( [
			'success'     => true,
			'order_id'    => $order->get_id(),
			'payment_url' => $order->get_checkout_payment_url(),
		] );
	}

	private function check_rate_limit() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		$key = 'yp_wd_order_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= self::RATE_LIMIT_MAX ) {
			return new \WP_Error( 'yeffoprint_rate_limited', __( 'Too many attempts — please wait a few minutes and try again.', 'yeffoprint-core' ), [ 'status' => 429 ] );
		}
		set_transient( $key, $hits + 1, self::RATE_LIMIT_WINDOW );
		return true;
	}
}
