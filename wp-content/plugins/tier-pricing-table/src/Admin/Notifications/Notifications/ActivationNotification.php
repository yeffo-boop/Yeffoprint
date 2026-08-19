<?php namespace TierPricingTable\Admin\Notifications\Notifications;

use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\TierPricingTablePlugin;

/**
 * Class Notifications
 *
 * @package TierPricingTable\Admin\Notifications
 */
class ActivationNotification extends Notification {
	
	use ServiceContainerTrait;
	
	const ACTIVATION_TRANSIENT_NAME = 'tiered_pricing_table_activated';
	
	public static function setActive() {
		set_transient( self::ACTIVATION_TRANSIENT_NAME, 'yes' );
	}
	
	public static function setInactive() {
		delete_transient( self::ACTIVATION_TRANSIENT_NAME );
	}
	
	public function getId(): string {
		return 'activation-notification';
	}
	
	public function getTemplate(): string {
		return 'admin/notifications/activation-notification.php';
	}
	
	public function getCloseURL(): string {
		return $this->getContainer()->getNotificationManager()->getNotificationCloseURL( $this->getId() );
	}
	
	public function isSeen(): bool {
		return get_transient( 'tiered_pricing_table_activated' ) !== 'yes';
	}
	
	public function isActive(): bool {
		return ! $this->isSeen();
	}
}
