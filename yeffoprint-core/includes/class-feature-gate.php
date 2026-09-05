<?php
/**
 * Admin-only gate for the "beyond peptide labels" expansion still being
 * finished — direct request: "I don't want to release all of these new
 * features until I'm sure they're ready... Customers should see a
 * coming soon page unless they're logged in as an admin." Covers the
 * Label Designer and its cross-link notice with Custom Design (both
 * FSE templates, gated via a dynamic block per feature — see
 * blocks/label-designer-app and blocks/label-designer-notice — since a
 * raw .html block template can't run a PHP conditional itself) and the
 * header nav link pointing at it.
 *
 * The 3 new prebuilt product-label Templates + the Product Type gallery
 * filter use a different, code-free mechanism instead (WordPress's own
 * `private` post status — see docs/ARCHITECTURE.md) and don't need
 * anything from this class.
 *
 * `manage_options` matches the capability the admin app itself already
 * gates on (class-admin-app-shortcut.php) — one convention for "is this
 * an admin" everywhere in the plugin, not a second one invented here.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Feature_Gate {

	/** Nav-link URLs to strip from parts/header.html for a non-admin viewer — path only, so this doesn't care whether the site is served over http/https or with/without a trailing slash mismatch. */
	private const GATED_NAV_PATHS = [ '/design-your-label/' ];

	public function __construct() {
		add_filter( 'render_block', [ $this, 'hide_gated_nav_links' ], 10, 2 );
	}

	public static function is_admin_viewer(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Strips one specific core/navigation-link block from rendering
	 * (returns '' instead of its markup) when it points at a gated path
	 * and the viewer isn't an admin — everything else in parts/header.html
	 * (shared by every page on the site) renders exactly as before.
	 */
	public function hide_gated_nav_links( string $block_content, array $block ): string {
		if ( 'core/navigation-link' !== ( $block['blockName'] ?? '' ) ) {
			return $block_content;
		}

		$url = (string) ( $block['attrs']['url'] ?? '' );
		if ( '' === $url || self::is_admin_viewer() ) {
			return $block_content;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( in_array( $path, self::GATED_NAV_PATHS, true ) ) {
			return '';
		}

		return $block_content;
	}
}
