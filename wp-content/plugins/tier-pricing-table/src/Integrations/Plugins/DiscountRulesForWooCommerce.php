<?php namespace TierPricingTable\Integrations\Plugins;

class DiscountRulesForWooCommerce extends PluginIntegrationAbstract {
	
	public function run() {
		
		add_filter( 'tiered_pricing_table/cart/need_price_recalculation', function ( $recalculate, $cartItem ) {
			if ( isset( $cartItem['wdr_free_product'] ) ) {
				return false;
			}
			
			return $recalculate;
		}, 10, 2 );
		
		add_filter( 'tiered_pricing_table/cart/need_price_recalculation/item', function ( $recalculate, $cartItem ) {
			if ( isset( $cartItem['wdr_free_product'] ) ) {
				return false;
			}
			
			return $recalculate;
		}, 10, 2 );
	}
	
	public function addToIntegrationsSettings( $integrations ) {
		return $integrations;
	}
	
	public function getIconURL(): string {
		return '';
	}
	
	public function getAuthorURL(): string {
		return 'https://wordpress.org/plugins/woo-discount-rules/';
	}
	
	public function getTitle(): string {
		return __( 'Discount Rules for WooCommerce', 'tier-pricing-table' );
	}
	
	public function getDescription(): string {
		return __( 'Prevent price recalculations for free promotional items added to the cart by Discount Rules for WooCommerce.', 'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'discount-rules-for-woocommerce';
	}
	
	public function getIntegrationCategory(): string {
		return 'other';
	}
}
