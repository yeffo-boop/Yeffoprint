<?php namespace TierPricingTable\Integrations\Plugins;

use WC_Bundled_Item_Data;
use WC_Product_Bundle;

class ProductBundles extends PluginIntegrationAbstract {

	public function run() {
		add_action( 'init', function () {
			// Bundle plugin does not exist
			if ( ! class_exists( '\WC_Product_Bundle' ) ) {
				return;
			}

			$this->hooks();
		} );
	}

	protected function hooks() {

		add_filter( 'tiered_pricing_table/frontend/should_wrap_price', function ( $wrap, \WC_Product $product ) {
			return ! $product->is_type( 'bundle' );
		}, 10, 2 );

		add_filter( 'tiered_pricing_table/catalog_pricing/price_html',
			function ( $priceHTML, $originalPriceHTML, \WC_Product $product ) {

				// Do not modify pricing for bundle products
				if ( 'bundle' === $product->get_type() ) {
					return $originalPriceHTML;
				}

				$currentProductId = get_queried_object_id();
				$currentProduct   = wc_get_product( $currentProductId );

				if ( $currentProduct instanceof WC_Product_Bundle ) {
					foreach ( $currentProduct->get_bundled_data_items() as $dataItem ) {
						// Do not modify prices for bundle items
						if ( $dataItem instanceof WC_Bundled_Item_Data && $dataItem->get_product_id() === $product->get_id() ) {
							return $originalPriceHTML;
						}
					}
				}

				return $priceHTML;
			}, 10, 3 );

		add_filter( 'tiered_pricing_table/supported_simple_product_types', function ( $types ) {
			$types[] = 'bundle';

			return $types;
		}, 10, 1 );

		add_action( 'wp_head', function () {
			if ( is_product() ) {

				$currentProductId = get_queried_object_id();
				$product          = wc_get_product( $currentProductId );

				if ( $product->get_type() === 'bundle' ) {
					?>
					<script>

						var TieredPricingBundlesIntegration = function () {
							this.bundle = null;

							jQuery(document).on('woocommerce-product-bundle-initializing', (function (event, bundle) {
								this.bundle = bundle;
							}).bind(this));

							jQuery('.tpt__tiered-pricing').on('tiered_price_update', (function (event, data) {

								this.bundle.price_data.base_regular_price = data.price;
								this.bundle.price_data.base_price = data.price;

								if (this.bundle.is_initialized) {
									this.bundle.dirty_subtotals = true;
									this.bundle.update_totals();
								}
							}).bind(this));
						}

						document.tieredPricingBundlesIntegration = new TieredPricingBundlesIntegration();

					</script>

					<?php
				}
			}
		} );

		add_filter( 'tiered_pricing_table/services/pricing/override_zero_prices', '__return_false' );

		add_filter( 'tiered_pricing_table/manual_created_orders/modify_item_price', function ( $modify, $item ) {
			if ( ! $modify ) {
				return false;
			}

			if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
				return $modify;
			}

			if ( function_exists( 'wc_pb_is_bundled_order_item' ) ) {
				if ( wc_pb_is_bundled_order_item( $item ) ) {
					return false;
				}
			} elseif ( ! empty( $item->get_meta( '_bundled_by', true ) ) || ! empty( $item->get_meta( 'bundled_by', true ) ) ) {
				return false;
			}

			return $modify;
		}, 10, 2 );
	}

	public function getAuthorURL(): string {
		return 'https://woocommerce.com/products/product-bundles/';
	}

	public function getIconURL(): string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/woocommerce-develop.jpeg' );
	}

	public function getTitle(): string {
		return 'Product Bundles (by WooCommerce)';
	}

	public function getDescription(): string {
		return __( 'Apply tiered pricing discounts correctly to WooCommerce Product Bundles.',
			'tier-pricing-table' );
	}

	public function getSlug(): string {
		return 'product-bundles-for-woocommerce';
	}

	public function getIntegrationCategory(): string {
		return 'custom_product_types';
	}
}
