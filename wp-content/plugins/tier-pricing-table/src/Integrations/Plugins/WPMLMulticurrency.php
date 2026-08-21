<?php namespace TierPricingTable\Integrations\Plugins;

use TierPricingTable\PricingRule;
use woocommerce_wpml;

class WPMLMulticurrency extends PluginIntegrationAbstract {
	
	public function run() {
		
		add_filter( 'tiered_pricing_table/price/price_by_rules', function (
			$productPrice,
			$quantity,
			$productId,
			$context,
			$place,
			PricingRule $pricingRule
		) {
			
			if ( $pricingRule->isPercentage() || ! $productPrice ) {
				return $productPrice;
			}

			return $this->convertPrice( $productPrice );
		}, 10, 10 );

		add_filter( 'tiered_pricing_table/services/regular_pricing/price', function ( $newPrice ) {

			if ( is_null( $newPrice ) ) {
				return $newPrice;
			}

			return $this->convertPrice( $newPrice );
		}, 10, 4 );

		/**
		 * Note: deliberately NO cart-side handlers here.
		 *
		 * Unlike WOOCS/CURCY/WCCS, WCML does not filter "woocommerce_product_get_price" for regular
		 * products — it converts by filtering "get_post_metadata" for the _price meta
		 * (WCML_Multi_Currency_Prices::product_price_filter). A price written to the product object
		 * with set_price() therefore never passes through WCML's conversion.
		 *
		 * So the tiered price must reach the cart already converted, which is exactly what the
		 * "price_by_rules" filter above does (it runs in the "view" context, including for the cart).
		 * Handing the cart a raw "edit" context price instead would leave it unconverted — that was
		 * the 7.1.3 regression where cart prices ignored exchange rates.
		 */
	}

	/**
	 * Whether WPML Multicurrency is active and a non-base currency is currently selected.
	 *
	 * Used to gate the cart-side handlers: when no conversion is happening (multicurrency module off,
	 * or the base currency is selected) the default cart price/subtotal handling is already correct,
	 * and the discount markup in the subtotal should be preserved.
	 *
	 * @return bool
	 */
	protected function isConversionNeeded(): bool {

		/**
		 * Clarifying type
		 *
		 * @var woocommerce_wpml $woocommerce_wpml
		 */ global $woocommerce_wpml;

		if ( ! $woocommerce_wpml || ! $woocommerce_wpml->multi_currency || ! $woocommerce_wpml->multi_currency->prices ) {
			return false;
		}

		if ( ! method_exists( $woocommerce_wpml->multi_currency, 'get_client_currency' ) ) {
			return false;
		}

		return wcml_get_woocommerce_currency_option() !== $woocommerce_wpml->multi_currency->get_client_currency();
	}

	/**
	 * Convert a price into the currency currently selected by the client.
	 *
	 * Uses WCML's own conversion pipeline (`raw_price_filter`), which applies both the exchange rate
	 * and the rounding rules configured per currency in the WPML Multicurrency settings.
	 * Calling `convert_price_amount()` alone would skip the rounding rules and produce
	 * tier prices inconsistent with the product's regular price.
	 *
	 * @param mixed $price
	 *
	 * @return mixed
	 */
	protected function convertPrice( $price ) {

		if ( ! $this->isConversionNeeded() ) {
			return $price;
		}

		/**
		 * Clarifying type
		 *
		 * @var woocommerce_wpml $woocommerce_wpml
		 */ global $woocommerce_wpml;

		if ( ! method_exists( $woocommerce_wpml->multi_currency->prices, 'raw_price_filter' ) ) {
			return $price;
		}

		return $woocommerce_wpml->multi_currency->prices->raw_price_filter(
			$price,
			$woocommerce_wpml->multi_currency->get_client_currency()
		);
	}
	
	public function getTitle(): string {
		return  'WPML Multicurrency';
	}
	
	public function getIconURL(): string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/wpml-multicurrency-icon.png' );
	}
	
	public function getAuthorURL(): string {
		return 'https://wpml.org/documentation/related-projects/woocommerce-multilingual/';
	}
	
	public function getDescription(): string {
		return __( 'Convert and display tiered pricing correctly when using WPML Multicurrency.', 'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'wpml_multicurrency';
	}
	
	public function getIntegrationCategory(): string {
		return 'multicurrency';
	}
}
