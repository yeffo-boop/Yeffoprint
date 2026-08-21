<?php namespace TierPricingTable\Services\API\ProductFields;

use TierPricingTable\Admin\ProductPage\AdvanceOptionsForVariableProduct;
use TierPricingTable\Admin\ProductPage\TieredPricingTab;
use WC_Product;

class TieredPricingProductSettings extends ProductField {
	
	public function getFieldSlug(): string {
		return 'tiered_pricing_product_settings';
	}
	
	public function getValue( array $product ): array {
		
		$layout              = TieredPricingTab::getProductTemplate( $product['id'] );
		$productBaseUnitName = TieredPricingTab::getProductBaseUnitName( $product['id'] );
		$defaultVariationId  = AdvanceOptionsForVariableProduct::getDefaultVariation( $product['id'], 'edit' );
		
		return array(
			'layout'            => $layout,
			'base_unit_name'    => $productBaseUnitName,
			'default_variation' => $defaultVariationId,
		);
	}
	
	public function updateValue( $value, WC_Product $product ) {
		$layout                          = $value['layout'] ?? '';
		$productBaseUnitName             = $value['base_unit_name'] ? (array) $value['base_unit_name'] : [
			'singular' => '',
			'plural'   => '',
		];
		$productBaseUnitName['singular'] = $productBaseUnitName['singular'] ?? '';
		$productBaseUnitName['plural']   = $productBaseUnitName['plural'] ?? '';
		$defaultVariationId              = isset( $value['default_variation'] ) ? (int) $value['default_variation'] : null;
		
		TieredPricingTab::updateProductTemplate( $product->get_id(), $layout );
		TieredPricingTab::updateProductBaseUnitName( $product->get_id(), $productBaseUnitName );
		AdvanceOptionsForVariableProduct::updateDefaultVariation( $product->get_id(), $defaultVariationId );
	}
	
	public function getType(): string {
		return 'object';
	}
	
	public function getDescription(): string {
		return 'Additional settings for tiered pricing.';
	}
}
