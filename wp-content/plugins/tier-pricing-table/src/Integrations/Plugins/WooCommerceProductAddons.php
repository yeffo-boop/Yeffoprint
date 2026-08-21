<?php namespace TierPricingTable\Integrations\Plugins;

use TierPricingTable\PriceManager;
use WC_Product;

class WooCommerceProductAddons extends PluginIntegrationAbstract {
	
	/**
	 * Add extra addons costs to product price in cart.
	 *
	 * @param  float  $price
	 * @param  array  $cart_item
	 *
	 * @return float
	 */
	public function addAddonsPrice( $price, $cart_item ) {
		
		$extra_cost = 0;
		
		if ( isset( $cart_item['addons'] ) && false != $price ) {
			foreach ( $cart_item['addons'] as $addon ) {
				$price_type  = $addon['price_type'];
				$addon_price = $addon['price'];
				
				switch ( $price_type ) {
					
					case 'percentage_based':
						$extra_cost += (float) ( $price * ( $addon_price / 100 ) );
						break;
					case 'flat_fee':
						$extra_cost += (float) ( $addon_price / $cart_item['quantity'] );
						break;
					default:
						$extra_cost += (float) $addon_price;
						break;
				}
			}
			
			return $price + $extra_cost;
		}
		
		return $price;
	}
	
	/**
	 * Render compatibility script
	 */
	public function addCompatibilityScript() {
		?>
		<script>
			(function ($) {
				$('.tpt__tiered-pricing').on('tiered_price_update', function (event, data) {
					$('#product-addons-total').filter('[data-product-id=' + data.parentId + ']').data('price', data.price);
				});
			})(jQuery);
		</script>
		<?php
	}
	
	public function getTitle(): string {
		return  'Product Add-ons (by WooCommerce)';
	}
	
	public function getDescription(): string {
		return __( 'Calculate tiered pricing accurately when WooCommerce Product Add-ons are selected.', 'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'product-add-ons';
	}
	
	public function getAuthorURL(): string {
		return 'https://woocommerce.com/products/product-add-ons/';
	}
	
	public function getIconURL(): string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/woocommerce-develop.jpeg' );
	}
	
	public function run() {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		
		if ( is_plugin_active( 'woocommerce-product-addons/woocommerce-product-addons.php' ) ) {
			
			add_action( 'wp_footer', array( $this, 'addCompatibilityScript' ) );

			add_action( 'tiered_pricing_table/cart/product_cart_price', array( $this, 'addAddonsPrice' ), 20, 2 );
			add_action( 'tiered_pricing_table/cart/product_cart_price/item', array( $this, 'addAddonsPrice' ), 20, 2 );
			
			// Handle the case when product addons request product price via AJAX
			add_filter( 'woocommerce_product_addons_ajax_get_product_price_excluding_tax',
				function ( $price, $qty, WC_Product $product ) {
					
					$pricingRule = PriceManager::getPricingRule( $product->get_id() );
					$newPrice    = $pricingRule->getTierPrice( $qty, false );
					
					if ( $newPrice ) {
						return $newPrice * $qty;
					}
					
					return $price;
				}, 10, 3 );
			
			add_filter( 'woocommerce_product_addons_ajax_get_product_price_including_tax',
				function ( $price, $qty, WC_Product $product ) {
					
					$pricingRule = PriceManager::getPricingRule( $product->get_id() );
					$newPrice    = $pricingRule->getTierPrice( $qty );
					
					if ( $newPrice ) {
						return $newPrice * $qty;
					}
					
					return $price;
				}, 10, 3 );
		}
	}
	
	public function getIntegrationCategory(): string {
		return 'product_addons';
	}
}
