<?php namespace TierPricingTable\Services\API\ProductFields;

use TierPricingTable\PriceManager;
use WC_Product;

class TieredPricingType extends ProductField {
	
	public function getFieldSlug(): string {
		return 'tiered_pricing_type';
	}
	
	public function getValue( array $product ): string {
		return PriceManager::getPricingType( (int) $product['id'], 'fixed', 'edit' );
	}
	
	public function updateValue( $value, WC_Product $product ) {
		PriceManager::updatePriceRulesType( $product->get_id(), $value );
	}
	
	public function getType(): string {
		return 'string';
	}
	
	public function getDescription(): string {
		return 'Tiered pricing type. Can be either "percentage" or "fixed"';
	}
}
