<?php
/**
 * Reads the theme's real design tokens (yeffoprint/theme.json) and
 * turns them into the same `--wp--preset--…`/`--wp--custom--…` CSS
 * custom properties the storefront already computes from that same
 * file — scoped under `body.yeffoprint-app` and inline-enqueued only
 * on the new admin dashboard screen.
 *
 * wp-admin never loads a block theme's generated global-styles
 * stylesheet, so without this the admin app would need its own copy of
 * every color/spacing/radius/shadow value — exactly the "hardcoded
 * literal mirror" the existing YeffoPrint_Admin_Shell reskin uses
 * (documented, deliberate, but drift-prone: it can silently go stale
 * the moment theme.json changes and nobody remembers to update the
 * mirror). Reading `wp_get_global_settings()` directly instead makes
 * that drift impossible — the admin app and the storefront always
 * agree, because they're reading the same file.
 *
 * `wp_get_global_settings()` returns the fully-merged settings tree
 * (already collapsed across default/theme/user origins — theme.json's
 * own `defaultPalette: false` means only this theme's palette survives
 * that merge), so every array below is flat: a plain list of
 * {slug, ...} entries for color/typography/spacing, and a plain nested
 * associative array for `custom` that mirrors theme.json's own
 * `settings.custom` block exactly (e.g. `custom.radius.control`,
 * `custom.gradient-brand-deep`) — flattened here into
 * `--wp--custom--radius--control`, `--wp--custom--gradient-brand-deep`,
 * etc., the same naming scheme WordPress's own global-styles engine
 * uses for the storefront.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Token_Bridge {

	/** @return string A `body.yeffoprint-app { --wp--…: …; }` block, or '' if no theme.json settings were found. */
	public static function inline_css(): string {
		$settings = wp_get_global_settings();
		$lines    = [];

		foreach ( (array) ( $settings['color']['palette'] ?? [] ) as $color ) {
			if ( isset( $color['slug'], $color['color'] ) ) {
				$lines[] = sprintf( '--wp--preset--color--%s: %s;', $color['slug'], $color['color'] );
			}
		}

		foreach ( (array) ( $settings['typography']['fontFamilies'] ?? [] ) as $font ) {
			if ( isset( $font['slug'], $font['fontFamily'] ) ) {
				$lines[] = sprintf( '--wp--preset--font-family--%s: %s;', $font['slug'], $font['fontFamily'] );
			}
		}

		foreach ( (array) ( $settings['spacing']['spacingSizes'] ?? [] ) as $space ) {
			if ( isset( $space['slug'], $space['size'] ) ) {
				$lines[] = sprintf( '--wp--preset--spacing--%s: %s;', $space['slug'], $space['size'] );
			}
		}

		self::flatten( (array) ( $settings['custom'] ?? [] ), 'wp--custom', $lines );

		return $lines ? "body.yeffoprint-app {\n\t" . implode( "\n\t", $lines ) . "\n}\n" : '';
	}

	/** @param string[] $lines */
	private static function flatten( array $data, string $prefix, array &$lines ): void {
		foreach ( $data as $key => $value ) {
			$name = $prefix . '--' . $key;
			if ( is_array( $value ) ) {
				self::flatten( $value, $name, $lines );
			} else {
				$lines[] = sprintf( '--%s: %s;', $name, $value );
			}
		}
	}
}
