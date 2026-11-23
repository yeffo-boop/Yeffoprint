<?php
/**
 * Admin app endpoints for the production screen (`#/design-sheet/{id}`,
 * class-order-design-sheet.php): the order's labels, and saving which
 * ones are designed.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Design_Sheet_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/order/(?P<id>\d+)/design-sheet', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_sheet' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/order/(?P<id>\d+)/designed', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'save_designed' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get_sheet( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return rest_ensure_response( array_merge(
			YeffoPrint_Order_Design_Sheet::sheet( $order ),
			[ 'designed' => (object) YeffoPrint_Order_Design_Sheet::designed( $order ) ]
		) );
	}

	/** Body: { keys: ["itemId:index", …], done: true|false }. */
	public function save_designed( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$keys = $request->get_param( 'keys' );
		if ( ! is_array( $keys ) || ! $keys ) {
			return new \WP_Error( 'yeffoprint_bad_keys', __( 'Choose at least one label.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$designed = YeffoPrint_Order_Design_Sheet::set_designed( $order, array_map( 'strval', $keys ), (bool) $request->get_param( 'done' ) );

		return rest_ensure_response( [ 'designed' => (object) $designed ] );
	}

	private function validate_order( int $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'yeffoprint_order_not_found', __( 'That order could not be found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}
		return $order;
	}
}
