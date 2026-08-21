<?php namespace TierPricingTable\Admin\Notifications;

use TierPricingTable\Admin\Notifications\Notifications\ActivationNotification;
use TierPricingTable\Admin\Notifications\Notifications\BlackFriday2024;
use TierPricingTable\Admin\Notifications\Notifications\BlackFriday2025;
use TierPricingTable\Admin\Notifications\Notifications\BlackFriday2026;
use TierPricingTable\Admin\Notifications\Notifications\BlackFriday2027;
use TierPricingTable\Admin\Notifications\Notifications\BlackFriday2028;
use TierPricingTable\Admin\Notifications\Notifications\BlackFriday2029;
use TierPricingTable\Admin\Notifications\Notifications\BlackFriday2030;
use TierPricingTable\Admin\Notifications\Notifications\Notification;
use TierPricingTable\Admin\Notifications\Notifications\TwoMonthsUsingDiscount;
use TierPricingTable\Admin\Notifications\Notifications\YearUsingDiscount;
use TierPricingTable\Core\ServiceContainerTrait;

/**
 * Class Notifications
 *
 * @package TierPricingTable\Admin\Notifications
 */
class Notifications {
	
	use ServiceContainerTrait;
	
	const CLOSE_NOTIFICATION_ACTION = 'tpt_close_notification';
	const SEEN_NOTIFICATIONS_OPTION_KEY = 'tpt_seen_notifications';
	
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}
	
	public function init() {
		$this->handleClosingNotification();
		$this->initNotifications();
	}
	
	public function handleClosingNotification() {
		add_action( 'admin_post_' . self::CLOSE_NOTIFICATION_ACTION, function () {
			$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( $_GET['nonce'] ) : false;
			
			if ( ! wp_verify_nonce( $nonce, self::CLOSE_NOTIFICATION_ACTION ) ) {
				return wp_safe_redirect( wp_get_referer() );
			}
			
			$notificationID = isset( $_GET['notification_id'] ) ? sanitize_text_field( $_GET['notification_id'] ) : false;
			
			if ( ! $notificationID ) {
				return wp_safe_redirect( wp_get_referer() );
			}
			
			$this->markNotificationAsSeen( $notificationID );
			
			return wp_safe_redirect( wp_get_referer() );
		} );
	}
	
	public function initNotifications() {
		
		foreach ( $this->getNotifications() as $notification ) {
			if ( $notification->isActive() ) {
				
				add_action( 'admin_notices', function () use ( $notification ) {
					$this->getContainer()->getFileManager()->includeTemplate( $notification->getTemplate(), array(
						'notification' => $notification,
					) );
				}, 10 );
				
				return;
			}
		}
	}
	
	public function getNotificationCloseURL( $id ): string {
		return add_query_arg( array(
			'action'          => self::CLOSE_NOTIFICATION_ACTION,
			'nonce'           => wp_create_nonce( self::CLOSE_NOTIFICATION_ACTION ),
			'notification_id' => $id,
		), admin_url( 'admin-post.php' ) );
	}
	
	public function getSeenNotifications(): array {
		$seenNotifications = get_option( self::SEEN_NOTIFICATIONS_OPTION_KEY, array() );
		
		return is_array( $seenNotifications ) ? array_filter( $seenNotifications ) : array();
	}
	
	public function updateSeenNotifications( array $seenNotifications ) {
		update_option( self::SEEN_NOTIFICATIONS_OPTION_KEY, array_filter( $seenNotifications ) );
	}
	
	public function markNotificationAsSeen( $id ) {
		$seenNotifications = $this->getSeenNotifications();
		
		$seenNotifications[ $id ] = true;
		
		$this->updateSeenNotifications( $seenNotifications );
	}
	
	public function isNotificationSeen( $id ): bool {
		return array_key_exists( $id, $this->getSeenNotifications() );
	}
	
	/**
	 * Get notifications
	 *
	 * @return Notification[]
	 */
	public function getNotifications(): array {
		return array(
			new TwoMonthsUsingDiscount(),
			new YearUsingDiscount(),
			// The activation notification was changed to welcome page.
			// new ActivationNotification(),
			new BlackFriday2024(),
			new BlackFriday2025(),
			new BlackFriday2026(),
			new BlackFriday2027(),
			new BlackFriday2028(),
			new BlackFriday2029(),
			new BlackFriday2030(),
		);
	}
	
}
