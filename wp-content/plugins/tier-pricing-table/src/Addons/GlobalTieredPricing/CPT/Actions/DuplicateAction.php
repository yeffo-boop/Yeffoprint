<?php namespace TierPricingTable\Addons\GlobalTieredPricing\CPT\Actions;

use TierPricingTable\Addons\GlobalTieredPricing\CPT\GlobalTieredPricingCPT;
use TierPricingTable\Core\AdminNotifier;
use TierPricingTable\Core\ServiceContainerTrait;
use WP_Post;

class DuplicateAction {
	
	const ACTION = 'tpt_duplicate_global_rule';
	
	use ServiceContainerTrait;
	
	public function __construct() {
		
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		
		add_filter( 'post_row_actions', function ( $actions, WP_Post $post ) {
			
			if ( GlobalTieredPricingCPT::SLUG !== $post->post_type ) {
				return $actions;
			}
			
			$actions['duplicate'] = sprintf( '<a href="%s" aria-label="%s">%s</a>', 
				esc_url( $this->getRunLink( $post->ID ) ),
				esc_attr__( 'Duplicate this rule', 'tier-pricing-table' ),
				$this->getName() 
			);
			
			return $actions;
		}, 10, 2 );
	}
	
	public function getName(): string {
		return __( 'Duplicate', 'tier-pricing-table' );
	}
	
	public function handle(): bool {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( $_GET['_wpnonce'] ) : false;
		
		if ( wp_verify_nonce( $nonce, self::ACTION ) ) {
			$ruleId = isset( $_GET['rule_id'] ) ? intval( $_GET['rule_id'] ) : false;
			
			if ( $ruleId ) {
				
				$originalPost = get_post( $ruleId );
				
				if ( $originalPost && $originalPost->post_type === GlobalTieredPricingCPT::SLUG ) {
					
					$newPostArgs = array(
						'post_title'  => $originalPost->post_title . ' ' . __( '(Copy)', 'tier-pricing-table' ),
						'post_status' => 'draft',
						'post_type'   => GlobalTieredPricingCPT::SLUG,
						'post_author' => get_current_user_id(),
					);
					
					$newPostId = wp_insert_post( $newPostArgs );
					
					if ( ! is_wp_error( $newPostId ) && $newPostId ) {
						// Copy all post meta directly using wpdb to avoid serialization and slashing issues
						global $wpdb;
						$post_meta_infos = $wpdb->get_results( 
							$wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $ruleId ) 
						);
						
						if ( count( $post_meta_infos ) !== 0 ) {
							foreach ( $post_meta_infos as $meta_info ) {
								$wpdb->insert(
									$wpdb->postmeta,
									array(
										'post_id'    => $newPostId,
										'meta_key'   => $meta_info->meta_key,
										'meta_value' => $meta_info->meta_value,
									)
								);
							}
						}
						
						clean_post_cache( $newPostId );
						
						$this->getContainer()->getAdminNotifier()->flash( __( 'The rule was duplicated successfully.',
							'tier-pricing-table' ), AdminNotifier::SUCCESS, true );
					} else {
						$this->getContainer()->getAdminNotifier()->flash( __( 'Failed to duplicate rule.',
							'tier-pricing-table' ), AdminNotifier::ERROR, true );
					}
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
