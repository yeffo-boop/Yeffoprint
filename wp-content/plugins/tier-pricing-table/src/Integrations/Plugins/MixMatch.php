<?php namespace TierPricingTable\Integrations\Plugins;

class MixMatch extends PluginIntegrationAbstract {
	
	public function run() {
		add_filter( 'tiered_pricing_table/cart/need_price_recalculation', function ( $bool, $cart_item ) {
			
			if ( isset( $cart_item['mnm_container'] ) ) {
				return false;
			}
			
			return $bool;
			
		}, 10, 2 );
	}
	
	public function getIconURL(): string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/mix-match-icon.png' );
	}
	
	public function getAuthorURL(): string {
		return 'https://woocommerce.com/products/woocommerce-mix-and-match-products/';
	}
	
	public function getTitle(): string {
		return 'Mix&Match for WooCommerce';
	}
	
	public function getDescription(): string {
		return __( 'Apply tiered pricing rules correctly to Mix and Match products.', 'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'mix-match-for-woocommerce';
	}
	
	public function getIntegrationCategory(): string {
		return 'custom_product_types';
	}
}
