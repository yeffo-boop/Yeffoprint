<?php
/**
 * Quantity shortcut presets for the configurator (PROJECT_SPEC §10:
 * "Quantity: arbitrary, with shortcuts (25/50/100/250/500/1000/Custom)").
 *
 * Unlike class-pricing-placeholder.php, this isn't a stand-in for a
 * future admin-configurable record — the spec fixes these values
 * directly. It still lives here rather than in the theme/JS so the
 * REST response (class-template-schema-controller.php) stays the
 * single source of truth the configurator reads from, instead of the
 * theme hard-coding the list a second time.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'yeffoprint_core_quantity_presets' ) ) {
	/** @return int[] */
	function yeffoprint_core_quantity_presets(): array {
		return [ 25, 50, 100, 250, 500, 1000 ];
	}
}
