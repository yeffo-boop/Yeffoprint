<?php namespace TierPricingTable\Addons\CustomColumns\Columns;

use TierPricingTable\PricingRule;

class RowTotalPriceColumn extends AbstractCustomColumn {
	
	const TYPE = 'total_price';
	
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
				return wc_price( wc_get_price_to_display( $product, array(
					'price' => $price,
					'qty'   => $currentTierQuantity,
				) ) );
			}
		}
		
		return wc_price( wc_get_price_to_display( $product, array(
			'qty' => $pricingRule->getMinimum(true),
		) ) );
	}
	
}
