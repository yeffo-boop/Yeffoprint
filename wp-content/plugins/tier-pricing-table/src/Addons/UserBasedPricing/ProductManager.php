<?php

namespace TierPricingTable\Addons\UserBasedPricing;

use Exception;
use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\Forms\MinimumOrderQuantityForm;
use TierPricingTable\Forms\RegularPricingForm;
use TierPricingTable\Forms\TieredPricingRulesForm;
use WP_Post;
class ProductManager {
    use ServiceContainerTrait;
    const GET_USER_ROW_HTML__ACTION = 'tpt_get_user_row_html';

    public function __construct() {
        // Get user row via AJAX
        add_action( 'wp_ajax_' . self::GET_USER_ROW_HTML__ACTION, array($this, 'getUserRowHtml') );
        // Activate new tab
        add_filter( 'tiered_pricing_table/admin/role_customer_pricing_tab_active', '__return_true' );
        // Render (priority 100 to show after role-based pricing)
        add_action(
            'tiered_pricing_table/admin/role_customer_pricing_tab_content',
            array($this, 'render'),
            100,
            1
        );
        add_action(
            'woocommerce_variation_options_pricing',
            function ( $loop, $variationData, WP_Post $variation ) {
                $this->render( $variation->ID, $loop );
            },
            12,
            3
        );
        // Save
        add_action( 'woocommerce_process_product_meta', function ( $productId ) {
        } );
        add_action(
            'woocommerce_save_product_variation',
            function ( $variationId, $loop ) {
            },
            10,
            3
        );
    }

    /**
     * Delete removed user-based rules
     *
     * @param  int  $productId
     * @param  null|int  $loop
     * @param  array  $data
     */
    public function handleRemoving( $productId, $loop, $data ) {
        if ( !empty( $data['tiered_price_rules_users_to_delete'] ) ) {
            foreach ( $data['tiered_price_rules_users_to_delete'] as $userToRemove ) {
                if ( !empty( $userToRemove ) ) {
                    UserBasedPriceManager::deleteAllDataForUser( $productId, (int) $userToRemove );
                }
            }
        }
        if ( !empty( $data['tiered_price_rules_users_to_delete_variation'][$loop] ) ) {
            foreach ( $data['tiered_price_rules_users_to_delete_variation'][$loop] as $userToRemove ) {
                if ( !empty( $userToRemove ) ) {
                    UserBasedPriceManager::deleteAllDataForUser( $productId, (int) $userToRemove );
                }
            }
        }
    }

    public function render( $productId, $loop = null ) {
        $this->getContainer()->getFileManager()->includeTemplate( 'addons/user-based-pricing/user-based-block.php', array(
            'product_id' => $productId,
            'loop'       => $loop,
        ) );
    }

    /**
     * AJAX Handler
     */
    public function getUserRowHtml() {
        $nonce = ( isset( $_GET['nonce'] ) ? sanitize_text_field( $_GET['nonce'] ) : false );
        if ( wp_verify_nonce( $nonce, self::GET_USER_ROW_HTML__ACTION ) ) {
            $userId = ( isset( $_GET['user_id'] ) ? intval( $_GET['user_id'] ) : 0 );
            $productId = ( isset( $_GET['product_id'] ) ? intval( $_GET['product_id'] ) : 0 );
            $loop = ( isset( $_GET['loop'] ) && '' !== $_GET['loop'] ? intval( $_GET['loop'] ) : null );
            $user = get_userdata( $userId );
            $product = wc_get_product( $productId );
            if ( $user && $product ) {
                $pricingRule = UserBasedPricingRule::build( $productId, $userId );
                wp_send_json( array(
                    'success'       => true,
                    'user_row_html' => $this->getContainer()->getFileManager()->renderTemplate( 'addons/user-based-pricing/user.php', array(
                        'loop'         => $loop,
                        'pricing_rule' => $pricingRule,
                        'product'      => $product,
                        'user'         => $user,
                    ) ),
                ) );
            }
            wp_send_json( array(
                'success'       => false,
                'error_message' => __( 'Invalid user', 'tier-pricing-table' ),
            ) );
        }
        wp_send_json( array(
            'success'       => false,
            'error_message' => __( 'Invalid nonce', 'tier-pricing-table' ),
        ) );
    }

}
