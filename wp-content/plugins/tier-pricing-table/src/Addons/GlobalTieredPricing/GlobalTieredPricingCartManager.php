<?php namespace TierPricingTable\Addons\GlobalTieredPricing;

use TierPricingTable\Addons\GlobalTieredPricing\CPT\GlobalTieredPricingCPT;
use TierPricingTable\PriceManager;

class GlobalTieredPricingCartManager {
	
	/**
	 * Pricing rules
	 *
	 * @var GlobalPricingRule[]
	 */
	public $globalPricingRules = array();
	
	public function __construct() {
		
		add_action( 'init', function () {
			$this->globalPricingRules = GlobalTieredPricingCPT::getGlobalRules();
		} );
		
		add_filter( 'tiered_pricing_table/cart/total_product_count', array(
			$this,
			'calculateCommonQuantities',
		), 10, 2 );
	}
	
	public function calculateCommonQuantities( $quantity, $cartItem ) {
		
		$globalPricingData = $this->getGlobalPricingDataFromCartItem( $cartItem );
		
		if ( empty( $globalPricingData ) ) {
			return $quantity;
		}
		
		// Global pricing rule is set to calculate tiered pricing individually
		if ( 'cross' !== $globalPricingData['applying_type'] ) {
			return $quantity;
		}
		
		// Reset quantity to calculate it from scratch
		$quantity = 0;
		
		foreach ( wc()->cart->get_cart_contents() as $_cartItem ) {
			
			$_globalPricingData = $this->getGlobalPricingDataFromCartItem( $_cartItem );
			
			// This is a different pricing rule
			if ( empty( $_globalPricingData['id'] ) || $_globalPricingData['id'] !== $globalPricingData['id'] ) {
				continue;
			}
			
			// Item has the same global pricing rule as the pricing provider and its set to "mix and match" strategy
			$quantity += $_cartItem['quantity'];
		}
		
		return $quantity;
	}
	
	protected function getGlobalPricingDataFromCartItem( array $cartItem ): array {
		$data = array();
		
		if ( ! ( $cartItem['data'] instanceof \WC_Product ) ) {
			return $data;
		}
		
		$pricingRule = PriceManager::getPricingRule( $cartItem['data']->get_id() );
		
		if ( 'global-rules' !== $pricingRule->provider ) {
			return $data;
		}
		
		$data['id']            = $pricingRule->providerData['rule_id'] ?? null;
		$data['applying_type'] = $pricingRule->providerData['applying_type'] ?? null;
		
		return $data;
	}
}
