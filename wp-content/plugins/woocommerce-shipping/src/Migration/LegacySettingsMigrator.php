<?php

namespace Automattic\WCShipping\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-nux.php';

use Automattic\WCShipping\Connect\WC_Connect_Nux;
use Automattic\WooCommerce\Utilities\ArrayUtil;
use WP_User;

/**
 * Class LegacySettingsMigrator
 *
 * This service helps to migrate settings data from WCS&T to the WC Shipping.
 *
 * @package Automattic\WCShipping\Migration
 */
class LegacySettingsMigrator {

	public const PURCHASE_SETTINGS = array(
		'label_box_id'    => array(
			'legacy'     => 'wc_connect_last_box_id',
			'wcshipping' => 'wcshipping_last_box_id',
		),
		'last_service_id' => array(
			'legacy'     => 'wc_connect_last_service_id',
			'wcshipping' => 'wcshipping_last_service_id',
		),
		'last_carrier_id' => array(
			'legacy'     => 'wc_connect_last_carrier_id',
			'wcshipping' => 'wcshipping_last_carrier_id',
		),
	);

	public const OPTIONS = array(
		'legacy'     => 'wc_connect_options',
		'wcshipping' => 'wcshipping_options',
	);

	public const ORIGIN_ADDRESS = array(
		'legacy'     => 'wc_connect_origin_address',
		'wcshipping' => 'wcshipping_origin_addresses',
	);

	public const NON_MIGRATABLE_SETTINGS = array(
		'tos'    => 'tos_accepted',
		'guid'   => 'store_guid',
		'banner' => 'should_display_nux_after_jp_cxn_banner',
		// All payment related data is something we fetch automatically in WC Shipping as well,
		// and their will automatically happen after a WPCOM connection is established, so they
		// will block settings migration from happening if e.g. a merchant visits the settings
		// page before doing a migration.
		'pm_url' => 'add_payment_method_url',
		'pms'    => 'payment_methods',
		'smm'    => 'shipping_methods_migrated',
	);

	public const WCST_PACKAGE_COMPATIBILITY_CLASS_FQN = 'WC_Connect_Compatibility_WCShipping_Packages';

	public const WCST_PACKAGE_COMPATIBILITY_FILTERS = array(
		/* Item: array( 'filter name', 'callback' ) */
		array(
			'option_wc_connect_options',
			array( self::WCST_PACKAGE_COMPATIBILITY_CLASS_FQN, 'intercept_packages_read' ),
		),
		array(
			'option_wc_connect_options',
			array( self::WCST_PACKAGE_COMPATIBILITY_CLASS_FQN, 'intercept_predefined_packages_read' ),
		),
	);

	public function migrate_all(): void {
		do_action( 'wcshipping_settings_migration_started' );
		$this->migrate_label_purchase_settings();
		$this->migrate_settings();
		$this->migrate_origin_address();
		do_action( 'wcshipping_settings_migration_completed' );
	}

	public function needs_migration(): bool {
		$wcshipping_options = get_option( self::OPTIONS['wcshipping'] );
		$wcshipping_origins = get_option( self::ORIGIN_ADDRESS['wcshipping'] );
		$legacy_options     = $this->get_legacy_options();
		$legacy_origins     = get_option( self::ORIGIN_ADDRESS['legacy'] );

		foreach ( self::NON_MIGRATABLE_SETTINGS as $setting_key ) {
			if ( isset( $wcshipping_options[ $setting_key ] ) ) {
				unset( $wcshipping_options[ $setting_key ] );
			}
			if ( isset( $legacy_options[ $setting_key ] ) ) {
				unset( $legacy_options[ $setting_key ] );
			}
		}

		/**
		 * If this user previously has WCS&T installed, then there are legacy options.
		 * In this case, the migration is required for the user. The user, however, can
		 * choose to delay the migration and create add entries to `wcshipping_options`
		 * before starting migration. This check is to ensure that migration is still
		 * required even if `wcshipping_options` isn't empty.
		 */
		if ( ! empty( $legacy_options ) || ! empty( $legacy_origins ) ) {
			$migrationState = MigrationState::get_state();
			if ( $migrationState !== MigrationState::DATA_MIGRATION_COMPLETED ) {
				// If the migration hasn't finished, then this user still needs to run migration.
				return true;
			}
		}

		return ! empty( $this->get_users_with_legacy_purchase_settings() )
			|| ( empty( $wcshipping_options ) && ! empty( $legacy_options ) )
			|| ( empty( $wcshipping_origins ) && ! empty( $legacy_origins ) );
	}

