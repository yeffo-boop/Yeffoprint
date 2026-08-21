<?php
/**
 * File containing the WCS&T integration class, that modifies some of the WooCommerce Shipping & Tax plugin behavior.
 *
 * @package Automattic\WCShipping\Integrations
 */

namespace Automattic\WCShipping\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for modifying some of the WooCommerce Shipping & Tax plugin behavior.
 *
 * @since 1.1.0
 */
class WCST {
	/**
	 * The plugin file for WCS&T.
	 */
	const PLUGIN_FILE = 'woocommerce-services/woocommerce-services.php';

	/**
	 * Constructor.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'maybe_filter_plugin_name' ) );
	}

	/**
	 * Check if the WooCommerce Shipping & Tax plugin is active.
	 *
	 * @since 1.1.4
	 *
	 * @param string|null $min_version Optional. Version number to compare.
	 * @return bool Whether the WooCommerce Shipping & Tax plugin is active and matches the version if provided.
	 */
	public static function is_wcst_active( $min_version = null ): bool {
		if ( ! is_plugin_active( self::PLUGIN_FILE ) ) {
			return false;
		}

		if ( $min_version ) {
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE );
			return version_compare( $plugin_data['Version'], $min_version, '>=' );
		}

		return true;
	}

	/**
	 * Maybe filter the plugin name.
	 */
	public static function maybe_filter_plugin_name(): void {
		if (
			self::is_wcst_active() &&
			! is_plugin_active( 'woocommerce-tax/woocommerce-tax.php' ) ) {
				// Use the "all_plugins" filter to change the plugin name.
				add_filter( 'all_plugins', array( __CLASS__, 'change_plugin_name' ) );
		}
	}

	/**
	 * Modify the name of the WooCommerce Shipping & Tax plugin to just WooCommerce Tax if WC Shipping is active.
	 *
	 * @param array $plugins An array of plugin data.
	 * @return array The modified array of plugin data.
	 */
	public static function change_plugin_name( $plugins ): array {
		/**
		 * Filter so other sources can prevent the plugin override from happening.
		 *
		 * @since 1.1.2
		 *
		 * @param bool $allow_override Whether to allow the plugin name to be overridden. Default is true.
		 * @return bool
		 */
		$allow_override = apply_filters( 'wcshipping_allow_override_plugin_name', true );

		if ( isset( $plugins[ self::PLUGIN_FILE ] ) && $allow_override ) {
			$new_plugin_name = 'WooCommerce Tax (previously WooCommerce Shipping & Tax)';

			$plugins[ self::PLUGIN_FILE ]['Name']        = $new_plugin_name;
			$plugins[ self::PLUGIN_FILE ]['Title']       = $new_plugin_name;
			$plugins[ self::PLUGIN_FILE ]['Description'] = _x( 'Previously WooCommerce Shipping & Tax. Hosted tax calculations for WooCommerce.', 'WooCommerce Shipping & Tax plugin description override to WooCommerce Tax', 'woocommerce-shipping' );
		}

		return $plugins;
	}
}
