<?php
/**
 * Admin REST endpoints for the admin app's Messages screen
 * (views/messages.js): list contact form messages and web design quote
 * requests, mark one done / reopen / delete it. Storage lives in
 * includes/messages/class-messages.php.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Messages_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/messages', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_messages' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/messages/(?P<id>\d+)/(?P<action>done|reopen|delete)', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'act' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	public function list_messages( \WP_REST_Request $request ): \WP_REST_Response {
		$filter   = sanitize_key( (string) $request->get_param( 'filter' ) ) ?: 'new';
		$response = rest_ensure_response( array_merge(
			YeffoPrint_Messages::list( $filter, (int) $request->get_param( 'page' ) ),
			[ 'new_count' => YeffoPrint_Messages::count_new() ]
		) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function act( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! YeffoPrint_Messages::exists( $id ) ) {
			return new \WP_Error( 'yeffoprint_message_missing', __( 'That message no longer exists.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		switch ( $request['action'] ) {
			case 'done':
				YeffoPrint_Messages::set_state( $id, 'done' );
				break;
			case 'reopen':
				YeffoPrint_Messages::set_state( $id, 'new' );
				break;
			case 'delete':
				YeffoPrint_Messages::delete( $id );
				break;
		}

		return rest_ensure_response( [ 'ok' => true, 'new_count' => YeffoPrint_Messages::count_new() ] );
	}
}
