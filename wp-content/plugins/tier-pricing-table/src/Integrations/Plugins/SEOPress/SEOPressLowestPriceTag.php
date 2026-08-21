<?php namespace TierPricingTable\Integrations\Plugins\SEOPress;

use SEOPress\Models\GetTagValue;

class SEOPressLowestPriceTag extends SEOPressCustomTag implements GetTagValue {
	
	public function getKey(): string {
		return 'lowest_price';
	}
	
	public static function getDescription(): string {
		return __( 'Displays the lowest price of the product', 'tier-pricing-table' );
	}
	
	public function getValue( $context = null ): string {
		return $this->getPrice( 'lowest_price' );
	}
}