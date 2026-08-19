<?php
/**
 * Plugin Name: Peptide Label Customizer
 * Description: Turns WooCommerce products into live-preview, customizable vial label templates (compound name, strength, batch number, expiration date) plus size/media/color options and a custom design request flow.
 * Version: 1.0.0
 * Author: YeffoPrint
 * Text Domain: peptide-label-customizer
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'PLC_VERSION', '1.0.0' );
define( 'PLC_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Check WooCommerce is active before loading anything that depends on it.
 */
function plc_check_dependencies() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Peptide Label Customizer</strong> requires WooCommerce to be installed and active.</p></div>';
		} );
		return false;
	}
	return true;
}

/**
 * Boot the plugin.
 */
function plc_init() {
	if ( ! plc_check_dependencies() ) {
		return;
	}

	require_once PLC_PATH . 'includes/class-plc-admin.php';
	require_once PLC_PATH . 'includes/class-plc-frontend.php';
	require_once PLC_PATH . 'includes/class-plc-cart.php';
	require_once PLC_PATH . 'includes/class-plc-custom-request.php';

	new PLC_Admin();
	new PLC_Frontend();
	new PLC_Cart();
	new PLC_Custom_Request();
}
add_action( 'plugins_loaded', 'plc_init' );

/**
 * On activation, create the uploads subfolder used for generated preview images.
 */
function plc_activate() {
	$upload_dir = wp_upload_dir();
	$plc_dir    = trailingslashit( $upload_dir['basedir'] ) . 'peptide-label-previews';
	if ( ! file_exists( $plc_dir ) ) {
		wp_mkdir_p( $plc_dir );
	}
}
register_activation_hook( __FILE__, 'plc_activate' );
