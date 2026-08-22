<?php
/**
 * Quantity shortcut presets for the configurator. Originally PROJECT_
 * SPEC §10's 25/50/100/250/500/1000 — changed to 10/20/30/50/100/250
 * per direct request: most customers actually order in increments of
 * 10, not 25, so the shortcuts were skipping past the quantities
 * people were most likely to want.
 *
 * Unlike YeffoPrint_Pricing_Rule (class-pricing-rule.php), this isn't
 * a stand-in for a future admin-configurable record — the list is
 * fixed directly here. It still lives here rather than in the
 * theme/JS so the REST response (class-template-schema-controller.php)
 * stays the single source of truth the configurator reads from,
 * instead of the theme hard-coding the list a second time.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'yeffoprint_core_quantity_presets' ) ) {
	/** @return int[] */
	function yeffoprint_core_quantity_presets(): array {
		return [ 10, 20, 30, 50, 100, 250 ];
	}
}
