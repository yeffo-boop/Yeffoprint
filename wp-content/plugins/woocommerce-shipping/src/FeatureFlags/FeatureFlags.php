<?php

namespace Automattic\WCShipping\FeatureFlags;

use Automattic\WCShipping\Connect\WC_Connect_Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FeatureFlags {

	/**
	 * Features supported by the store.
	 *
	 * Please do not use this constant directly - instead, use the
	 * `wcshipping_features_supported_by_store` filter.
	 *
	 * @var string[]
	 */
	const FEATURES_SUPPORTED_BY_STORE = array( 'upsdap', 'fedex' );

	public function register_hooks() {
		add_filter( 'wcshipping_api_client_body', array( $this, 'decorate_api_request_body_with_feature_flags' ) );
		add_filter( 'wcshipping_features_supported_by_store', array( $this, 'get_features_supported_by_store' ) );
	}

	public function decorate_api_request_body_with_feature_flags( array $body ): array {
		$body['settings']['features_supported_by_store'] = apply_filters( 'wcshipping_features_supported_by_store', array() );

		// Pass `features_supported_by_client` as part of `settings`.
		// When no client features are provided (e.g. during schema fetch), default to the
		// store's supported features so the connect server includes all relevant data in
		// the cached schema. Per-request filtering in get_predefined_packages_schema()
		// ensures only clients that declare feature support will see the data.
		if ( isset( $body['features_supported_by_client'] ) ) {
			$body['settings']['features_supported_by_client'] = $body['features_supported_by_client'];
			unset( $body['features_supported_by_client'] );
		} else {
			$body['settings']['features_supported_by_client'] = $body['settings']['features_supported_by_store'];
		}

		return $body;
	}

	/**
	 * Get features supported by the store.
	 *
	 * @return string[]
	 */
	public function get_features_supported_by_store(): array {
		$features = self::FEATURES_SUPPORTED_BY_STORE;

		if ( self::is_bulk_labels_enabled() ) {
			$features[] = 'bulk_labels';
		}

		return $features;
	}

	/**
	 * Check if ScanForm feature is enabled.
	 *
	 * Checks the user setting from account_settings, with a filter to override.
	 *
	 * @return bool
	 */
	public static function is_scanform_enabled(): bool {
		$account_settings   = WC_Connect_Options::get_option( 'account_settings', array() );
		$enabled_by_setting = ! isset( $account_settings['scanform_enabled'] ) || ! empty( $account_settings['scanform_enabled'] );

		/**
		 * Filter to enable/disable USPS ScanForm feature.
		 *
		 * @param bool $enable_scanform Whether to enable the ScanForm feature. Defaults to the user setting value.
		 */
		return apply_filters( 'wcshipping_enable_scanform_feature', $enabled_by_setting );
	}

	/**
	 * Check if the bulk label printing feature is enabled.
	 *
	 * Defaults to disabled while the feature is in development. Flip on per environment via the
	 * `wcshipping_enable_bulk_labels_feature` filter (for example in a local mu-plugin).
	 *
	 * When enabled, `'bulk_labels'` is advertised to the Connect Server through
	 * `features_supported_by_store`, which lets Connect Server handlers gate bulk-only behavior.
	 *
	 * @return bool
	 */
	public static function is_bulk_labels_enabled(): bool {
		/**
		 * Filter to enable the bulk label printing feature.
		 *
		 * @param bool $enable_bulk_labels Whether to enable bulk label printing. Defaults to false.
		 */
		return (bool) apply_filters( 'wcshipping_enable_bulk_labels_feature', false );
	}
}
