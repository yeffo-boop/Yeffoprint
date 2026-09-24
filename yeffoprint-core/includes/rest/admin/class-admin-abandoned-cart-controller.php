<?php
/**
 * Admin REST endpoints for the admin app's Abandoned Carts screen
 * (views/abandoned-carts.js): the last 30 days of carts with the
 * recovery stats, the recovery settings, and per-cart Send / Stop
 * actions. All the logic lives in class-abandoned-carts.php; this only
 * exposes it.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Abandoned_Cart_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/abandoned-carts', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_carts' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/abandoned-carts/settings', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'save_settings' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/abandoned-carts/(?P<id>\d+)/(?P<action>send|stop)', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'act' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	public function list_carts(): \WP_REST_Response {
		return rest_ensure_response( array_merge(
			YeffoPrint_Abandoned_Carts::report(),
			[
				'settings'  => YeffoPrint_Abandoned_Carts::settings(),
				'away_mode' => (bool) YeffoPrint_Admin_Menu::away_mode(),
			]
		) );
	}

	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params() ?: [];
		return rest_ensure_response( [ 'settings' => YeffoPrint_Abandoned_Carts::save_settings( is_array( $params ) ? $params : [] ) ] );
	}

	/**
	 * `send` sends whichever reminder is next (Email 1, then Email 2);
	 * `stop` closes the cart so nothing more goes out.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function act( \WP_REST_Request $request ) {
		$row = YeffoPrint_Abandoned_Carts::get_row( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'yeffoprint_abandoned_cart_missing', __( 'That cart no longer exists.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}
		if ( YeffoPrint_Abandoned_Carts::STATUS_OPEN !== $row['status'] ) {
			return new \WP_Error( 'yeffoprint_abandoned_cart_closed', __( 'This cart is already closed.', 'yeffoprint-core' ), [ 'status' => 409 ] );
		}

		if ( 'stop' === $request['action'] ) {
			YeffoPrint_Abandoned_Carts::stop( (int) $row['id'] );
			return rest_ensure_response( [ 'ok' => true ] );
		}

		$stage = (int) $row['stage'] + 1;
		if ( $stage > 2 ) {
			return new \WP_Error( 'yeffoprint_abandoned_cart_done', __( 'Both reminders have already gone out.', 'yeffoprint-core' ), [ 'status' => 409 ] );
		}
		if ( YeffoPrint_Abandoned_Carts::is_opted_out( $row['email'] ) ) {
			return new \WP_Error( 'yeffoprint_abandoned_cart_optout', __( 'This customer asked not to get cart reminders.', 'yeffoprint-core' ), [ 'status' => 409 ] );
		}

		YeffoPrint_Abandoned_Carts::send_stage( $row, $stage );
		return rest_ensure_response( [ 'ok' => true, 'stage' => $stage ] );
	}
}
