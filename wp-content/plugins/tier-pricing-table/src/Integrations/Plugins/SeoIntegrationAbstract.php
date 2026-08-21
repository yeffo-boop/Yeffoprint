<?php namespace TierPricingTable\Integrations\Plugins;

use TierPricingTable\Managers\FormatPriceManager;
use TierPricingTable\PriceManager;
use TierPricingTable\PricingRule;
use TierPricingTable\TierPricingTablePlugin;
use WC_Product;

/**
 * Shared behaviour for SEO plugin integrations (Rank Math, Yoast, SEOPress).
 *
 * Each concrete integration only wires up its plugin-specific hooks in run() and delegates the schema
 * building and price-variable formatting to the methods below, so the tiered-pricing schema stays
 * identical across every SEO plugin.
 */
abstract class SeoIntegrationAbstract extends PluginIntegrationAbstract {

	/**
	 * Memoized product for the current request.
	 *
	 * @var WC_Product|null
	 */
	protected $product = null;

	/**
	 * Settings key prefix for this integration (e.g. "rank_math", "yoast", "seopress"). Used to build
	 * the "{prefix}_enable_variables" and "{prefix}_enhance_schema" option keys.
	 *
	 * @return string
	 */
	abstract protected function getSettingsPrefix(): string;

	/**
	 * Slug used in the "tiered_pricing_table/integrations/{slug}/variable_products_supported" filter.
	 *
	 * @return string
	 */
	abstract protected function getSchemaFilterSlug(): string;

	public function getIntegrationCategory(): string {
		return 'seo';
	}

	/**
	 * Add tiered pricing offers to a product's schema data.
	 *
	 * For variable products only the low price is adjusted. For simple products with rules the offer
	 * block becomes an AggregateOffer holding a default base-price offer plus one offer per tier, so
	 * the emitted offer count matches offerCount.
	 *
	 * @param  array       $data     Schema data; the offer block lives under $data['offers'].
	 * @param  WC_Product  $product
	 *
	 * @return array
	 */
	protected function enhanceProductSchema( array $data, WC_Product $product ): array {

		// Update only the low price for variable products.
		if ( TierPricingTablePlugin::isVariableProductSupported( $product ) ) {

			$variableProductsSupported = apply_filters(
				'tiered_pricing_table/integrations/' . $this->getSchemaFilterSlug() . '/variable_products_supported',
				true, $product );

			if ( ! $variableProductsSupported ) {
				return $data;
			}

			$data['offers']['lowPrice'] = FormatPriceManager::getFormattedPrice( $product, array(
				'for_display'        => true,
				'with_suffix'        => false,
				'with_default_price' => true,
				'with_lowest_prefix' => false,
				'html'               => false,
				'display_type'       => 'lowest_price',
				'use_cache'          => false,
			) );

			return $data;
		}

		if ( ! TierPricingTablePlugin::isSimpleProductSupported( $product ) ) {
			return $data;
		}

		$pricingRule = PriceManager::getPricingRule( $product->get_id() );

		if ( empty( $pricingRule->getRules() ) ) {
			return $data;
		}

		$data['offers']['@type']      = 'AggregateOffer';
		$data['offers']['offerCount'] = count( $pricingRule->getRules() ) + 1;
		$data['offers']['lowPrice']   = FormatPriceManager::getFormattedPrice( $product, array(
			'for_display'        => true,
			'with_suffix'        => false,
			'with_default_price' => true,
			'with_lowest_prefix' => false,
			'html'               => false,
			'display_type'       => 'lowest_price',
		) );
		$data['offers']['highPrice']  = $product->get_price();
		$data['offers']['offers']     = $this->buildOffers( $product, $pricingRule );

		return $data;
	}

	/**
	 * Build the list of schema offers: a default base-price offer followed by one offer per tier.
	 *
	 * @param  WC_Product   $product
	 * @param  PricingRule  $pricingRule
	 *
	 * @return array[]
	 */
	protected function buildOffers( WC_Product $product, PricingRule $pricingRule ): array {

		$rules = $pricingRule->getRules();

		// Default offer for the base price: applies from the product minimum up to the first tier.
		$offers = array(
			$this->buildOffer( $product, $product->get_price(), $pricingRule->getMinimum(),
				array_keys( $rules )[0] - 1 ),
		);

		$iterator = new \ArrayIterator( $rules );

		while ( $iterator->valid() ) {
			$quantity = $iterator->key();
			$iterator->next();

			$maxValue = $iterator->valid() ? $iterator->key() - 1 : null;

			$offers[] = $this->buildOffer( $product, $pricingRule->getTierPrice( $quantity ), $quantity, $maxValue );
		}

		return $offers;
	}

	/**
	 * Build a single schema.org Offer.
	 *
	 * @param  WC_Product  $product
	 * @param  mixed       $price
	 * @param  mixed       $minValue
	 * @param  int|null    $maxValue  Omitted from the output when null (open-ended top tier).
	 *
	 * @return array
	 */
	protected function buildOffer( WC_Product $product, $price, $minValue, $maxValue ): array {

		$eligibleQuantity = array(
			'@type'    => 'QuantitativeValue',
			'minValue' => $minValue,
		);

		if ( ! is_null( $maxValue ) ) {
			$eligibleQuantity['maxValue'] = $maxValue;
		}

		$offer = array(
			'@type'            => 'Offer',
			'price'            => $price,
			'seller'           => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name', 'display' ),
			),
			'eligibleQuantity' => $eligibleQuantity,
			'availability'     => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
		);

		if ( $product->get_sku() ) {
			$offer['sku'] = $product->get_sku();
		}

		return $offer;
	}

	/**
	 * Formatted tiered price for use in an SEO title/meta variable.
	 *
	 * @param  string  $displayType  e.g. "lowest_price" or "range".
	 * @param  bool    $html
	 *
	 * @return string
	 */
	protected function getFormattedProductPrice( string $displayType, bool $html = true ): string {

		$product = $this->get_product();

		if ( ! $product ) {
			return '';
		}

		$price = FormatPriceManager::getFormattedPrice( $product, array(
			'for_display'        => true,
			'with_suffix'        => false,
			'with_default_price' => true,
			'with_lowest_prefix' => false,
			'html'               => $html,
			'display_type'       => $displayType,
		) );

		return $price ? $price : '';
	}

	/**
	 * Resolve the product ID for the current request. Overridable for plugins that expose the ID
	 * differently (e.g. Rank Math's admin preview).
	 *
	 * @return int
	 */
	protected function resolveProductId() {
		return get_queried_object_id();
	}

	public function get_product() {

		if ( ! is_null( $this->product ) ) {
			return $this->product;
		}

		$productId = $this->resolveProductId();

		$this->product = ( ! function_exists( 'wc_get_product' ) || ! $productId || ( ! is_admin() && ! is_singular( 'product' ) ) )
			? null
			: wc_get_product( $productId );

		return $this->product;
	}

	public function isVariablesEnabled(): bool {
		return $this->getContainer()->getSettings()->get( $this->getSettingsPrefix() . '_enable_variables',
				'yes' ) === 'yes';
	}

	public function isEnhancedSchemaEnabled(): bool {
		return $this->getContainer()->getSettings()->get( $this->getSettingsPrefix() . '_enhance_schema',
				'no' ) === 'yes';
	}
}
