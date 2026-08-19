<?php namespace TierPricingTable\Integrations\Plugins;

class WCPA extends PluginIntegrationAbstract {

	public function addAddonsPriceToItem( $price, $cart_item ) {

		if ( $price && ! empty( $cart_item['wcpa_options_price_start'] ) ) {
			$price += $cart_item['wcpa_options_price_start'];
		}

		return $price;
	}

	public function getTitle(): string {
		return 'WooCommerce Custom Product Addons (WCPA) by Acowebs';
	}

	public function getDescription(): string {
		return __( 'Calculate tiered pricing accurately when custom product add-ons are selected.', 'tier-pricing-table' );
	}

	public function getSlug(): string {
		return 'wcpa';
	}

	public function getAuthorURL(): string {
		return 'https://wordpress.org/plugins/woo-custom-product-addons/';
	}

	public function getIconURL(): string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/wcpa-icon.png' );
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
				$('.tpt__tiered-pricing').on('tiered_price_update', function (event, data) {
					$.each($('.wcpa_form_outer'), function (i, el) {
						var $el = $(el);
						var product = $el.data('product');

						if (product) {
							product.wc_product_price = data.price;
							$(el).data('product', product);
						}
					});
				});
			})(jQuery);
		</script>
		<?php
	}

	public function run() {

		add_action( 'wp_head', array( $this, 'addCompatibilityScript' ) );


		add_filter( 'tiered_pricing_table/cart/product_cart_price', array( $this, 'addAddonsPriceToItem' ), 20, 2 );
		add_filter( 'tiered_pricing_table/cart/product_cart_regular_price/item', array(
			$this,
			'addAddonsPriceToItem',
		), 20, 2 );
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
