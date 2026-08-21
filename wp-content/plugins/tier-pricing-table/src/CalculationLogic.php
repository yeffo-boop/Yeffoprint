<?php namespace TierPricingTable;

use TierPricingTable\Settings\Settings;

class CalculationLogic {
	
	/**
	 * If product is on sale, calculate the discount based on the sale or regular price
	 *
	 * @return bool
	 */
	public static function calculateDiscountBasedOnRegularPrice(): bool {
		return get_option( Settings::SETTINGS_PREFIX . 'calculate_discount_based_on_regular_price', 'no' ) === 'yes';
	}
	
	/**
	 * Consider all variations from the same product as the same product, when tiered pricing calculates the price for a variation
	 *
	 * @return bool
	 */
	public static function considerProductVariationAsOneProduct(): bool {
		return get_option( Settings::SETTINGS_PREFIX . 'summarize_variations', 'no' ) === 'yes';
	}
	
	/**
	 * Round the final price, when tiered pricing calculates a percentage discount
	 *
	 * @return bool
	 */
	public static function roundPrice(): bool {
		return get_option( Settings::SETTINGS_PREFIX . 'round_price', 'yes' ) === 'yes';
	}
	
	/**
	 * Do global pricing rules have a higher priority than product level rules.
	 *
	 * @return bool
	 */
	public static function globalRulesOverrideProductLevelRules(): bool {
		return get_option( Settings::SETTINGS_PREFIX . 'override_prices_by_global_rules', 'no' ) === 'yes';
	}
	
	/**
	 * When cart item has a tiered price, show it as a discount with the original price crossed out.
	 *
	 * @return bool
	 */
	public static function showTieredPriceInCartAsDiscount(): bool {
		return get_option( Settings::SETTINGS_PREFIX . 'show_discount_in_cart', 'yes' ) === 'yes';
	}
	
	/**
	 * Mix and match minimum order quantity for variable products
	 *
	 * @return bool
	 */
	public static function isMixAndMatchMinimumEnabled(): bool {
		return get_option( Settings::SETTINGS_PREFIX . 'mix_and_match_minimum', 'no' ) === 'yes';
	}
}
