<?php namespace TierPricingTable\Integrations\Plugins\SEOPress;

use SEOPress\Models\GetTagValue;

class SEOPressPriceRangeTag extends SEOPressCustomTag implements GetTagValue {
	
	public function getKey(): string {
		return 'price_range';
	}
	
	public static function getDescription(): string {
		return __( 'Product price range', 'tier-pricing-table' );
	}
	
	public function getValue( $context = null ): string {
		return $this->getPrice( 'range' );
	}
}