<?php namespace TierPricingTable\Settings\Sections\CalculationLogic;

use TierPricingTable\Settings\CustomOptions\TPTSwitchOption;
use TierPricingTable\Settings\Sections\SectionAbstract;
use TierPricingTable\Settings\Settings;

class CalculationLogic extends SectionAbstract {

	public function getSettings() {
		$settings = apply_filters( 'tiered_pricing_table/settings/calculation_logic', $this->getMainSettings() );

		return array_merge( $settings, $this->getGlobalPricingRulesOptions() );
	}

	public function getMainSettings(): array {
		return array(
				array(
						'title' => __( 'Variable products logic', 'tier-pricing-table' ),
						'desc'  => __( 'Control how tiered pricing handles variable products.', 'tier-pricing-table' ),
						'id'    => Settings::SETTINGS_PREFIX . 'variable_products_logic',
						'type'  => 'title',
				),
				array(
						'title'                => __( 'Combine variations for quantity calculation',
								'tier-pricing-table' ),
						'id'                   => Settings::SETTINGS_PREFIX . 'summarize_variations',
						'type'                 => TPTSwitchOption::FIELD_TYPE,
						'default'              => 'no',
						'extended_description' => __( 'Treat all variations of a variable product as the same item when calculating the total cart quantity for tiered pricing rules.',
								'tier-pricing-table' ),
						'desc_tip'             => true,
				),
				array(
						'title'                => __( 'Combine variations for minimum quantity', 'tier-pricing-table' ),
						'id'                   => Settings::SETTINGS_PREFIX . 'mix_and_match_minimum',
						'type'                 => TPTSwitchOption::FIELD_TYPE,
						'default'              => 'no',
						'extended_description' => __( 'Determine whether the minimum quantity requirement applies to each variation separately, or to the combined total of all variations in the cart.', 'tier-pricing-table' ),
						'desc_tip'             => true,
				),
				array(
						'type' => 'sectionend',
						'id'   => Settings::SETTINGS_PREFIX . 'variable_products_logic',
				),
				array(
						'title' => __( 'General calculations', 'tier-pricing-table' ),
						'desc'  => __( 'Control general calculation behavior for tiered pricing.', 'tier-pricing-table' ),
						'id'    => Settings::SETTINGS_PREFIX . 'general_calculations',
						'type'  => 'title',
				),
				array(
						'title'                => __( 'Calculate percentage discounts from regular price',
								'tier-pricing-table' ),
						'id'                   => Settings::SETTINGS_PREFIX . 'calculate_discount_based_on_regular_price',
						'type'                 => TPTSwitchOption::FIELD_TYPE,
						'extended_description' => $this->getCalculateDiscountDescription(),
						'default'              => 'no',
				),
				array(
						'title'                => __( 'Round calculated prices', 'tier-pricing-table' ),
						'id'                   => Settings::SETTINGS_PREFIX . 'round_price',
						'type'                 => TPTSwitchOption::FIELD_TYPE,
						'default'              => 'yes',
						'extended_description' => __( 'Round calculated percentage discounts to prevent minor display discrepancies with standard WooCommerce pricing.',
								'tier-pricing-table' ),
						'desc_tip'             => true,
				),
				array(
						'type' => 'sectionend',
						'id'   => Settings::SETTINGS_PREFIX . 'general_calculations',
				),
		);
	}

	public function getCalculateDiscountDescription() {
		ob_start();
		?>
		<p>
			<?php
				esc_html_e( 'Calculate percentage discounts using the product\'s regular price, ignoring any active sale prices.',
						'tier-pricing-table' );
			?>
		</p>
		<p>
			<?php
				esc_html_e( 'Example: Regular price is $100.00, sale price is $90.00. Rule: 20% off.',
						'tier-pricing-table' );
			?>
			<br>
			<?php
				esc_html_e( 'Disabled (Default): Discount applies to the sale price ($90.00 - 20% = $72.00).',
						'tier-pricing-table' );
			?>
			<br>
			<?php
				esc_html_e( 'Enabled: Discount applies to the regular price ($100.00 - 20% = $80.00).',
						'tier-pricing-table' );
			?>
		</p>
		<?php

		return ob_get_clean();
	}

	public function getSlug(): string {
		return 'calculation_logic';
	}

	public function getName(): string {
		return __( 'Calculations', 'tier-pricing-table' );
	}

	protected function getGlobalPricingRulesOptions(): array {
		return array(
				array(
						'title' => __( 'Global pricing rules', 'tier-pricing-table' ),
						'desc'  => __( 'How global pricing rules behave.', 'tier-pricing-table' ),
						'type'  => 'title',
				),
				array(
						'title'                => __( 'Prioritize global pricing rules', 'tier-pricing-table' ),
						'id'                   => Settings::SETTINGS_PREFIX . 'override_prices_by_global_rules',
						'extended_description' => __( 'Apply global pricing rules before product-level rules. If disabled, individual product rules take precedence over global rules.',
								'tier-pricing-table' ),
						'type'                 => TPTSwitchOption::FIELD_TYPE,
						'default'              => 'no',
				),
				array(
						'type' => 'sectionend',
				),
		);
	}

}