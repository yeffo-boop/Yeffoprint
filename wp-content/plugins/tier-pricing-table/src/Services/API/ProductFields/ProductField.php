<?php namespace TierPricingTable\Services\API\ProductFields;

use TierPricingTable\Core\ServiceContainerTrait;
use WC_Product;

abstract class ProductField {
	
	use ServiceContainerTrait;
	
	abstract public function getFieldSlug(): string;
	
	abstract public function getValue( array $product );
	
	abstract public function updateValue( $value, WC_Product $product );
	
	abstract public function getType();
	
	abstract public function getDescription();
	
	public function register() {
		register_rest_field( $this->getSupportedProductTypes(), $this->getFieldSlug(), array(
			'get_callback'    => array( $this, 'getValue' ),
			'update_callback' => array( $this, 'updateValue' ),
			'schema'          => array(
				'description' => $this->getDescription(),
				'type'        => $this->getType(),
				'context'     => $this->getContext(),
			),
		) );
	}
	
	public function getContext(): array {
		return array( 'view', 'edit' );
	}
	
	public function getSupportedProductTypes() {
		
		return apply_filters( 'tiered_pricing_table/api/supported_product_types', array(
			'product',
			'product_variation',
		), $this );
	}
}
