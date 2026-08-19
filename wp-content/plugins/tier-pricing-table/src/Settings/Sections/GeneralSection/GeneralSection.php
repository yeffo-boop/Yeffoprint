<?php namespace TierPricingTable\Settings\Sections\GeneralSection;

use TierPricingTable\Core\ServiceContainer;
use TierPricingTable\Settings\Sections\GeneralSection\Subsections\HiddenOptionsSubsection;
use TierPricingTable\Settings\Sections\GeneralSection\Subsections\LayoutSubsection;
use TierPricingTable\Settings\Sections\GeneralSection\Subsections\ProductPagePriceSubsection;
use TierPricingTable\Settings\Sections\SectionAbstract;
use TierPricingTable\Settings\Settings;

class GeneralSection extends SectionAbstract {
	
	public function getSettings() {
		$settings = array();
		
		foreach ( $this->getSubsections() as $subsection ) {
			$settings = array_merge( $settings, ( new $subsection() )->getWrappedSettings() );
		}
		
		return apply_filters( 'tiered_pricing_table/settings/general_settings', $settings );
	}
	
	protected function getSubsections() {
		return apply_filters( 'tiered_pricing_table/settings/general_subsections', array(
			HiddenOptionsSubsection::class,
			LayoutSubsection::class,
			ProductPagePriceSubsection::class,
		) );
	}
	
	public static function deleteOptions() {
		delete_option( Settings::SETTINGS_PREFIX . 'product_page_subsection' );
		delete_option( Settings::SETTINGS_PREFIX . 'display' );
		delete_option( Settings::SETTINGS_PREFIX . 'display_type' );
		delete_option( Settings::SETTINGS_PREFIX . 'quantity_type' );
		delete_option( Settings::SETTINGS_PREFIX . 'tooltip_color' );
		delete_option( Settings::SETTINGS_PREFIX . 'tooltip_size' );
		delete_option( Settings::SETTINGS_PREFIX . 'tooltip_border' );
		delete_option( Settings::SETTINGS_PREFIX . 'table_title' );
		delete_option( Settings::SETTINGS_PREFIX . 'position_hook' );
		delete_option( Settings::SETTINGS_PREFIX . 'selected_quantity_color' );
		delete_option( Settings::SETTINGS_PREFIX . 'head_quantity_text' );
		delete_option( Settings::SETTINGS_PREFIX . 'head_price_text' );
		delete_option( Settings::SETTINGS_PREFIX . 'show_discount_column' );
		delete_option( Settings::SETTINGS_PREFIX . 'head_discount_text' );
		delete_option( Settings::SETTINGS_PREFIX . 'table_quantity_measurement' );
		delete_option( Settings::SETTINGS_PREFIX . 'blocks_quantity_measurement' );
		delete_option( Settings::SETTINGS_PREFIX . 'product_page_price_format' );
		delete_option( Settings::SETTINGS_PREFIX . 'clickable_table_rows' );
		delete_option( Settings::SETTINGS_PREFIX . 'product_page_subsection' );
		delete_option( Settings::SETTINGS_PREFIX . 'cart_checkout_subsection' );
		delete_option( Settings::SETTINGS_PREFIX . 'summarize_variations' );
		delete_option( Settings::SETTINGS_PREFIX . 'show_discount_in_cart' );
		delete_option( Settings::SETTINGS_PREFIX . 'cart_checkout_subsection' );
		delete_option( Settings::SETTINGS_PREFIX . 'catalog_prices_subsection' );
		delete_option( Settings::SETTINGS_PREFIX . 'tiered_price_at_catalog' );
		delete_option( Settings::SETTINGS_PREFIX . 'tiered_price_at_catalog_for_variable' );
		delete_option( Settings::SETTINGS_PREFIX . 'tiered_price_at_catalog_cache_for_variable' );
		delete_option( Settings::SETTINGS_PREFIX . 'tiered_price_at_product_page' );
		delete_option( Settings::SETTINGS_PREFIX . 'tiered_price_at_catalog_type' );
		delete_option( Settings::SETTINGS_PREFIX . 'lowest_prefix' );
		delete_option( Settings::SETTINGS_PREFIX . 'premium_options' );
		delete_option( Settings::SETTINGS_PREFIX . 'summary_section' );
		delete_option( Settings::SETTINGS_PREFIX . 'display_summary' );
		delete_option( Settings::SETTINGS_PREFIX . 'summary_title' );
		delete_option( Settings::SETTINGS_PREFIX . 'summary_type' );
		delete_option( Settings::SETTINGS_PREFIX . 'summary_total_label' );
		delete_option( Settings::SETTINGS_PREFIX . 'summary_each_label' );
		delete_option( Settings::SETTINGS_PREFIX . 'summary_position_hook' );
		
		delete_option( Settings::SETTINGS_PREFIX . 'options_option_text' );
		delete_option( Settings::SETTINGS_PREFIX . 'options_show_default_option' );
		delete_option( Settings::SETTINGS_PREFIX . 'options_default_option_text' );
		delete_option( Settings::SETTINGS_PREFIX . 'options_show_original_product_price' );
		delete_option( Settings::SETTINGS_PREFIX . 'options_show_total' );
		
		delete_option( Settings::SETTINGS_PREFIX . 'show_total_price' );
		delete_option( Settings::SETTINGS_PREFIX . 'update_price_on_product_page' );
		delete_option( Settings::SETTINGS_PREFIX . 'show_tiered_price_as_discount' );
	}
	
