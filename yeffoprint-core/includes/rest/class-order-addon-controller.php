<?php
/**
 * The manual order-number + email fallback on /add-to-order/
 * (yeffoprint theme's blocks/order-addon-gate/render.php) — for a
 * customer who reaches the page with no order_key in the URL (lost the
 * email, or typed the address by hand). Same guest-safe order+email
 * match as YeffoPrint_Telegram_Order_Lookup::find(), not a new one.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Addon_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	/** Same bar as class-web-chat-controller.php's own per-IP limit — blunt enough to stop order-number/email enumeration, generous enough for a real customer who mistypes once or twice. */
	private const RATE_LIMIT_WINDOW = 5 * MINUTE_IN_SECONDS;
	private const RATE_LIMIT_MAX    = 8;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/addon/verify', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'verify' ],
			'permission_callback' => '__return_true',
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function verify( \WP_REST_Request $request ) {
		if ( $this->is_rate_limited() ) {
			return new \WP_Error( 'yeffoprint_addon_rate_limited', __( "You're trying a little fast — wait a few minutes and try again.", 'yeffoprint-core' ), [ 'status' => 429 ] );
		}

		$order_ref = (string) $request->get_param( 'order_ref' );
		$email     = (string) $request->get_param( 'email' );

		$order = YeffoPrint_Telegram_Order_Lookup::find( $order_ref, $email );

		if ( ! $order ) {
			return new \WP_Error( 'yeffoprint_addon_not_found', __( "We couldn't find an order matching that number and email.", 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$eligibility = YeffoPrint_Order_Addon::eligibility( $order );

		if ( ! $eligibility['eligible'] ) {
			return new \WP_Error( 'yeffoprint_addon_ineligible', $eligibility['reason'], [ 'status' => 409 ] );
		}

		$root = $eligibility['root'];
		YeffoPrint_Order_Addon::start_session( $root->get_id() );

		return rest_ensure_response( [
			'order_number' => $root->get_order_number(),
			'redirect'     => home_url( '/shop-labels/' ),
		] );
	}

	private function is_rate_limited(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return false;
		}

		$key   = 'yp_addon_verify_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return true;
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		return false;
	}
}
