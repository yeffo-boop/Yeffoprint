<?php namespace TierPricingTable\Admin\Notifications\Notifications;

use TierPricingTable\Core\ServiceContainerTrait;

/**
 * Class Notifications
 *
 * @package TierPricingTable\Admin\Notifications
 */
class YearUsingDiscount extends TwoMonthsUsingDiscount {
	
	use ServiceContainerTrait;
	
	public function getId(): string {
		return 'a-year-using-discount';
	}
	
	public function getMonthsNumberToUsePluginToSeeNotification(): int {
		return 12;
	}
}
