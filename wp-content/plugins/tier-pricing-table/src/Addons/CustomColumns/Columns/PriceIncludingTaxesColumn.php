<?php namespace TierPricingTable\Addons\CustomColumns\Columns;

use TierPricingTable\PricingRule;

class PriceIncludingTaxesColumn extends AbstractCustomColumn {
	
	const TYPE = 'price_incl_taxes';
	
	public function getType(): string {
		return self::TYPE;
	}
	
	public function getDataType(): string {
		return 'price';
	}
	
	protected function _getSingleRowValue( PricingRule $pricingRule, $currentTierQuantity = null ): string {
		$product = wc_get_product( $pricingRule->getProductId() );
		
		if ( $currentTierQuantity ) {
			$price = $pricingRule->getTierPrice( $currentTierQuantity, false );
			
			if ( $price && $product ) {
				return wc_price( wc_get_price_including_tax( $product, array(
					'price' => $price,
				) ) );
			}
		}
		
		return wc_price( wc_get_price_including_tax( $product ) );
	}
}