	public function getSlug(): string {
		return 'general';
	}
	
	public function getName(): string {
		return __( 'General', 'tier-pricing-table' );
	}
	
	public static function getOptionText() {
		$default = __( '<strong>Buy {tp_quantity} pieces and save {tp_rounded_discount}%</strong>',
			'tier-pricing-table' );
		
		return ServiceContainer::getInstance()->getSettings()->get( 'options_option_text', $default );
	}
	
	public static function getPlainTextTemplate() {
		$default = __( '<strong>Buy {tp_quantity} pieces for {tp_price} each and save {tp_rounded_discount}%</strong>',
			'tier-pricing-table' );
		
		return ServiceContainer::getInstance()->getSettings()->get( 'plain_text_template', $default );
	}
	
	public static function getPlainTextFirstTierTemplate() {
		$default = __( '<strong>Buy {tp_quantity} pieces for {tp_price}</strong>', 'tier-pricing-table' );
		
		return ServiceContainer::getInstance()->getSettings()->get( 'plain_text_first_tier_template', $default );
	}
	
	public static function isPlainTextFirstTierEnabled(): bool {
		return ServiceContainer::getInstance()->getSettings()->get( 'plain_text_show_first_tier', 'yes' ) === 'yes';
	}
	
	public static function getDefaultOptionText() {
		$default = __( '<strong>Buy {tp_quantity} pieces</strong>', 'tier-pricing-table' );
		
		return ServiceContainer::getInstance()->getSettings()->get( 'options_default_option_text', $default );
	}
	
	public static function isDefaultOptionEnabled(): bool {
		return ServiceContainer::getInstance()->getSettings()->get( 'options_show_default_option', 'yes' ) === 'yes';
	}
	
	public static function isShowOriginalProductPrice(): bool {
		return ServiceContainer::getInstance()->getSettings()->get( 'options_show_original_product_price',
				'yes' ) === 'yes';
	}
	
	public static function isShowOptionTotal(): bool {
		return ServiceContainer::getInstance()->getSettings()->get( 'options_show_total', 'yes' ) === 'yes';
	}
	
	public static function getPricingBlocksStyle(): string {
		return ServiceContainer::getInstance()->getSettings()->get( 'pricing_blocks_style', 'default' );
	}
	
	public static function getPricingOptionsStyle(): string {
		return ServiceContainer::getInstance()->getSettings()->get( 'pricing_options_style', 'default' );
	}
	
	public static function getPricingTableStyle(): string {
		return ServiceContainer::getInstance()->getSettings()->get( 'pricing_table_style', 'default' );
	}
}
