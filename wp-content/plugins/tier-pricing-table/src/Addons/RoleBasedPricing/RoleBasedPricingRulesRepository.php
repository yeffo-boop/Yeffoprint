<?php namespace TierPricingTable\Addons\RoleBasedPricing;

use TierPricingTable\TierPricingTablePlugin;
use WC_Product;

class RoleBasedPricingRulesRepository {
	
	protected static $instance;
	
	public static function getInstance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		
		return self::$instance;
	}
	
	public function getCurrentUserRule( WC_Product $product ): ?RoleBasedPricingRule {
		
		$userRoles = TierPricingTablePlugin::getCurrentUserRoles();
		
		if ( ! empty( $userRoles ) ) {
			
			foreach ( $userRoles as $role ) {
				if ( RoleBasedPriceManager::roleHasRules( $role, $product->get_id() ) ) {
					return RoleBasedPricingRule::build( $product->get_id(), $role );
				}
				
				// Check also for parent level
				if ( TierPricingTablePlugin::isVariationProductSupported( $product ) ) {
					if ( RoleBasedPriceManager::roleHasRules( $role, $product->get_parent_id() ) ) {
						return RoleBasedPricingRule::build( $product->get_parent_id(), $role );
					}
				}
			}
		}
		
		return null;
	}
}
