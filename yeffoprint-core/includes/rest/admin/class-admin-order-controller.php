<?php
/**
 * Admin REST endpoint for direct actions on a WooCommerce order — right
 * now just the Dashboard's "Send to Printer" button (docs/ARCHITECTURE.md):
 * staff mark a paid, "Processing" order as queued at the printer, moving
 * it into the new "In Production" status (class-order-production-status.php).
 * This doesn't talk to any printer/hardware — it's a manual status flag,
 * same as any other order-status change, just one click instead of
 * hunting through the classic order screen's status dropdown.
 *
 * WooCommerce orders otherwise stay outside this app's own REST layer
 * (class-admin-dashboard-controller.php's own docblock — staff still
 * manage them in classic wp-admin); this is a narrow, single-purpose
 * exception for the one action the new Dashboard needs inline.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Order_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/order/(?P<id>\d+)/send-to-printer', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'send_to_printer' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function send_to_printer( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new \WP_Error( 'yeffoprint_woocommerce_inactive', __( 'WooCommerce is not active.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'yeffoprint_order_not_found', __( 'That order could not be found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		if ( 'processing' !== $order->get_status() ) {
			return new \WP_Error(
				'yeffoprint_order_not_processing',
				__( 'This order is no longer in the Processing status — someone else may have already sent it to the printer.', 'yeffoprint-core' ),
				[ 'status' => 409 ]
			);
		}

		$order->set_status( YeffoPrint_Order_Production_Status::STATUS, __( 'Sent to printer from the dashboard.', 'yeffoprint-core' ) );
		$order->save();

		return rest_ensure_response( [ 'id' => $order->get_id(), 'status' => $order->get_status() ] );
	}
}
