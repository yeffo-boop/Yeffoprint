<?php namespace TierPricingTable\Addons\GlobalTieredPricing\CPT\Actions;

use TierPricingTable\Addons\GlobalTieredPricing\CPT\GlobalTieredPricingCPT;
use TierPricingTable\Core\AdminNotifier;
use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;
use WP_Post;

class SuspendAction {
	
	const ACTION = 'tpt_suspend_global_rule';
	
	use ServiceContainerTrait;
	
	public function __construct() {
		
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		
		add_filter( 'post_row_actions', function ( $actions, WP_Post $post ) {
			
			if ( GlobalTieredPricingCPT::SLUG !== $post->post_type ) {
				return $actions;
			}
			
			$rule = GlobalPricingRule::build( $post->ID );
			
			if ( ! $rule->isSuspended() ) {
				$actions['suspend'] = sprintf( '<a href="%s">%s</a>', $this->getRunLink( $post->ID ),
					$this->getName() );
			}
			
			return $actions;
		}, 10, 2 );
	}
	
	public function getName(): string {
		return __( 'Suspend', 'tier-pricing-table' );
	}
	
	public function handle(): bool {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( $_GET['_wpnonce'] ) : false;
		
		if ( wp_verify_nonce( $nonce, self::ACTION ) ) {
			$ruleId = isset( $_GET['rule_id'] ) ? intval( $_GET['rule_id'] ) : false;
			
			if ( $ruleId ) {
				$rule = GlobalPricingRule::build( $ruleId );
				
				if ( false !== get_post_status( $ruleId ) ) {
					$rule->suspend();
					
					$rule->save();
					
					$this->getContainer()->getAdminNotifier()->flash( __( 'The rule suspended successfully.',
						'tier-pricing-table' ), AdminNotifier::SUCCESS, true );
				}
				
			}
			
		} else {
			wp_die( 'You\'re not allowed to run this action' );
		}
		
		return wp_safe_redirect( wp_get_referer() );
	}
	
	public function getRunLink( $id ): string {
		return add_query_arg( array(
			'rule_id' => $id,
			'action'  => self::ACTION,
		), wp_nonce_url( admin_url( 'admin-post.php' ), self::ACTION ) );
	}
}
