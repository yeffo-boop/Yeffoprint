<?php namespace TierPricingTable\Addons\ProductCatalogLoop;

use TierPricingTable\TierPricingTablePlugin;

class QuantityFieldHandler {
	
	public function __construct() {
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'renderQuantityField' ), 9 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueQuantityHandlerScript' ) );
	}
	
	public function renderQuantityField() {
		$product = wc_get_product( get_the_ID() );
		
		if ( $product && ( TierPricingTablePlugin::isSimpleProductSupported( $product ) ) && ! $product->is_sold_individually() && $product->is_purchasable() && $product->is_in_stock() ) {
			woocommerce_quantity_input( array(
				'min_value' => 1,
				'max_value' => $product->backorders_allowed() ? '' : $product->get_stock_quantity(),
			) );
		}
	}
	
	public function enqueueQuantityHandlerScript() {
		
		$handle = 'tpt-catalog-quantity-input-handler';
		
		wp_register_script( $handle, '', array( 'jquery' ), TierPricingTablePlugin::VERSION, true );
		
		wp_enqueue_script( $handle );
		
		$inlineJs = '
			jQuery(".type-product").on("click", ".quantity input", function() {
				return false;
			});

			jQuery(".type-product").on("change input", ".quantity .qty", function() {
				var add_to_cart_button = jQuery(this).closest(".product").find(".add_to_cart_button");

				// For AJAX add-to-cart actions
				add_to_cart_button.attr("data-quantity", jQuery(this).val());

				// For non-AJAX add-to-cart actions
				add_to_cart_button.attr("href", "?add-to-cart=" + add_to_cart_button.attr("data-product_id") + "&quantity=" + jQuery(this).val());
			});

			// Trigger on Enter press
			jQuery(".woocommerce .products").on("keypress", ".quantity .qty", function(e) {
				if ((e.which || e.keyCode) === 13) {
					jQuery(this).parents(".product").find(".add_to_cart_button").trigger("click");
				}
			});
		';
		
		wp_add_inline_script( $handle, $inlineJs );
	}
}
