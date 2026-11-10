<?php
/**
 * Order delay emails — direct request (2026-11-10, printer down): "I need
 * the ability to send order delayed emails to customers". Jeff picked
 * mockup B with C's thank-you coupon code. Sent from the admin app:
 * tick orders in All orders and tap "Send delay email", or use the
 * button on one order's page.
 *
 * Each order gets a note and the `_yp_delay_emailed` meta, so a second
 * bulk send skips it unless "Send again" is ticked. Orders that aren't
 * waiting to ship (shipped, delivered, cancelled, unpaid...) are always
 * skipped, and 3D-print-only orders are skipped by default since they
 * use a different printer.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Delay_Email {

	private const NAMESPACE      = 'yeffoprint-core/v1';
	private const MAX            = 100;
	private const PREVIEW_ACTION = 'yeffoprint_delay_email_preview';
	public const ORDER_META      = '_yp_delay_emailed';
	public const DEFAULTS_OPTION = 'yeffoprint_delay_email_defaults';

	/** Paid and not shipped yet. */
	private const STATUSES = [ 'processing', 'on-hold', 'in-production', 'curing' ];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_post_' . self::PREVIEW_ACTION, [ $this, 'render_preview' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/orders/delay-email', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_defaults' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'send_bulk' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );
	}

	/** Last used date/code/note, so the next send is pre-filled. */
	public static function defaults(): array {
		$saved = (array) get_option( self::DEFAULTS_OPTION, [] );
		return [
			'ship_date'   => (string) ( $saved['ship_date'] ?? '' ),
			'code'        => (string) ( $saved['code'] ?? '' ),
			'note'        => (string) ( $saved['note'] ?? '' ),
			'preview_url' => add_query_arg( 'action', self::PREVIEW_ACTION, admin_url( 'admin-post.php' ) ),
		];
	}

	public function get_defaults(): \WP_REST_Response {
		return rest_ensure_response( self::defaults() );
	}

	/** @return array{ship_date:string, code:string, note:string}|\WP_Error */
	private static function read_args( array $params ) {
		$ship_date = sanitize_text_field( (string) ( $params['ship_date'] ?? '' ) );
		if ( '' !== $ship_date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ship_date ) ) {
			return new \WP_Error( 'yeffoprint_delay_bad_date', __( 'Pick the ship date from the date box.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$code = strtoupper( sanitize_text_field( (string) ( $params['code'] ?? '' ) ) );
		if ( '' !== $code && ! self::coupon( $code ) ) {
			return new \WP_Error(
				'yeffoprint_delay_no_coupon',
				/* translators: %s: coupon code */
				sprintf( __( 'There’s no active coupon called %s. Create it under Customers › Coupons first, or leave the code blank.', 'yeffoprint-core' ), $code ),
				[ 'status' => 400 ]
			);
		}

		return [
			'ship_date' => $ship_date,
			'code'      => $code,
			'note'      => sanitize_textarea_field( (string) ( $params['note'] ?? '' ) ),
		];
	}

	private static function coupon( string $code ): ?\WC_Coupon {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return null;
		}
		$coupon = new \WC_Coupon( $code );
		return $coupon->get_id() && 'publish' === get_post_status( $coupon->get_id() ) ? $coupon : null;
	}

	/** "10% off your next order", "$5.00 off your next order". */
	private static function code_line( string $code ): string {
		$coupon = $code ? self::coupon( $code ) : null;
		if ( ! $coupon ) {
			return __( 'A thank-you code for your next order', 'yeffoprint-core' );
		}
		$amount = (float) $coupon->get_amount();
		$off    = 'percent' === $coupon->get_discount_type()
			? rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' ) . '%'
			: html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ) );
		/* translators: %s: "10%" or "$5.00" */
		return sprintf( __( '%s off your next order', 'yeffoprint-core' ), $off );
	}

	private static function is_print_only( \WC_Order $order ): bool {
		$items = $order->get_items();
		if ( ! $items || ! class_exists( 'YeffoPrint_Print_Product' ) ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( YeffoPrint_Print_Product::get_print_id( (int) $item->get_product_id() ) <= 0 ) {
				return false;
			}
		}
		return true;
	}

	/** Why this order is skipped, or '' to send. */
	private static function skip_reason( \WC_Order $order, bool $skip_prints, bool $resend ): string {
		if ( ! in_array( $order->get_status(), self::STATUSES, true ) ) {
			return __( 'not waiting to ship', 'yeffoprint-core' );
		}
		if ( ! is_email( $order->get_billing_email() ) ) {
			return __( 'no email address', 'yeffoprint-core' );
		}
		if ( $skip_prints && self::is_print_only( $order ) ) {
			return __( '3D prints only', 'yeffoprint-core' );
		}
		if ( ! $resend && $order->get_meta( self::ORDER_META ) ) {
			return __( 'already emailed', 'yeffoprint-core' );
		}
		return '';
	}

	/** Template variables for one order. */
	private static function template_args( \WC_Order $order, array $args ): array {
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$change = class_exists( 'YeffoPrint_Order_Label_Changes' ) && YeffoPrint_Order_Label_Changes::customer_can_request( $order )
			? YeffoPrint_Order_Label_Changes::url( $order )
			: '';

		return [
			'email_heading' => __( 'Your order is delayed', 'yeffoprint-core' ),
			'order'         => $order,
			'name'          => $order->get_billing_first_name() ?: __( 'there', 'yeffoprint-core' ),
			'ship_label'    => $args['ship_date'] ? wp_date( 'l, F j', strtotime( $args['ship_date'] . ' 12:00:00' ) ) : '',
			'note'          => $args['note'],
			'code'          => $args['code'],
			'code_line'     => self::code_line( $args['code'] ),
			'change_url'    => $change,
			/* translators: 1: first name, 2: site name */
			// Jeff: "sign the emails from Yeffo instead of from Jeff".
			'signer'        => sprintf( __( '%1$s at %2$s', 'yeffoprint-core' ), 'Yeffo', $site ),
		];
	}

	private static function subject( \WC_Order $order ): string {
		/* translators: %s: order number */
		return sprintf( __( 'Your order #%s is delayed (printer repair)', 'yeffoprint-core' ), $order->get_order_number() );
	}

	private static function mailer(): ?\WC_Email {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return null;
		}
		// WC_Email only exists once WooCommerce's mailer is loaded.
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-email-payment-balance.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-email-order-delay.php';
		return new YeffoPrint_Email_Order_Delay();
	}

	public static function send( \WC_Order $order, array $args ): bool {
		$email = self::mailer();
		if ( ! $email || ! $email->send_notice( $order->get_billing_email(), self::subject( $order ), self::template_args( $order, $args ) ) ) {
			return false;
		}

		$order->update_meta_data( self::ORDER_META, time() );
		$order->save_meta_data();
		$order->add_order_note( sprintf(
			/* translators: 1: date or "no date", 2: code or "no code" */
			__( 'Delay email sent (ship date: %1$s, code: %2$s).', 'yeffoprint-core' ),
			$args['ship_date'] ?: __( 'none', 'yeffoprint-core' ),
			$args['code'] ?: __( 'none', 'yeffoprint-core' )
		) );
		return true;
	}

	/**
	 * Body: `{ ids: [], ship_date?: 'YYYY-MM-DD', code?, note?, skip_prints?: bool, resend?: bool }`.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function send_bulk( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: [];
		$ids    = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $params['ids'] ?? [] ) ) ) ) ), 0, self::MAX );
		if ( ! $ids ) {
			return new \WP_Error( 'yeffoprint_delay_no_orders', __( 'Pick at least one order.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$args = self::read_args( $params );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		update_option( self::DEFAULTS_OPTION, $args, false );

		$skip_prints = ! array_key_exists( 'skip_prints', $params ) || ! empty( $params['skip_prints'] );
		$resend      = ! empty( $params['resend'] );
		$sent        = [];
		$skipped     = [];

		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$reason = self::skip_reason( $order, $skip_prints, $resend );
			if ( '' === $reason && ! self::send( $order, $args ) ) {
				$reason = __( 'email failed', 'yeffoprint-core' );
			}
			if ( '' === $reason ) {
				$sent[] = $order->get_order_number();
			} else {
				$skipped[] = [ 'number' => $order->get_order_number(), 'reason' => $reason ];
			}
		}

		return rest_ensure_response( [ 'sent' => $sent, 'skipped' => $skipped ] );
	}

	/** admin-post.php?action=…&id=&ship_date=&code=&note= — the email as the customer will see it, not sent. */
	public function render_preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'yeffoprint-core' ), 403 );
		}

		$order = wc_get_order( absint( $_GET['id'] ?? 0 ) );
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Pick an order first.', 'yeffoprint-core' ), 404 );
		}

		$args = self::read_args( [
			'ship_date' => wp_unslash( $_GET['ship_date'] ?? '' ),
			'code'      => wp_unslash( $_GET['code'] ?? '' ),
			'note'      => wp_unslash( $_GET['note'] ?? '' ),
		] );
		if ( is_wp_error( $args ) ) {
			wp_die( esc_html( $args->get_error_message() ), 400 );
		}

		$email = self::mailer();
		if ( ! $email ) {
			wp_die( esc_html__( 'Email isn’t available right now.', 'yeffoprint-core' ), 503 );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		$html = wc_get_template_html( 'emails/customer-order-delay.php', array_merge( self::template_args( $order, $args ), [ 'email' => $email ] ) );
		// Same inlining the real send does, so the preview matches the inbox.
		// The subject bar goes in after inlining: anything ahead of the
		// email's own <html> makes the inliner fail ("There is no HEAD
		// element") and the preview showed up unstyled.
		$html = $email->style_inline( $html );
		$bar  = '<p style="font:14px sans-serif;padding:8px 16px;background:#141414;color:#fff;margin:0">' . esc_html( 'Subject: ' . self::subject( $order ) ) . '</p>';
		$html = preg_replace( '/<body[^>]*>/i', '$0' . addcslashes( $bar, '\\$' ), $html, 1, $count );
		echo $count ? $html : $bar . $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
