<?php namespace TierPricingTable\Integrations\Plugins\SEOPress;

use SEOPress\Models\GetTagValue;
use TierPricingTable\Managers\FormatPriceManager;

abstract class SEOPressCustomTag implements GetTagValue {
	
	protected $product = null;
	
	public function getProduct() {
		if ( ! is_null( $this->product ) ) {
			return $this->product;
		}
		
		$product_id    = get_queried_object_id();
		$this->product = ( ! function_exists( 'wc_get_product' ) || ! $product_id || ( ! is_admin() && ! is_singular( 'product' ) ) ) ? null : wc_get_product( $product_id );
		
		return $this->product;
	}
	
	public function getPrice( $type ): ?string {
		$product = $this->getProduct();
		
		if ( ! $product ) {
			return '';
		}
		
		return FormatPriceManager::getFormattedPrice( $product, array(
			'for_display'        => true,
			'with_suffix'        => false,
			'with_default_price' => true,
			'with_lowest_prefix' => false,
			'html'               => false,
			'display_type'       => $type,
		) );
	}
}