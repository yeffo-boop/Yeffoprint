<?php namespace TierPricingTable\Services\API;

use TierPricingTable\Services\API\ProductFields\FixedTieredPricing;
use TierPricingTable\Services\API\ProductFields\MinimumOrderQuantity;
use TierPricingTable\Services\API\ProductFields\PercentageTieredPricing;
use TierPricingTable\Services\API\ProductFields\ProductField;
use TierPricingTable\Services\API\ProductFields\RoleBasedPricingData;
use TierPricingTable\Services\API\ProductFields\TieredPricingProductSettings;
use TierPricingTable\Services\API\ProductFields\TieredPricingType;
use TierPricingTable\Core\ServiceContainerTrait;

class WooCommerceRestAPI {
	
	use ServiceContainerTrait;
	
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}
	
	public function registerRoutes() {
		
		$productFields = apply_filters( 'tiered_pricing_table/api/product_fields', array(
			TieredPricingType::class,
			FixedTieredPricing::class,
			TieredPricingProductSettings::class,
			MinimumOrderQuantity::class,
			RoleBasedPricingData::class,
			PercentageTieredPricing::class,
		) );
		
		foreach ( $productFields as $productField ) {
			/**
			 * Variable type hinting
			 *
			 * @var ProductField $productField
			 */
			$productField = new $productField();
			
			$productField->register();
		}
	}
}
