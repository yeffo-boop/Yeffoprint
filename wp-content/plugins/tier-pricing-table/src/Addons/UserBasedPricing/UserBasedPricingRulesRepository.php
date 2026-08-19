<?php namespace TierPricingTable\Addons\UserBasedPricing;

use TierPricingTable\TierPricingTablePlugin;
use WC_Product;

class UserBasedPricingRulesRepository {
	
	protected static $instance;
	
	public static function getInstance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		
		return self::$instance;
	}
	
	public function getCurrentUserRule( WC_Product $product ): ?UserBasedPricingRule {
		
		$userId = get_current_user_id();
		
		if ( $userId ) {
			
			if ( UserBasedPriceManager::userHasRules( $userId, $product->get_id() ) ) {
				return UserBasedPricingRule::build( $product->get_id(), $userId );
			}
			
			// Check also for parent level
			if ( TierPricingTablePlugin::isVariationProductSupported( $product ) ) {
				if ( UserBasedPriceManager::userHasRules( $userId, $product->get_parent_id() ) ) {
					return UserBasedPricingRule::build( $product->get_parent_id(), $userId );
				}
			}
		}
		
		return null;
	}
}
