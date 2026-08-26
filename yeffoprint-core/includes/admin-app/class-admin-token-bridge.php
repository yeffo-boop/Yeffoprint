<?php
/**
 * Reads the theme's real design tokens (yeffoprint/theme.json) and
 * turns them into the same `--wp--preset--…`/`--wp--custom--…` CSS
 * custom properties the storefront already computes from that same
 * file — inline-enqueued only on the new admin dashboard screen.
 *
 * wp-admin never loads a block theme's generated global-styles
 * stylesheet, so without this the admin app would need its own copy of
 * every color/spacing/radius/shadow value — exactly the "hardcoded
 * literal mirror" the existing YeffoPrint_Admin_Shell reskin uses
 * (documented, deliberate, but drift-prone: it can silently go stale
 * the moment theme.json changes and nobody remembers to update the
 * mirror).
 *
 * Delegates entirely to `wp_get_global_stylesheet( [ 'variables' ] )`
 * — the exact core function WordPress itself uses to generate the
 * `<style id="global-styles-inline-css">` block every block-theme page
 * on the front end already gets — rather than hand-parsing
 * `wp_get_global_settings()`'s merged-settings tree ourselves. An
 * earlier version of this class did that hand-parsing and shipped with
 * a bug: it silently produced an empty string (root-caused as a
 * mismatch between the assumed and actual shape of the merged settings
 * array), so not one `--wp--…` custom property was ever actually
 * defined — every token-dependent style across the whole admin app
 * (colors, fonts, spacing, the button/badge/drawer styles borrowed from
 * global.css) silently fell back to the browser's bare defaults, while
 * this app's own *static* CSS (fixed pixel widths, layout, border-
 * radius) kept working fine, since none of that depends on a custom
 * property resolving. Direct report: "looks like no styling at all."
 * Using core's own generator instead of re-deriving the same output by
 * hand removes that entire class of bug.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Token_Bridge {

	/** @return string CSS custom-property declarations (WordPress's own `body{ --wp--…: …; }` block), or '' if unavailable. */
	public static function inline_css(): string {
		if ( ! function_exists( 'wp_get_global_stylesheet' ) ) {
			return '';
		}

		return (string) wp_get_global_stylesheet( [ 'variables' ] );
	}
}
