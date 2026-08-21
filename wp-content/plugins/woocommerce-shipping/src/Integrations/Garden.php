<?php

namespace Automattic\WCShipping\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class Garden {

	/**
	 * Check if Garden config is enabled.
	 *
	 * @return bool
	 */
	public static function is_config_enabled() {
		/**
		 * Filter whether Garden config is enabled.
		 *
		 * Internal hook used by WooCommerce Shipping test/bootstrap infrastructure.
		 * It is not designed for third-party plugin integrations and may change or
		 * be removed in future releases without notice.
		 *
		 * @internal
		 *
		 * @param bool $is_enabled Whether Garden config is enabled.
		 */
		return apply_filters( 'wcshipping_garden_is_config_enabled', function_exists( 'garden_get_wpcloud_config' ) );
	}

	/**
	 * Get a WPCloud config value.
	 *
	 * @param string $key The config key to retrieve.
	 * @return mixed The config value or null.
	 */
	public static function get_wpcloud_config( $key ) {
		if ( ! self::is_config_enabled() ) {
			return null;
		}

		$value = function_exists( 'garden_get_wpcloud_config' ) ? garden_get_wpcloud_config( $key ) : null;

		/**
		 * Filter the WPCloud config value.
		 *
		 * Internal hook used by WooCommerce Shipping test/bootstrap infrastructure.
		 * It is not designed for third-party plugin integrations and may change or
		 * be removed in future releases without notice.
		 *
		 * @internal
		 *
		 * @param mixed  $value The config value.
		 * @param string $key   The config key.
		 */
		return apply_filters( 'wcshipping_garden_wpcloud_config', $value, $key );
	}

	public static function get_plan_info() {
		return json_decode( self::get_wpcloud_config( 'plan_info' ) ?? '', true );
	}

	public static function get_selected_payment_method_id() {
		$plan_info = self::get_plan_info();
		return intval( $plan_info['stored_details_id'] ?? 0 );
	}
}
