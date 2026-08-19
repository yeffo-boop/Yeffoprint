<?php namespace TierPricingTable\Addons\GlobalTieredPricing\PricingRule;

use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;

class RuleSettings {
	
	protected $priorityType = 'default';
	
	protected $tieredPricingPriority = 'prefer-product';
	protected $allowTieredPricingMixAndMatch = false;
	
	protected $regularPricingPriority = 'prefer-role-based-product';
	
	protected $quantityLimitsPriority = 'prefer-product';
	
	public function __construct( GlobalPricingRule $pricingRule ) {
		$tieredPricingPriority         = get_post_meta( $pricingRule->getId(),
			'_tpt_settings_tiered_pricing_priority_type', true );
		$allowTieredPricingMixAndMatch = get_post_meta( $pricingRule->getId(),
				'_tpt_settings_tiered_pricing_allow_mix_and_match', true ) === 'yes';
		
		$regularPricingPriority = get_post_meta( $pricingRule->getId(), '_tpt_settings_regular_pricing_priority_type',
			true );
		$quantityLimitsPriority = get_post_meta( $pricingRule->getId(), '_tpt_settings_quantity_limits_priority_type',
			true );
		$priorityType           = get_post_meta( $pricingRule->getId(), '_tpt_settings_priority_type', true );
		
		$this->setTieredPricingPriority( $tieredPricingPriority );
		$this->setAllowTieredPricingMixAndMatch( $allowTieredPricingMixAndMatch );
		
		$this->setRegularPricingPriority( $regularPricingPriority );
		$this->setQuantityLimitsPriority( $quantityLimitsPriority );
		
		$this->setPriorityType( $priorityType );
	}
	
	public function getPriorityType(): string {
		return $this->priorityType;
	}
	
	public function setPriorityType( string $priorityType ): void {
		
		if ( in_array( $priorityType, array( 'default', 'prefer-product', 'override', 'flexible' ) ) ) {
			$this->priorityType = $priorityType;
		}
	}
	
	public function getQuantityLimitsPriority(): string {
		return $this->quantityLimitsPriority;
	}
	
	public function setQuantityLimitsPriority( string $quantityLimitsPriority ): void {
		if ( in_array( $quantityLimitsPriority, array( 'prefer-product', 'prefer-role-based-product', 'override' ) ) ) {
			$this->quantityLimitsPriority = $quantityLimitsPriority;
		}
	}
	
	public function getTieredPricingPriority(): string {
		return $this->tieredPricingPriority;
	}
	
	public function setTieredPricingPriority( string $tieredPricingPriority ): void {
		if ( in_array( $tieredPricingPriority, array( 'prefer-product', 'prefer-role-based-product', 'override' ) ) ) {
			$this->tieredPricingPriority = $tieredPricingPriority;
		}
	}
	
	public function isAllowTieredPricingMixAndMatch(): bool {
		return $this->allowTieredPricingMixAndMatch;
	}
	
	public function setAllowTieredPricingMixAndMatch( bool $allowTieredPricingMixAndMatch ): void {
		$this->allowTieredPricingMixAndMatch = $allowTieredPricingMixAndMatch;
	}
	
	public function getRegularPricingPriority(): string {
		return $this->regularPricingPriority;
	}
	
	public function setRegularPricingPriority( string $regularPricingPriority ): void {
		if ( in_array( $regularPricingPriority, array( 'prefer-role-based-product', 'override' ) ) ) {
			$this->regularPricingPriority = $regularPricingPriority;
		}
	}
	
	public static function updateFromPOST( $ruleId ) {
		$tieredPricingPriority         = isset( $_POST['_tpt_settings_tiered_pricing_priority_type'] ) ? sanitize_text_field( $_POST['_tpt_settings_tiered_pricing_priority_type'] ) : 'prefer-product';
		$allowTieredPricingMixAndMatch = isset( $_POST['_tpt_settings_tiered_pricing_allow_mix_and_match'] );
		
		$regularPricingPriority = isset( $_POST['_tpt_settings_regular_pricing_priority_type'] ) ? sanitize_text_field( $_POST['_tpt_settings_regular_pricing_priority_type'] ) : 'prefer-role-based-product';
		$quantityLimitsPriority = isset( $_POST['_tpt_settings_quantity_limits_priority_type'] ) ? sanitize_text_field( $_POST['_tpt_settings_quantity_limits_priority_type'] ) : 'prefer-product';
		$priorityType           = isset( $_POST['_tpt_settings_priority_type'] ) ? sanitize_text_field( $_POST['_tpt_settings_priority_type'] ) : 'default';
		
		
		update_post_meta( $ruleId, '_tpt_settings_tiered_pricing_priority_type', $tieredPricingPriority );
		update_post_meta( $ruleId, '_tpt_settings_tiered_pricing_allow_mix_and_match',
			$allowTieredPricingMixAndMatch ? 'yes' : 'no' );
		update_post_meta( $ruleId, '_tpt_settings_regular_pricing_priority_type', $regularPricingPriority );
		update_post_meta( $ruleId, '_tpt_settings_quantity_limits_priority_type', $quantityLimitsPriority );
		
		update_post_meta( $ruleId, '_tpt_settings_priority_type', $priorityType );
	}
	
}
