<?php namespace TierPricingTable\Addons\CustomColumns\Columns;

use TierPricingTable\PricingRule;

class SavingPerItemColumn extends AbstractCustomColumn {
	
	const TYPE = 'saving_per_item';
	
	public function getType(): string {
		return self::TYPE;
	}
	
	public function getDataType(): string {
		return 'price';
	}
	
	protected function _getSingleRowValue( PricingRule $pricingRule, $currentTierQuantity = null ): string {
		$product = wc_get_product( $pricingRule->getProductId() );
		
		if ( $currentTierQuantity && $product ) {
			$tierPrice = $pricingRule->getTierPrice( $currentTierQuantity, false );
			$regularPrice = (float) $product->get_price();
			
			if ( $tierPrice && $regularPrice && $regularPrice > $tierPrice ) {
				$saving = $regularPrice - $tierPrice;
				$priceHtml = wc_price( wc_get_price_to_display( $product, array(
					'price' => $saving,
				) ) );

				$unitName = get_post_meta( $product->get_id(), '_tiered_pricing_base_unit_name', true );
				$unitLabel = '';
				
				if ( ! empty( $unitName['singular'] ) ) {
					$unitLabel = $unitName['singular'];
				} else {
					$quantity_measurement = \TierPricingTable\Core\ServiceContainer::getInstance()->getSettings()->get( 'table_quantity_measurement', array(
						'singular' => '',
						'plural'   => '',
					) );
					if ( ! empty( $quantity_measurement['singular'] ) ) {
						$unitLabel = $quantity_measurement['singular'];
					}
				}

				if ( $unitLabel ) {
					$priceHtml .= '<span> / ' . $unitLabel . '</span>';
				}

				return $priceHtml;
			}
		}
		
		return '-';
	}
}
