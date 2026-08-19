<?php

/**
 * Plugin Name:       WooCommerce Tiered Price Table
 * Requires Plugins:  woocommerce
 * Description:       Quantity-based discounts with nice-looking reflection on the product page.
 * Version:           7.1.5
 * Author:            U2Code
 * Author URI:        https://tiered-pricing.com/
 * Plugin URI:        https://tiered-pricing.com/
 * License:           GPLv2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       tier-pricing-table
 * Domain Path:       /languages/
 *
 * Requires PHP:      7.4
 *
 * WC requires at least: 9.0
 * WC tested up to: 11.1
 */
use TierPricingTable\TierPricingTablePlugin;
// If this file is called directly, abort.
if ( !defined( 'WPINC' ) ) {
    die;
}
if ( version_compare( phpversion(), '7.4.0', '<' ) ) {
    add_action( 'admin_notices', function () {
        ?>
			<div class='notice notice-error'>
				<p>
					Tiered Pricing Table plugin requires PHP version to be <b>7.4 or higher</b>. You run PHP
					version <?php 
        echo esc_attr( phpversion() );
        ?>
				</p>
			</div>
			<?php 
    } );
    return;
}
if ( !function_exists( 'tpt_initFreemius' ) ) {
    function tpt_initFreemius() {
        // Create a helper function for easy SDK access.
        function tpt_fs() {
            global $tpt_fs;
            if ( !isset( $tpt_fs ) ) {
                // Include Freemius SDK.
                require_once dirname( __FILE__ ) . '/freemius/start.php';
                $tpt_fs = fs_dynamic_init( array(
                    'id'               => '3433',
                    'slug'             => 'tier-pricing-table',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_d9f80d20e4c964001b87a062cd2b7',
                    'is_premium'       => false,
                    'premium_suffix'   => 'Premium',
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'trial'            => array(
                        'days'               => 7,
                        'is_require_payment' => true,
                    ),
                    'menu'             => array(
                        'first-path' => 'admin.php?page=tiered-pricing-table-welcome',
                        'contact'    => false,
                        'support'    => false,
                    ),
                    'is_org_compliant' => true,
                    'is_live'          => true,
                ) );
            }
            return $tpt_fs;
        }

        // Init Freemius.
        tpt_fs();
        // Signal that SDK was initiated.
        do_action( 'tpt_fs_loaded' );
    }

}
if ( !function_exists( 'tpt_fs_activation_url' ) ) {
    function tpt_fs_activation_url() : ?string {
        return ( tpt_fs()->is_activation_mode() ? tpt_fs()->get_activation_url() : tpt_fs()->get_upgrade_url() );
    }

}
if ( function_exists( 'tpt_fs' ) ) {
    tpt_fs()->set_basename( false, __FILE__ );
    return;
} else {
    tpt_initFreemius();
    call_user_func( function () {
        require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
        $plugin = new TierPricingTablePlugin(__FILE__);
        if ( $plugin->checkRequirements() ) {
            register_activation_hook( __FILE__, array($plugin, 'activate') );
            add_action( 'uninstall', array(TierPricingTablePlugin::class, 'uninstall') );
            tpt_fs()->add_action( 'after_uninstall', array(TierPricingTablePlugin::class, 'uninstall') );
            $plugin->run();
        }
    } );
}
define( 'TIERED_PRICING_PRODUCTION', true );