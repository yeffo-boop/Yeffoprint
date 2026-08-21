<?php namespace TierPricingTable\Services\API\ProductFields;

use TierPricingTable\PriceManager;
use WC_Product;

class FixedTieredPricing extends ProductField {
	
	public function getFieldSlug(): string {
		return 'tiered_pricing_fixed_rules';
	}
	
	public function sanitizeValue( $value ): array {
		$rules = array();
		$value = is_array( $value ) ? $value : array();
		
		foreach ( $value as $key => $val ) {
			$rules[ (int) $key ] = (float) $val;
		}
		
		return $rules;
	}
	
	public function getValue( array $product ): array {
		return PriceManager::getFixedPriceRules( (int) $product['id'], 'edit' );
	}
	
	public function updateValue( $value, WC_Product $product ) {
		update_post_meta( $product->get_id(), '_fixed_price_rules', $this->sanitizeValue( $value ) );
	}
	
	public function getType(): string {
		return 'object';
	}
	
	public function getDescription(): string {
		return 'Tiered pricing fixed rules. Key is a quantity and value is a price. Minimum quantity is 2';
	}
}
