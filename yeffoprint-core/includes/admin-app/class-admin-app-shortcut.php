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
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_App_Shortcut {

	private const SLUG      = 'design';
	private const QUERY_VAR = 'yeffoprint_admin_app_shortcut';

	public function __construct() {
		add_action( 'init', [ $this, 'register_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_redirect' ] );
	}

	public function register_rewrite(): void {
		add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function maybe_redirect(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=yeffoprint' ) );
		exit;
	}
}
