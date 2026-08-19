<?php namespace TierPricingTable\Admin\Notifications\Notifications;

use TierPricingTable\Addons\GlobalTieredPricing\CPT\GlobalTieredPricingCPT;
use TierPricingTable\Core\ServiceContainerTrait;

/**
 * Class Notifications
 *
 * @package TierPricingTable\Admin\Notifications
 */
abstract class Notification {
	
	use ServiceContainerTrait;
	
	abstract public function getId(): string;
	
	abstract public function getTemplate(): string;
	
	abstract public function isActive(): bool;
	
	public function isPluginPage(): bool {
		$settingsTab         = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : false;
		$postType            = isset( $_GET['post_type'] ) ? sanitize_text_field( $_GET['post_type'] ) : false;
		$isSettingsPage      = 'tiered_pricing_table_settings' === $settingsTab;
		$isGlobalPricingRule = GlobalTieredPricingCPT::SLUG === $postType;
		
		return $isSettingsPage || $isGlobalPricingRule;
	}
	
	public function getCloseURL(): string {
		return $this->getContainer()->getNotificationManager()->getNotificationCloseURL( $this->getId() );
	}
	
	public function isSeen(): bool {
		return $this->getContainer()->getNotificationManager()->isNotificationSeen( $this->getId() );
	}
}
