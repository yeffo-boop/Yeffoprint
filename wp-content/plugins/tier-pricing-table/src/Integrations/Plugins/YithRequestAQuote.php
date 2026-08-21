<?php namespace TierPricingTable\Integrations\Plugins;

use TierPricingTable\CalculationLogic;
use TierPricingTable\PriceManager;

class YithRequestAQuote extends PluginIntegrationAbstract {
	
	public function run() {
		
		add_action( 'ywraq_quote_adjust_price', function ( $raq, \WC_Product $product ) {
			
			$quantity = $this->getProductCount( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id(),
				$raq['quantity'] ? $raq['quantity'] : 1 );
			
			$pricingRule = PriceManager::getPricingRule( $product->get_id() );
			
			$newPrice = $pricingRule->getTierPrice( $quantity, false, 'cart', false );
			
			if ( $newPrice ) {
				$product->set_price( $newPrice );
			}
		}, 10, 2 );
		
		add_filter( 'woocommerce_cart_product_subtotal', function ( $product_subtotal, $product, $quantity ) {
			
			if ( ! class_exists( 'YITH_Request_Quote' ) ) {
				return $product_subtotal;
			}
			
			$pricingRule = PriceManager::getPricingRule( $product->get_id() );
			
			$totalQuantity = $this->getProductCount( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id(),
				$quantity );
			
			$newPrice = $pricingRule->getTierPrice( $totalQuantity, false, 'cart', false );
			
			if ( $newPrice ) {
				$product_subtotal = wc_price( $newPrice * $quantity );
			}
			
			return $product_subtotal;
			
		}, - 99999, 6 );
	}
	
	protected function getProductCount( $productId, $default ) {
		
		if ( ! function_exists( 'YITH_Request_Quote' ) ) {
			return $default;
		}
		
		if ( CalculationLogic::considerProductVariationAsOneProduct() ) {
			$count = 0;
			
			foreach ( YITH_Request_Quote()->get_raq_return() as $raq ) {
				
				if ( $raq['product_id'] == $productId ) {
					$count += $raq['quantity'];
				}
			}
			
			if ( $count > 0 ) {
				return $count;
			} else {
				return $default;
			}
		}
		
		return $default;
	}
	
	public function getIconURL(): string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/yith-raq-icon.jpeg' );
	}
	
	public function getAuthorURL(): string {
		return 'https://wordpress.org/plugins/yith-woocommerce-request-a-quote/';
	}
	
	public function getTitle(): string {
		return 'YITH Request a Quote';
	}
	
	public function getDescription(): string {
		return __( 'Apply tiered pricing discounts when users request a quote through YITH Request a Quote.', 'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'yith-request-a-quote';
	}
	
	public function getIntegrationCategory(): string {
		return 'other';
	}
}