	/**
	 * @internal For internal use only.
	 */
	public function migrate_settings(): void {
		if ( ! $this->needs_migration() ) {
			return;
		}

		$legacy_options              = $this->get_legacy_options();
		$existing_wcshipping_options = get_option( self::OPTIONS['wcshipping'], array() );

		// First, make a copy of the WCS&T options, then we selectively overwrite based on each option's algorithm
		$new_wcshipping_options = $legacy_options;

		// Loop through the ones we can not migrate, delete them.
		foreach ( self::NON_MIGRATABLE_SETTINGS as $setting_key ) {
			if ( isset( $new_wcshipping_options[ $setting_key ] ) ) {
				unset( $new_wcshipping_options[ $setting_key ] );
			}
		}

		// If no settings are left to migrate, then quit.
		if ( empty( $new_wcshipping_options ) ) {
			return;
		}

		// Then, combine the settings together.. Use WCS&T as base, overwrite it with WooShipping on top
		$new_wcshipping_options = array_merge(
			$new_wcshipping_options,
			$existing_wcshipping_options,
		);

		// We want to keep the old store_guid since it's unique to the store and a new one will be generated if we do not move it.
		// Check this: https://github.com/woocommerce/woocommerce-shipping/pull/680#discussion_r1733339443
		if ( ! empty( $legacy_options['store_guid'] ) ) {
			$new_wcshipping_options['store_guid'] = $legacy_options['store_guid'] ?? $existing_wcshipping_options['store_guid'];
		}

		// For payment methods, we will default to the new if it's already there.
		if ( ! empty( $legacy_options['payment_methods'] ) ) {
			$new_wcshipping_options['payment_methods'] = $existing_wcshipping_options['payment_methods'] ?? $legacy_options['payment_methods'];
		}

		// For account settings, it has selected_payment_method_id which is related to payment_methods. The logic should be the same as above.
		if ( ! empty( $legacy_options['account_settings'] ) ) {
			$new_wcshipping_options['account_settings'] = $existing_wcshipping_options['account_settings'] ?? $legacy_options['account_settings'];
		}

		// For packages, they need to be combined together.
		if ( ! empty( $legacy_options['packages'] ) ) {
			$new_wcshipping_options['packages'] = array_merge( $legacy_options['packages'], $existing_wcshipping_options['packages'] ?? array() );
		}

		// For predefined packages, overwrite the WCS&T with the new ones if it exists.
		if ( ! empty( $legacy_options['predefined_packages'] ) ) {
			$new_wcshipping_options['predefined_packages'] = array_merge( $legacy_options['predefined_packages'], $existing_wcshipping_options['predefined_packages'] ?? array() );
		}

		update_option(
			self::OPTIONS['wcshipping'],
			$new_wcshipping_options
		);
	}

	/**
	 * @return int[]
	 */
	private function get_users_with_legacy_purchase_settings(): array {
		/**
		 * As all the items in self::PURCHASE_SETTINGS get saved in one go per user, it's fine to use only
		 * one of them to retrieve users with this metadata
		 */
		$users = get_users(
			array(
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => self::PURCHASE_SETTINGS['label_box_id']['legacy'],
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::PURCHASE_SETTINGS['label_box_id']['wcshipping'],
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return array_column( $users, 'ID' );
	}

	/**
	 * @internal For internal use only.
	 */
	public function migrate_label_purchase_settings(): void {
		$user_ids = $this->get_users_with_legacy_purchase_settings();
		foreach ( $user_ids as $user_id ) {
			foreach ( self::PURCHASE_SETTINGS as $versioned_setting ) {
				$setting_value = get_user_meta( $user_id, $versioned_setting['legacy'], true );
				update_user_meta( $user_id, $versioned_setting['wcshipping'], $setting_value );
			}
		}
	}

	/**
	 * @internal For internal use only.
	 */
	public function migrate_origin_address(): void {
		$legacy_origin      = get_option( self::ORIGIN_ADDRESS['legacy'] );
		$wcshipping_origins = get_option( self::ORIGIN_ADDRESS['wcshipping'] );

		if ( ! empty( $legacy_origin ) && empty( $wcshipping_origins ) ) {
			/**
			 * In the legacy plugin only verified origin address is saved but in WCS,
			 * a valid origin has to have a phone number and email and since in WCS&T email address is not recorded
			 * the migrated address is always set as unverified
			 */
			$legacy_origin['is_verified'] = false;
			$legacy_origin['id']          = 'wcst_copy_over';
			update_option(
				self::ORIGIN_ADDRESS['wcshipping'],
				array(
					$legacy_origin,
				)
			);
		}
	}

	/**
	 * Gets the value of `wc_connect_options`, deregistering WCS&T's packages setting redirection if needed.
	 *
	 * WCS&T 2.8.2 introduced a compatibility layer which will detect reads of and writes to `wc_connect_options`
	 * and replace the option's `packages` and `predefined_packages` keys with those of `wcshipping_options`.
	 *
	 * This was done to keep packages between WCS&T and WCShipping in sync. Keeping them synchronized is necessary
	 * for WCS&T's package manager (which WCShipping surfaces) and WCShipping's package selection field to display
	 * the same packages.
	 *
	 * @return array
	 */
	private function get_legacy_options(): array {
		$disabled_filters = array();

		// Remove filters if they were registered by WCS&T.
		foreach ( self::WCST_PACKAGE_COMPATIBILITY_FILTERS as $filter ) {
			if ( has_filter( $filter[0], $filter[1] ) ) {
				$disabled_filters[] = $filter;
				remove_filter( $filter[0], $filter[1] );
			}
		}

		// Get the value.
		$value = get_option( self::OPTIONS['legacy'], array() );

		// Add back filters that were previously unregistered.
		foreach ( $disabled_filters as $filter ) {
			add_filter( $filter[0], $filter[1] );
		}

		return $value;
	}
}
