<?php namespace TierPricingTable\Admin\Tips;

use TierPricingTable\Admin\Tips\Tips\DefaultVariationTip;
use TierPricingTable\Admin\Tips\Tips\RoleCustomerPricingTabTip;
use TierPricingTable\Admin\Tips\Tips\VariationsPricingCalculationTip;

/**
 * Class TipsManager
 *
 * @package TierPricingTable\Admin\Tips
 */
class TipsManager {
	
	protected static $tips = array();
	
	public function __construct() {
		self::$tips = array(
			new VariationsPricingCalculationTip(),
			new DefaultVariationTip(),
			new RoleCustomerPricingTabTip(),
		);
	}
	
	public static function getTips(): array {
		return self::$tips;
	}
	
	public static function getTipBySlug( string $slug ): ?Tip {
		$tips = self::getTips();
		
		foreach ( $tips as $tip ) {
			if ( $slug === $tip->getSlug() ) {
				return $tip;
			}
		}
		
		$tipBySlug = apply_filters( 'tiered_pricing_table/admin/tips/get_tip_by_slug', null, $slug );
		
		if ( $tipBySlug instanceof Tip ) {
			return $tipBySlug;
		}
		
		return null;
	}
}
