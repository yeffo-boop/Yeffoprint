<?php namespace TierPricingTable\Admin\Tips;

use TierPricingTable\Core\ServiceContainerTrait;

/**
 * Class Tip
 *
 * @package TierPricingTable\Admin\Tips
 */
abstract class Tip {
	
	use ServiceContainerTrait;
	
	const SEEN_TIPS_OPTION_KEY = 'tiered_pricing_seen_tips';
	const AJAX_ACTION = 'tiered_pricing_set_tip_as_seen';
	
	public function __construct() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handleAjax' ) );
	}
	
	public function handleAjax() {
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}
		
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( $_REQUEST['nonce'] ) : false;
		$slug  = isset( $_REQUEST['slug'] ) ? sanitize_text_field( $_REQUEST['slug'] ) : false;
		
		if ( ! wp_verify_nonce( $nonce, self::AJAX_ACTION ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
		}
		
		if ( ! $slug ) {
			wp_send_json_error( array( 'message' => 'Invalid slug' ) );
		}
		
		$tip = TipsManager::getTipBySlug( $slug );
		
		if ( ! $tip ) {
			wp_send_json_error( array( 'message' => 'Tip not found' ) );
		}
		
		$tip->markAsSeen();
		
		wp_send_json_success( array( 'message' => 'Tip marked as seen' ) );
	}
	
	abstract public function getSlug(): string;
	
	public function isSeen(): bool {
		return in_array( $this->getSlug(), self::getSeenTips() );
	}
	
	public function markAsSeen(): bool {
		if ( $this->isSeen() ) {
			return true;
		}
		
		$seenTips   = self::getSeenTips();
		$seenTips[] = $this->getSlug();
		
		return update_option( self::SEEN_TIPS_OPTION_KEY, $seenTips );
	}
	
	public function getMarkAsSeenURL(): string {
		return add_query_arg( array(
			'action' => self::AJAX_ACTION,
			'slug'   => $this->getSlug(),
			'nonce'  => wp_create_nonce( self::AJAX_ACTION ),
		), admin_url( 'admin-ajax.php' ) );
	}
	
	public static function getSeenTips(): array {
		return array_filter( (array) get_option( self::SEEN_TIPS_OPTION_KEY, array() ) );
	}
}
