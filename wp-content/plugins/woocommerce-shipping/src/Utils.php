<?php
/**
 * General Automattic\WCShipping utils.
 *
 * Provides utility functions useful for multiple parts of WCShipping.
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping;

use Automattic\WCShipping\Connect\WC_Connect_Jetpack;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WCShipping\Connect\WC_Connect_Options;

/**
 * Automattic\WCShipping utils class.
 */
class Utils {
	/**
	 * Get WooCommerce Shipping plugin version.
	 *
	 * @return string
	 */
	public static function get_wcshipping_version() {
		if ( defined( 'WCSHIPPING_VERSION' ) ) {
			return WCSHIPPING_VERSION;
		}
		// Fallback to reading the version from the plugin file.
		$plugin_data = get_file_data( WCSHIPPING_PLUGIN_FILE, array( 'Version' => 'Version' ) );
		return $plugin_data['Version'];
	}

	/**
	 * Return an array of usefull settings that can be used throughout the codebase and as a JS object.
	 *
	 * @return array Array of settings.
	 */
	public static function get_settings_object() {
		$wcshipping_version = self::get_wcshipping_version();

		$jetpack_blog_id = WC_Connect_Jetpack::get_wpcom_site_id();
		if ( $jetpack_blog_id instanceof \WP_Error ) {
			$jetpack_blog_id = -1;
		}

		$settings = array(
			'version'             => $wcshipping_version,
			'is_atomic'           => WC_Connect_Jetpack::is_atomic_site(),
			'is_connected'        => WC_Connect_Jetpack::is_connected(),
			'is_development_site' => WC_Connect_Jetpack::is_development_site(),
			'is_safe_mode'        => WC_Connect_Jetpack::is_safe_mode(),
			'is_offline_mode'     => WC_Connect_Jetpack::is_offline_mode(),
			'environment'         => wp_get_environment_type(),
		);

		return $settings;
	}

	/**
	 * Get customs data for a product.
	 *
	 * @since 1.1.2
	 *
	 * @param int|\WC_Product $product Product ID or object.
	 * @return array|false Return an array of customs data or false if the product does not exist.
	 */
	public static function get_product_customs_data( $product ) {
		$product = wc_get_product( $product );

		if ( ! $product ) {
			return false;
		}

		$data = $product->get_meta( 'wcshipping_customs_info' );

		// Fall back to getting WCS&T customs data if present.
		if ( empty( $data ) ) {
			$data = $product->get_meta( 'wc_connect_customs_info' );
		}

		return ! empty( $data ) ? $data : array(
			'description'      => $product->get_name(),
			'hs_tariff_number' => '',
			'origin_country'   => WC()->countries->get_base_country(),
		);
	}

	/**
	 * Get the base URL for enqueuing assets.
	 *
	 * @since 1.6.3
	 *
	 * @return string
	 */
	public static function get_enqueue_base_url() {
		return trailingslashit( defined( 'WOOCOMMERCE_SHIPPING_DEV_SERVER_URL' ) ? WOOCOMMERCE_SHIPPING_DEV_SERVER_URL : WCSHIPPING_PLUGIN_DIST_URL );
	}

	/**
	 * Filter out dev-only dependencies that are injected by build tools during HMR
	 * when WordPress hasn't registered them as script handles.
	 *
	 * @param array $dependencies The dependencies array from an asset.php file.
	 * @return array Filtered dependencies.
	 */
	public static function filter_dev_dependencies( array $dependencies ): array {
		$dev_only_handles = array( 'wp-react-refresh-runtime', 'wp-react-refresh-entry' );

		return array_values(
			array_filter(
				$dependencies,
				static function ( $dep ) use ( $dev_only_handles ) {
					// Keep registered handles; only strip dev-only ones WordPress doesn't know about.
					if ( in_array( $dep, $dev_only_handles, true ) ) {
						return wp_script_is( $dep, 'registered' );
					}

					return true;
				}
			)
		);
	}

	/**
	 * Get the plugin directory path.
	 * This is a helper function to get the plugin directory path for either the main plugin or the WooCommerce plugin.
	 *
	 * @param bool $for_woocommerce Whether to get the path for the WooCommerce plugin.
	 * @return string The plugin directory path.
	 */
	public static function get_plugin_path( $for_woocommerce = false ) {
		return $for_woocommerce ? plugin_dir_path( WC_PLUGIN_FILE ) : plugin_dir_path( WCSHIPPING_PLUGIN_FILE );
	}

