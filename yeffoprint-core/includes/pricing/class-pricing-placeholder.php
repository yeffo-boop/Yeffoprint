<?php
/**
 * Temporary stand-in for the Phase 6 pricing engine.
 *
 * The Shop Labels gallery cards (PROJECT_SPEC §9) need a "From
 * $0.35/label" starting price before the real PricingRule-driven
 * calculation exists. Rather than hard-coding "$0.35" into the theme
 * template — which PROJECT_SPEC §12 and the "Do Not" list explicitly
 * forbid — the constant lives here in the plugin and is read through
 * a template tag. This file is deleted once Phase 6 lands, at which
 * point yeffoprint_core_starting_price_label() reads from the real
 * PricingRule record instead of this constant.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'yeffoprint_core_base_unit_price' ) ) {
	function yeffoprint_core_base_unit_price(): float {
		// Matches PROJECT_SPEC §12 base price. Phase 6 replaces this with
		// PricingRule::base_unit_price.
		return 0.35;
	}
}

if ( ! function_exists( 'yeffoprint_core_starting_price_label' ) ) {
	function yeffoprint_core_starting_price_label(): string {
		return sprintf(
			/* translators: %s: formatted starting unit price. */
			__( 'From %s/label', 'yeffoprint-core' ),
			'$' . number_format_i18n( yeffoprint_core_base_unit_price(), 2 )
		);
	}
}
