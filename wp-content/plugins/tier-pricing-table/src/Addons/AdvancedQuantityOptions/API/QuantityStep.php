<?php namespace TierPricingTable\Addons\AdvancedQuantityOptions\API;

use TierPricingTable\Addons\AdvancedQuantityOptions\DataProvider;
use TierPricingTable\Services\API\ProductFields\ProductField;
use WC_Product;

class QuantityStep extends ProductField {
	
	public function getFieldSlug(): string {
		return 'tiered_pricing_quantity_step';
	}
	
	public function getValue( array $product ) {
		return DataProvider::getGroupOfQuantity( $product['id'], null, 'edit' );
	}
	
	public function updateValue( $value, WC_Product $product ) {
		$value = empty( $value ) ? null : (int) $value;
		
		DataProvider::updateGroupOfQuantity( $product->get_id(), $value );
	}
	
	public function getType(): string {
		return 'string';
	}
	
	public function getDescription(): string {
		return 'Quantity step.';
	}
}
