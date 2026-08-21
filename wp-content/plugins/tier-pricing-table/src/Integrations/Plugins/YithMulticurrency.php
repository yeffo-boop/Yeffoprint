<?php namespace TierPricingTable\Integrations\Plugins;

use TierPricingTable\PricingRule;

class YithMulticurrency extends PluginIntegrationAbstract {

	public function run() {
		add_filter( 'tiered_pricing_table/price/price_by_rules',
			function ( $productPrice, $quantity, $productId, $context, $place, PricingRule $pricingRule ) {

				if ( ! function_exists( 'yith_wcmcs_convert_price' ) ) {
					return $productPrice;
				}

				if ( $pricingRule->isPercentage() ) {
					return $productPrice;
				}

				if ( $productPrice && 'view' === $context ) {
					return (float) yith_wcmcs_convert_price( $productPrice );
				}

				return $productPrice;

			}, 10, 10 );

		// Add currency dependency to variable product price
		add_filter( 'woocommerce_get_variation_prices_hash', function ( $hash ) {

			if ( ! function_exists( 'yith_wcmcs_get_current_currency' ) ) {
				return $hash;
			}

			$currency = yith_wcmcs_get_current_currency();

			if ( $currency && is_callable( array( $currency, 'get_code' ) ) ) {
				$hash[] = $currency->get_code();
			}

			return $hash;
		}, 10, 2 );

		/**
		 * Note: deliberately NO cart-side handlers here.
		 *
		 * The tiered price reaches the cart already converted through the "price_by_rules" filter
		 * above (it runs in the "view" context, including for the cart), which mirrors the conversion
		 * snippet YITH users run today. Handing the cart a raw "edit" context price instead would
		 * leave it unconverted unless YITH filters "woocommerce_product_get_price" — the assumption
		 * that broke WPML Multicurrency in 7.1.3, so it is not repeated here.
		 */
	}

	public function getTitle(): string {
		return 'YITH Multi Currency Switcher';
	}

	public function getIconURL(): string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/yith-raq-icon.jpeg' );
	}

	public function getAuthorURL(): string {
		return 'https://yithemes.com/themes/plugins/yith-multi-currency-switcher-for-woocommerce/';
	}

	public function getDescription(): string {
		return __( 'Convert and display tiered pricing correctly when using the YITH Multi Currency Switcher for WooCommerce.', 'tier-pricing-table' );
	}

	public function getSlug(): string {
		return 'yith-multicurrency';
	}

	public function getIntegrationCategory(): string {
		return 'multicurrency';
	}

	protected function isActiveByDefault(): bool {
		return true;
	}
}
