<?php namespace TierPricingTable\Integrations\Plugins;

use TierPricingTable\PriceManager;
use WC_Product;

class AddifyRequestAQuote extends PluginIntegrationAbstract {
	
	public function run() {
		add_filter( 'addify_quote_item_product', function ( WC_Product $product, $quoteItem ) {
			$quantity = $quoteItem['quantity'] ? intval( $quoteItem['quantity'] ) : 1;
			
			$pricingRule = PriceManager::getPricingRule( $product->get_id() );
			
			$price = $pricingRule->getTierPrice( $quantity, false, 'cart' );
			
			if ( $price ) {
				$product->set_price( $price );
			}
			
			return $product;
		}, 10, 2 );
	}
	
	public function getIconURL(): string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/addify-raq-icon.png' );
	}
	
	public function getAuthorURL(): string {
		return 'https://woocommerce.com/products/request-a-quote-plugin-for-woocommerce/';
	}
	
	public function getTitle(): string {
		return 'Request a Quote for WooCommerce by Addify';
	}
	
	public function getDescription(): string {
		return __( 'Apply tiered pricing discounts when users request a quote through the Addify Request a Quote form.', 'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'addify-request-a-quote';
	}
	
	public function getIntegrationCategory(): string {
		return 'other';
	}
}
