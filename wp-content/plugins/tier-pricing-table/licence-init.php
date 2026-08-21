<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}

if ( !function_exists( 'tpt_fs' ) ) {
    // Create a helper function for easy SDK access.
    function tpt_fs()
    {
        global  $tpt_fs ;
        
        if ( !isset( $tpt_fs ) ) {
            // Include Freemius SDK.
            require_once dirname( __FILE__ ) . '/freemius/start.php';
            $tpt_fs = fs_dynamic_init( array(
                'id'             => '3433',
                'slug'           => 'tier-pricing-table',
                'type'           => 'plugin',
                'public_key'     => 'pk_d9f80d20e4c964001b87a062cd2b7',
                'is_premium'     => false,
                'premium_suffix' => 'Premium',
                'has_addons'     => false,
                'has_paid_plans' => true,
                'trial'          => array(
                'days'               => 7,
                'is_require_payment' => true,
            ),
                'menu'           => array(
                'first-path' => 'admin.php?page=wc-settings&tab=tiered_pricing_table_settings',
                'contact'    => false,
                'support'    => false,
            ),
                'is_live'        => true,
            ) );
        }
        
        return $tpt_fs;
    }
    
    // Init Freemius.
    tpt_fs();
    function tpt_fs_activation_url()
    {
        return ( tpt_fs()->is_activation_mode() ? tpt_fs()->get_activation_url() : tpt_fs()->get_upgrade_url() );
    }
    
    // Signal that SDK was initiated.
    do_action( 'tpt_fs_loaded' );
}
