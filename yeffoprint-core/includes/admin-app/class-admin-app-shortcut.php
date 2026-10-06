<?php
/**
 * A short, memorable URL straight into the admin app — direct request:
 * "let's go ahead and add an easy url to access it." `/design/` redirects
 * to the exact same `wp-admin/admin.php?page=yeffoprint` screen
 * class-admin-menu.php already registers; this is purely a URL alias,
 * not a new access surface — the redirect target is what actually
 * enforces access (add_menu_page()'s own 'manage_options' capability,
 * same as every other route into this app), so a logged-out visitor or
 * a logged-in non-admin hitting /design/ lands exactly where they
 * would have from typing the full wp-admin URL by hand: WordPress's own
 * login screen, or its own "you don't have permission" screen. Nothing
 * here duplicates or second-guesses that check.
 *
 * Registered with 'top' priority so it's matched before any of
 * WordPress's own more general rules (e.g. a real page or post that
 * happened to already use the slug "design") — see docs/ARCHITECTURE.md
 * for the one-time check that no such content already existed at this
 * slug before this shipped.
 *
 * Also serves the new admin app's phone install files (direct request:
 * "install it as a web app on my iPhone so I get notifications"):
 * `/design/manifest.webmanifest` and `/design/sw.js`. Neither can live
 * under /wp-admin/ (no rewrite rules reach it), so the service worker
 * sends `Service-Worker-Allowed: /wp-admin/` to be allowed to control
 * the admin pages from here. It only handles push alerts and has no
 * fetch handler, so it never changes how any wp-admin page loads.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_App_Shortcut {

	private const SLUG      = 'design';
	private const QUERY_VAR = 'yeffoprint_admin_app_shortcut';

	public function __construct() {
		add_action( 'init', [ $this, 'register_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 0 ); // Before redirect_canonical(), which would otherwise add a trailing slash to sw.js.
	}

	public function register_rewrite(): void {
		add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		add_rewrite_rule( '^' . self::SLUG . '/sw\\.js$', 'index.php?' . self::QUERY_VAR . '=sw', 'top' );
		add_rewrite_rule( '^' . self::SLUG . '/manifest\\.webmanifest$', 'index.php?' . self::QUERY_VAR . '=manifest', 'top' );
	}

	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function maybe_redirect(): void {
		$target = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $target ) {
			return;
		}

		if ( 'sw' === $target ) {
			self::serve_service_worker();
		} elseif ( 'manifest' === $target ) {
			self::serve_manifest();
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=yeffoprint' ) );
		}
		exit;
	}

	public static function service_worker_url(): string {
		return home_url( '/' . self::SLUG . '/sw.js' );
	}

	public static function manifest_url(): string {
		return home_url( '/' . self::SLUG . '/manifest.webmanifest' );
	}

	/** Path part of admin_url(), e.g. "/wp-admin/" — the scope the phone app and its service worker cover. */
	public static function admin_scope(): string {
		return (string) wp_parse_url( admin_url( '/' ), PHP_URL_PATH );
	}

	private static function serve_service_worker(): void {
		status_header( 200 );
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'Service-Worker-Allowed: ' . self::admin_scope() );
		readfile( YEFFOPRINT_CORE_PATH . 'assets/admin-app/next/sw.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	}

	private static function serve_manifest(): void {
		status_header( 200 );
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );

		$start = admin_url( 'admin.php?page=' . YeffoPrint_Admin_Push::APP_SLUG );
		$icons = YEFFOPRINT_CORE_URL . 'assets/admin-app/next/icons/';
		echo wp_json_encode( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
			'id'               => $start,
			'name'             => 'YeffoDesign Admin',
			'short_name'       => 'YeffoDesign',
			'description'      => 'Orders, production and store settings for YeffoDesign.',
			'start_url'        => $start,
			'scope'            => self::admin_scope(),
			'display'          => 'standalone',
			'background_color' => '#F6F5F2',
			'theme_color'      => '#FFFFFF',
			'icons'            => [
				[ 'src' => $icons . 'icon-192.png?v=2', 'sizes' => '192x192', 'type' => 'image/png' ],
				[ 'src' => $icons . 'icon-512.png?v=2', 'sizes' => '512x512', 'type' => 'image/png' ],
				[ 'src' => $icons . 'icon-maskable-512.png?v=2', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ],
			],
		], JSON_UNESCAPED_SLASHES );
	}
}
