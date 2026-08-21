<?php namespace TierPricingTable\Addons\TaxSettings\Overrides;

use TierPricingTable\PriceManager;

class ProductsTaxService {
	
	public function __construct() {
		add_filter( 'woocommerce_product_get_tax_class', array( $this, 'overrideTaxClass' ), 98, 2 );
		add_filter( 'woocommerce_product_variation_get_tax_class', array( $this, 'overrideTaxClass' ), 98, 2 );
		add_filter( 'woocommerce_product_get_tax_status', array( $this, 'overrideTaxStatus' ), 98, 2 );
		add_filter( 'woocommerce_product_variation_get_tax_status', array( $this, 'overrideTaxStatus' ), 98, 2 );
	}
	
	public function overrideTaxClass( $taxClass, $product ) {
		if ( ! $product ) {
			return $taxClass;
		}
		
		$pricingRule = PriceManager::getPricingRule( $product->get_id() );
		
		if ( ! empty( $pricingRule->pricingData['tax_class'] ) ) {
			return 'standard' === $pricingRule->pricingData['tax_class'] ? '' : $pricingRule->pricingData['tax_class'];
		}
		
		return $taxClass;
	}
	
	public function overrideTaxStatus( $taxClass, $product ) {
		if ( ! $product ) {
			return $taxClass;
		}
		
		$pricingRule = PriceManager::getPricingRule( $product->get_id() );
		
		if ( ! empty( $pricingRule->pricingData['tax_status'] ) ) {
			return $pricingRule->pricingData['tax_status'];
		}
		
		return $taxClass;
	}
}
