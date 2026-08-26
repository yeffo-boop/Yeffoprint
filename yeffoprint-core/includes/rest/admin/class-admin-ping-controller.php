<?php
/**
 * The new admin dashboard's first REST endpoint (docs/ARCHITECTURE.md).
 *
 * Deliberately does nothing but confirm identity: the app shell calls
 * this once on load, before any real screen exists, to prove the whole
 * chain works end to end — the `yeffoprint` page's own manage_options
 * capability gate, the nonce baked into that page's HTML, and this
 * endpoint's own YeffoPrint_Rest_Security::admin_write() check — so
 * every later admin-app REST controller can build on a chain that's
 * already been proven live, rather than debugging auth and a real
 * feature at the same time.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Ping_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/ping', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'ping' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	public function ping(): \WP_REST_Response {
		$user = wp_get_current_user();

		return rest_ensure_response( [
			'name' => $user->display_name,
		] );
	}
}
