<?php namespace TierPricingTable\Addons\PricingSummary;

use TierPricingTable\Settings\CustomOptions\TPTDisplayType;
use TierPricingTable\Settings\CustomOptions\TPTSwitchOption;
use TierPricingTable\Settings\Sections\SubsectionAbstract;
use TierPricingTable\Settings\Settings;

class SummarySubsection extends SubsectionAbstract {
	
	public function getTitle(): string {
		return __( 'Pricing Summary', 'tier-pricing-table' );
	}
	
	public function getDescription(): string {
		return __( 'Show a summary block with total cost and unit price details.', 'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'summary';
	}
	
	public function getSettings(): array {
		return array(
			array(
				'title'    => __( 'Show pricing summary block', 'tier-pricing-table' ),
				'id'       => Settings::SETTINGS_PREFIX . 'display_summary',
				'type'     => TPTSwitchOption::FIELD_TYPE,
				'default'  => 'yes',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Show for standard products (non-tiered)', 'tier-pricing-table' ),
				'id'       => Settings::SETTINGS_PREFIX . 'display_summary_non_tiered',
				'type'     => TPTSwitchOption::FIELD_TYPE,
				'default'  => 'no',
				'desc'     => __( 'Display the pricing summary block for regular WooCommerce products even if they don\'t have tiered pricing rules.', 'tier-pricing-table' ),
			),
			array(
				'title'    => __( 'Summary block title', 'tier-pricing-table' ),
				'id'       => Settings::SETTINGS_PREFIX . 'summary_title',
				'type'     => 'text',
				'desc'     => __( 'The name is displaying above the summary block.', 'tier-pricing-table' ),
				'desc_tip' => true,
				'default'  => '',
			),
			array(
				'title'    => __( 'Block position', 'tier-pricing-table' ),
				'id'       => Settings::SETTINGS_PREFIX . 'summary_position_hook',
				'type'     => 'select',
				'options'  => array(
					'woocommerce_before_add_to_cart_button'     => __( 'Above buy button', 'tier-pricing-table' ),
					'woocommerce_after_add_to_cart_button'      => __( 'Below buy button', 'tier-pricing-table' ),
					'woocommerce_before_add_to_cart_form'       => __( 'Above add to cart form', 'tier-pricing-table' ),
					'woocommerce_after_add_to_cart_form'        => __( 'Below add to cart form', 'tier-pricing-table' ),
					'woocommerce_single_product_summary'        => __( 'Above product title', 'tier-pricing-table' ),
					'woocommerce_before_single_product_summary' => __( 'Before product summary', 'tier-pricing-table' ),
					'woocommerce_after_single_product_summary'  => __( 'After product summary', 'tier-pricing-table' ),
				),
				'default'  => 'woocommerce_after_add_to_cart_button',
				'desc'     => __( 'Where to display the summary block.', 'tier-pricing-table' ),
				'desc_tip' => true,
			),
			array(
				'title'   => __( 'Summary layout', 'tier-pricing-table' ),
				'id'      => Settings::SETTINGS_PREFIX . 'summary_type',
				'type'    => TPTDisplayType::FIELD_TYPE,
				'options' => array(
					'detailed' => __( 'Detailed', 'tier-pricing-table' ),
					'table'    => __( 'Compact', 'tier-pricing-table' ),
					'inline'   => __( 'Inline labels', 'tier-pricing-table' ),
				),
				'default' => 'table',
			),
			array(
				'title'    => __( '"Total" label', 'tier-pricing-table' ),
				'id'       => Settings::SETTINGS_PREFIX . 'summary_total_label',
				'type'     => 'text',
				'default'  => __( 'Total:', 'tier-pricing-table' ),
				'desc_tip' => true,
			),
			array(
				'title'    => __( '"Each" label', 'tier-pricing-table' ),
				'id'       => Settings::SETTINGS_PREFIX . 'summary_each_label',
				'type'     => 'text',
				'default'  => __( 'Each: ', 'tier-pricing-table' ),
				'desc_tip' => true,
			),
		);
	}
}
