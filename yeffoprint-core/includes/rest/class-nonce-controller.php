<?php
/**
 * A single endpoint to fetch a fresh `wp_rest` nonce for whichever
 * session is currently making the request.
 *
 * Every page that embeds a nonce (configurator, custom design form,
 * proof approval — see functions.php) bakes it into that page's HTML
 * once, at load time. If the visitor's session outlives that nonce —
 * open the tab, come back later, log in partway through, or (the
 * likelier case in practice) the page itself got served from a cache
 * that predates their current session — every subsequent write to a
 * nonce-checked endpoint (class-rest-security.php) fails WordPress's
 * own cookie/nonce check ("Cookie check failed") for a reason no
 * visitor would ever connect to caching.
 *
 * This lets the frontend recover instead of just failing: on a 403
 * from a nonce-checked request, fetch a fresh nonce from here (no
 * nonce of its own required — generating one only needs the request's
 * *current* auth cookie, which is exactly what's actually still
 * valid) and retry once. See configurator.js's submitAddToCart() for
 * the one call site using this so far.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Nonce_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/session/nonce', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_nonce' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function get_nonce(): \WP_REST_Response {
		$response = rest_ensure_response( [ 'nonce' => wp_create_nonce( 'wp_rest' ) ] );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
