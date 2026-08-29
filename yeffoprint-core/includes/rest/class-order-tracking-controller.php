<?php
/**
 * Public order-tracking endpoint behind the new /track-order/ page —
 * same guest-access shape as class-proof-approval-controller.php (a
 * secret in the URL for a guest, nonce + ownership check for a logged-
 * in customer or staff), but the secret here is WooCommerce's own
 * order_key rather than a new token, since one already exists on every
 * order (class-order-tracking.php's own docblock explains why).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Tracking_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/orders/(?P<id>\d+)/tracking', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_tracking' ],
			'permission_callback' => [ $this, 'check_access' ],
		] );
	}

	/**
	 * @return true|\WP_Error
	 */
	public function check_access( \WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request->get_param( 'id' ) ) );

		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'yeffoprint_order_not_found', __( 'That order was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$key = (string) $request->get_param( 'key' );
		if ( '' !== $key && hash_equals( $order->get_order_key(), $key ) ) {
			return true;
		}

		if ( is_user_logged_in() ) {
			$owns_it = $order->get_customer_id() && $order->get_customer_id() === get_current_user_id();

			if ( $owns_it || current_user_can( 'manage_woocommerce' ) ) {
				$nonce = $request->get_header( 'X-WP-Nonce' );

				return ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) )
					? true
					: new \WP_Error( 'yeffoprint_invalid_nonce', __( 'Your session has expired. Please refresh the page and try again.', 'yeffoprint-core' ), [ 'status' => 403 ] );
			}
		}

		return new \WP_Error( 'yeffoprint_forbidden', __( "You don't have access to this order.", 'yeffoprint-core' ), [ 'status' => 403 ] );
	}

	public function get_tracking( \WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request->get_param( 'id' ) ) );

		$shipments = array_map( [ $this, 'with_live_events' ], YeffoPrint_Order_Tracking::get_shipments( $order ) );

		return rest_ensure_response( [
			'order_number' => $order->get_order_number(),
			'status'       => $order->get_status(),
			'status_label' => wc_get_order_status_name( $order->get_status() ),
			'shipments'    => $shipments,
		] );
	}

	/**
	 * Direct link ($shipment['carrier_url'], already present) is always
	 * the fallback the front-end shows when `events` comes back empty —
	 * no configured provider or a failed live lookup is never the reason
	 * a customer sees a broken page, just a plainer one.
	 */
	private function with_live_events( array $shipment ): array {
		$shipment['events'] = YeffoPrint_Order_Tracking::live_events( $shipment );

		return $shipment;
	}
}
