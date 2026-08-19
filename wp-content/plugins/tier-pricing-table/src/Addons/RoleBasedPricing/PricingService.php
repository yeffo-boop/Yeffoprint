<?php namespace TierPricingTable\Addons\RoleBasedPricing;

use TierPricingTable\PricingRule;

class PricingService {
	
	public function __construct() {
		/**
		 * Main function to filter the tiered pricing rules
		 *
		 * @priority 20
		 */
		add_filter( 'tiered_pricing_table/price/pricing_rule', array(
			$this,
			'addPricing',
		), 20, 2 );
	}
	
	/**
	 * Main function to filter pricing rules with role-based pricing rule data
	 *
	 * @param  PricingRule  $pricingRule
	 * @param $productId
	 *
	 * @return PricingRule
	 */
	public function addPricing( PricingRule $pricingRule, $productId ): PricingRule {
		
		$product = wc_get_product( $productId );
		
		if ( ! $product ) {
			return $pricingRule;
		}
		
		$roleBasedRule = RoleBasedPricingRulesRepository::getInstance()->getCurrentUserRule( $product );
		
		if ( ! $roleBasedRule ) {
			return $pricingRule;
		}
		
		$pricingRule->setType( $roleBasedRule->getTieredPricingType() );
		$pricingRule->setRules( $roleBasedRule->getTieredPricingRules() );
		$pricingRule->setMinimum( $roleBasedRule->getMinimumOrderQuantity() );
		
		$pricingRule->pricingData['pricing_type'] = $roleBasedRule->getPricingType();
		
		$pricingRule->pricingData['regular_price'] = $roleBasedRule->getRegularPrice();
		$pricingRule->pricingData['sale_price']    = $roleBasedRule->getSalePrice();
		
		$pricingRule->pricingData['discount']      = $roleBasedRule->getDiscount();
		$pricingRule->pricingData['discount_type'] = $roleBasedRule->getDiscountType();
		
		$pricingRule->pricingData['tax_status'] = $roleBasedRule->getTaxStatus();
		$pricingRule->pricingData['tax_class']  = $roleBasedRule->getTaxClass();
		
		$pricingRule->provider             = 'role-based';
		$pricingRule->providerData['role'] = $roleBasedRule->getRole();
		
		$pricingRule->logPricingModification( '[role-based]: Pricing rule is overridden by the rule for: ' . $roleBasedRule->getRole() );
		
		do_action( 'tiered_pricing_table/role_based_pricing/after_adjusting_pricing_rule', $pricingRule, $roleBasedRule,
			$productId );
		
		return $pricingRule;
	}
}