	/**
	 * Get the relative path to the plugin directory.
	 * This is a helper function to get the relative path to the plugin directory for either the main plugin or the WooCommerce plugin.
	 *
	 * @param bool $for_woocommerce Whether to get the path for the WooCommerce plugin.
	 * @return string The relative path to the plugin directory.
	 */
	public static function get_relative_plugin_path( $for_woocommerce = false ) {
		// Full path to the plugins directory using plugin_dir_path.
		$plugin_full_path = self::get_plugin_path( $for_woocommerce );

		// Normalize paths to avoid issues with different directory separators.
		$plugin_full_path = wp_normalize_path( $plugin_full_path );
		$root_path        = wp_normalize_path( ABSPATH );

		// Remove the root path part, leaving only the relative path in place.
		$relative_path = str_replace( $root_path, '', $plugin_full_path );

		return $relative_path;
	}

	/**
	 * Get constants that are useful for JavaScript.
	 *
	 * @return array
	 */
	public static function get_constants_for_js() {
		return array(
			'WCSHIPPING_PLUGIN_FILE'         => WCSHIPPING_PLUGIN_FILE,
			'WCSHIPPING_PLUGIN_DIR'          => self::get_plugin_path(),
			'WCSHIPPING_RELATIVE_PLUGIN_DIR' => self::get_relative_plugin_path(),
			'WC_PLUGIN_RELATIVE_DIR'         => self::get_relative_plugin_path( true ),
		);
	}

	/**
	 * Get the file modified time as a cache buster if we're in dev mode.
	 *
	 * @param string $file Local path to the file.
	 *
	 * @return string The cache buster value to use for the given file.
	 */
	public static function get_file_version( string $file ): string {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( $file ) ) {
			return (string) filemtime( $file );
		}

		return self::get_wcshipping_version();
	}

	/**
	 * Whether to use the fulfillment API.
	 * This checks a database option and can be filtered.
	 *
	 * @return bool
	 */
	public static function should_use_fulfillment_api(): bool {
		$use_fulfillment_api = WC_Connect_Options::get_option( 'use_fulfillment_api' );
		return apply_filters( 'wcshipping_should_use_fulfillment_api', (bool) $use_fulfillment_api );
	}

	/**
	 * Get the base tracking URL for a carrier based on their ID.
	 *
	 * @param string $carrier_id Carrier ID (e.g., 'ups', 'usps', 'fedex', 'dhlexpress').
	 * @return string The base tracking URL (without tracking number), or empty string if carrier is unknown.
	 */
	public static function get_carrier_tracking_url( string $carrier_id ): string {
		$tracking_urls = array(
			'ups'        => 'https://www.ups.com/track?loc=en_US&tracknum=',
			'upsdap'     => 'https://www.ups.com/track?loc=en_US&tracknum=',
			'usps'       => 'https://tools.usps.com/go/TrackConfirmAction.action?tLabels=',
			'fedex'      => 'https://www.fedex.com/apps/fedextrack/?action=track&tracknumbers=',
			'dhlexpress' => 'https://www.dhl.com/en/express/tracking.html?brand=DHL&AWB=',
		);

		return $tracking_urls[ $carrier_id ] ?? '';
	}

	/**
	 * Get the complete tracking URL for a shipment.
	 *
	 * @param string $carrier_id Carrier ID (e.g., 'ups', 'usps', 'fedex', 'dhlexpress').
	 * @param string $tracking_number The tracking number.
	 * @return string The complete tracking URL, or empty string if carrier is unknown.
	 */
	public static function get_tracking_url( string $carrier_id, string $tracking_number ): string {
		$base_url = self::get_carrier_tracking_url( $carrier_id );

		if ( empty( $base_url ) ) {
			return '';
		}

		return $base_url . $tracking_number;
	}

	/**
	 * Checks if we're on an orders screen (HPOS or Classic), including list and edit pages.
	 *
	 * @return bool True if on orders screen, false otherwise.
	 */
	public static function is_orders_screen(): bool {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		return in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order' ), true );
	}

	public static function is_next(): bool {
		return defined( 'NEXT_ADMIN_PLUGIN_DIR' );
	}
}
