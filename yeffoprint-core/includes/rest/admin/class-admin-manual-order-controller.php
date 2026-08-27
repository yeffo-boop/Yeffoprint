<?php
/**
 * Admin REST endpoints for manually creating a WooCommerce order from
 * the admin app (docs/ARCHITECTURE.md — "Manual order creation") —
 * thin wrapper, same shape as every other admin/* controller: the real
 * work is YeffoPrint_Manual_Order_Creator::create(), this just parses
 * the request and formats the response.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Manual_Order_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/manual-orders/customer-search', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'search_customers' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/manual-orders', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'create_order' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/** @return \WP_REST_Response */
	public function search_customers( \WP_REST_Request $request ) {
		$term = trim( (string) $request->get_param( 'q' ) );
		if ( '' === $term ) {
			return rest_ensure_response( [] );
		}

		$users = get_users( [
			'search'         => '*' . esc_attr( $term ) . '*',
			'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
			'number'         => 20,
			'fields'         => [ 'ID', 'display_name', 'user_email' ],
		] );

		return rest_ensure_response( array_map( static function ( $user ) {
			return [
				'id'           => (int) $user->ID,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
			];
		}, $users ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function create_order( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: [];

		$result = YeffoPrint_Manual_Order_Creator::create( $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$order            = $result['order'];
		$custom_order_id  = $result['custom_order_id'];

		return rest_ensure_response( [
			'success'                   => true,
			'order_id'                  => $order->get_id(),
			// $order->get_edit_order_url() rather than a hand-built
			// post.php?post=…&action=edit link — resolves correctly
			// whether this store is on classic post-based orders or HPOS.
			'order_edit_url'            => $order->get_edit_order_url(),
			'custom_order_id'           => $custom_order_id,
			'custom_order_approval_url' => $custom_order_id ? yeffoprint_core_proof_approval_url( $custom_order_id ) : '',
		] );
	}
}
