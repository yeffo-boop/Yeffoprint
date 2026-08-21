<?php namespace TierPricingTable\Integrations\Plugins;

use SW_WAPF\Includes\Classes\Fields;

class WombatProductAddons extends PluginIntegrationAbstract {
	
	public function addAddonsPriceToItem( $price, $cart_item ) {
		
		if ( ! $price ) {
			return $price;
		}
		
		// Premium version
		if ( ! empty( $cart_item['wapf_item_price']['options_total'] ) ) {
			$price += $cart_item['wapf_item_price']['options_total'];
		}
		
		// Free version
		if ( class_exists( 'SW_WAPF\Includes\Classes\Fields' ) && ! empty( $cart_item['wapf'] ) ) {
			$optionsTotal = 0;
			
			foreach ( $cart_item['wapf'] as $field ) {
				if ( ! empty( $field['price'] ) ) {
					foreach ( $field['price'] as $_price ) {
						
						if ( 0 === $_price['value'] ) {
							continue;
						}
						
						$optionsTotal = $optionsTotal + Fields::do_pricing( $_price['value'], $cart_item['quantity'] );
					}
				}
			}
			
			$price += $optionsTotal;
		}
		
		return $price;
	}
	
	public function getTitle(): string {
		return 'Product Fields (Product Addons) by StudioWombat';
	}
	
	public function getDescription(): string {
		return __( 'Calculate tiered pricing accurately when using StudioWombat Advanced Product Fields.', 'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'wombat_product_addons';
	}
	
	public function getAuthorURL(): string {
		return 'https://wordpress.org/plugins/advanced-product-fields-for-woocommerce/';
	}
	
	public function getIconURL(): string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/wombat-addons-icon.png' );
	}
	
	/**
	 * Render compatibility script
	 */
	public function addCompatibilityScript() {
		
		if ( ! is_product() ) {
			return;
		}
		
		?>
		<script>
			// Tiered Pricing WOOCS Compatibility
			(function ($) {
				const updateVariationPrice = function (price, variationId) {

					if (!variationId) {
						return;
					}

					if ($('[data-product_variations]').length === 0) {
						return;
					}

					let variationData = $('[data-product_variations]').data('product_variations');

					if (!variationData) {
						return;
					}

					variationData = variationData.map(variation => {
						if (variation.variation_id === parseInt(variationId)) {
							variation.display_price = price;
						}

						return variation;
					});

					$('[data-product_variations]').data('product_variations', variationData);
				};

				$(document).on('tiered_price_update', function (event, data) {

					if (typeof WAPF !== 'undefined') {
						// the variable is defined
						WAPF.Filter.add('wapf/pricing/base', function (_price, _wrapper) {
							return data.price;
						});

						// Trigger update totals.
						$('.wapf').find('input, select, textarea').trigger('change');
					}

					// Free version
					if ($('.wapf-product-totals').length) {
						$('.wapf-product-totals').data('product-price', data.price);

						const productId = parseInt(data.__instance.$getPricingElement().data('product-id'));

						updateVariationPrice(data.price, productId);
					}
				});
			})(jQuery);
		</script>
		<?php
	}
	
	public function run() {
		
		add_action( 'wp_footer', array( $this, 'addCompatibilityScript' ) );
		
		add_filter( 'tiered_pricing_table/cart/product_cart_price', array( $this, 'addAddonsPriceToItem' ), 20, 2 );
		
		add_filter( 'tiered_pricing_table/cart/product_cart_price/item', array(
			$this,
			'addAddonsPriceToItem',
		), 20, 2 );
		
		add_filter( 'tiered_pricing_table/cart/product_cart_old_price', array( $this, 'addAddonsPriceToItem' ), 20, 2 );
	}
	
	public function getIntegrationCategory(): string {
		return 'product_addons';
	}
}
