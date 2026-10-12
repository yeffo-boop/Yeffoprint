<?php
/**
 * Admin app endpoints for Email Orders (includes/email-orders/class-
 * email-orders.php): the list, one record (what Create Order fills in
 * from), Check now, Read again, Dismiss / Reopen / Delete, and the
 * link back to the order once Create Order has made it.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Email_Orders_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$admin = [ 'YeffoPrint_Rest_Security', 'admin_write' ];

		register_rest_route( self::NAMESPACE, '/admin/email-orders', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list' ],
			'permission_callback' => $admin,
		] );

		register_rest_route( self::NAMESPACE, '/admin/email-orders/check', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'check' ],
			'permission_callback' => $admin,
		] );

		register_rest_route( self::NAMESPACE, '/admin/email-orders/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get' ],
				'permission_callback' => $admin,
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete' ],
				'permission_callback' => $admin,
			],
		] );

		register_rest_route( self::NAMESPACE, '/admin/email-orders/(?P<id>\d+)/(?P<action>read|dismiss|reopen|created)', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'act' ],
			'permission_callback' => $admin,
		] );
	}

	public function list( \WP_REST_Request $request ): \WP_REST_Response {
		$filter = (string) $request->get_param( 'filter' );
		return rest_ensure_response( YeffoPrint_Email_Orders::list(
			in_array( $filter, [ 'open', 'done', 'all' ], true ) ? $filter : 'open',
			max( 1, (int) $request->get_param( 'page' ) )
		) );
	}

	public function check(): \WP_REST_Response {
		return rest_ensure_response( YeffoPrint_Email_Orders::check() );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! YeffoPrint_Email_Orders::exists( $id ) ) {
			return $this->not_found();
		}
		return rest_ensure_response( YeffoPrint_Email_Orders::format( get_post( $id ) ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function delete( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! YeffoPrint_Email_Orders::exists( $id ) ) {
			return $this->not_found();
		}
		YeffoPrint_Email_Orders::delete( $id );
		return rest_ensure_response( [ 'deleted' => true ] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function act( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! YeffoPrint_Email_Orders::exists( $id ) ) {
			return $this->not_found();
		}

		switch ( (string) $request['action'] ) {
			case 'read':
				YeffoPrint_Email_Orders::requeue( $id );
				YeffoPrint_Email_Orders::read( $id );
				break;
			case 'dismiss':
				YeffoPrint_Email_Orders::set_state( $id, 'dismissed' );
				break;
			case 'reopen':
				$record = YeffoPrint_Email_Orders::format( get_post( $id ) );
				YeffoPrint_Email_Orders::set_state( $id, $record['result'] ? 'ready' : 'failed' );
				break;
			case 'created':
				$order_id = (int) $request->get_param( 'order_id' );
				if ( ! $order_id || ! function_exists( 'wc_get_order' ) || ! wc_get_order( $order_id ) ) {
					return new \WP_Error( 'yeffoprint_order_not_found', __( 'That order doesn’t exist.', 'yeffoprint-core' ), [ 'status' => 400 ] );
				}
				YeffoPrint_Email_Orders::mark_created( $id, $order_id );
				break;
		}

		return rest_ensure_response( YeffoPrint_Email_Orders::format( get_post( $id ) ) );
	}

	private function not_found(): \WP_Error {
		return new \WP_Error( 'yeffoprint_email_order_not_found', __( 'This emailed order is gone.', 'yeffoprint-core' ), [ 'status' => 404 ] );
	}
}
