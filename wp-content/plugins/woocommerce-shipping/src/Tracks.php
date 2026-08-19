<?php
/**
 * PHP Tracker functionality.
 *
 * @package Automattic\WCShipping\Tracks
 */

namespace Automattic\WCShipping;

use Automattic\WCShipping\Connect\WC_Connect_Jetpack;
use Automattic\WCShipping\Connect\WC_Connect_Options;
use WC_Tracks;
use WC_Site_Tracking;
use WC_Tracks_Event;
use Automattic\WCShipping\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Tracks' ) ) {
	require_once WC_ABSPATH . 'includes/tracks/class-wc-tracks.php';
}
/**
 * Automattic\WCShipping\Tracks class.
 */
class Tracks extends WC_Tracks {
	/**
	 * Tracks prefix.
	 *
	 * @var string
	 */
	const PREFIX = 'wcadmin_wcshipping_';

	/**
	 * Init function, add any wp action that should be tracked here.
	 *
	 * @return void
	 */
	public static function init() {
		// Add all WP actions that should result in recorded Tracks events here.
		add_action( 'wcshipping_plugin_activation', array( self::class, 'plugin_activation' ) );
		add_action( 'wcshipping_plugin_deactivation', array( self::class, 'plugin_deactivation' ) );
		add_action( 'wcshipping_shipping_zone_method_added', array( self::class, 'shipping_zone_method_added' ), 10, 3 );
		add_action( 'wcshipping_shipping_zone_method_deleted', array( self::class, 'shipping_zone_method_deleted' ), 10, 3 );
		add_action( 'wcshipping_shipping_zone_method_status_toggled', array( self::class, 'shipping_zone_method_status_toggled' ), 10, 4 );
		add_action( 'wcshipping_settings_saved', array( self::class, 'settings_saved' ), 10, 1 );
		add_action( 'wcshipping_show_banner', array( self::class, 'show_banner' ), 10, 1 );
		add_action( 'wcshipping_tos_accepted', array( self::class, 'tos_accepted' ), 10, 1 );
		add_action( 'wcshipping_tos_already_accepted', array( self::class, 'tos_already_accepted' ), 10, 1 );
		add_action( 'wcshipping_setup_complete_banner_dismissed', array( self::class, 'setup_complete_banner_dismissed' ) );
		add_action( 'wcshipping_settings_migration_started', array( self::class, 'wcshipping_settings_migration_started' ) );
		add_action( 'wcshipping_settings_migration_completed', array( self::class, 'wcshipping_settings_migration_completed' ) );
		add_action( 'wcshipping_labels_migration_started', array( self::class, 'wcshipping_labels_migration_started' ), 10, 1 );
		add_action( 'wcshipping_labels_migration_completed', array( self::class, 'wcshipping_labels_migration_completed' ), 10, 1 );
		add_action( 'wcshipping_wpcom_connect_site_start', array( self::class, 'wpcom_connect_site_start' ), 10, 1 );
		add_action( 'wcshipping_wpcom_connect_site_error', array( self::class, 'wpcom_connect_site_error' ), 10, 1 );
		add_action( 'wcshipping_wpcom_connect_site_connected', array( self::class, 'wpcom_connect_site_connected' ), 10, 1 );
	}

	/**
	 * Check if we can track.
	 *
	 * @return bool
	 */
	protected static function can_track() {
		// If TOS accepted we can track.
		if ( WC_Connect_Jetpack::is_connected() || WC_Connect_Jetpack::is_atomic_site() || WC_Connect_Options::get_option( 'tos_accepted' ) ) {
			return true;
		}

		// If WC Tracking is enabled we can track.
		if ( WC_Site_Tracking::is_tracking_enabled() ) {
			return true;
		}

		return false;
	}

	/**
	 * Record an event in Tracks - this is the preferred way to record events from PHP.
	 * Note: the event request won't be made if $properties has a member called `error`.
	 * We override the WC_Tracks::record_event method to add the prefix to the event name,
	 * and to add additional data to the event.
	 *
	 * @param string $event_name The name of the event without the wcadmin_wcshipping prefix.
	 * @param array  $event_properties Custom properties to send with the event.
	 * @return bool|WP_Error True for success or WP_Error if the event pixel could not be fired.
	 */
	public static function record_event( $event_name, $event_properties = array() ) {
		/**
		 * Don't track users who don't have tracking enabled.
		 */
		if ( ! self::can_track() ) {
			return false;
		}

		$user = wp_get_current_user();

		// We don't want to track user events during unit tests/CI runs.
		if ( $user instanceof \WP_User && 'wptests_capabilities' === $user->cap_key ) {
			return false;
		}

		// Normalize the properties, ie convert arrays to strings, etc.
		$event_properties = self::normalize_tracks_properties( $event_properties );

		// Add the wcadmin_wcshipping_ prefix to the event name.
		$prefixed_event_name = self::PREFIX . $event_name;
		$properties          = self::get_properties( $prefixed_event_name, $event_properties );

		// Add the additional WooCommerce Shipping props data to the event.
		$additional_props = Utils::get_settings_object();
		$properties       = array_merge( $properties, $additional_props );

		$event_obj = new WC_Tracks_Event( $properties );

		if ( is_wp_error( $event_obj->error ) ) {
			return $event_obj->error;
		}
		$event = $event_obj->record();
		return $event;
	}

	/**
	 * Normalize the properties, ie convert arrays to strings, etc.
	 *
	 * @param array  $properties The properties to normalize.
	 * @param string $parent_key The parent key.
	 * @return array
	 */
	protected static function normalize_tracks_properties( $properties, $parent_key = '' ) {
		foreach ( $properties as $key => $value ) {
			if ( is_array( $value ) ) {
				unset( $properties[ $key ] );
				foreach ( $value as $sub_key => $sub_value ) {
					$properties[ $key . '_' . $sub_key ] = is_array( $sub_value ) ? self::normalize_tracks_properties( $sub_value, $parent_key ) : $sub_value;
				}
			}
		}
		return $properties;
	}

	/**
	 * Get the additional WooCommerce Shipping properties to add to the event.
	 *
	 * @todo Implement this a default data when we track events.
	 *
	 * @return array
	 */
	public static function get_wcshipping_props() {
		$wcshipping_version = \Automattic\WCShipping\Utils::get_wcshipping_version();

		$jetpack_blog_id = WC_Connect_Jetpack::get_wpcom_site_id();
		if ( $jetpack_blog_id instanceof \WP_Error ) {
			$jetpack_blog_id = -1;
		}

		$additional_props = array(
			'wcshipping_version'  => $wcshipping_version,
			'is_atomic'           => WC_Connect_Jetpack::is_atomic_site(),
			'is_connected'        => WC_Connect_Jetpack::is_connected(),
			'is_safe_mode'        => WC_Connect_Jetpack::is_safe_mode(),
			'is_development_site' => WC_Connect_Jetpack::is_development_site(),
			'is_offline_mode'     => WC_Connect_Jetpack::is_offline_mode(),
			'wpcom_blog_id'       => $jetpack_blog_id,
		);

		return $additional_props;
	}

	/**
	 * Record a plugin activation event.
	 *
	 * @return void
	 */
	public static function plugin_activation() {
		self::record_event( 'plugin_activated' );
	}

	/**
	 * Record a plugin deactivation event.
	 *
	 * @return void
	 */
	public static function plugin_deactivation() {
		self::record_event( 'plugin_deactivated' );
	}

	/**
	 * Record a shipping zone method added event.
	 *
	 * @param int    $instance_id The instance ID.
	 * @param string $service_id The service ID.
	 * @param int    $zone_id The zone ID.
	 *
	 * @return void
	 */
	public static function shipping_zone_method_added( $instance_id, $service_id, $zone_id ) {
		$event_data = array(
			'instance_id' => $instance_id,
			'service_id'  => $service_id,
			'zone_id'     => $zone_id,
		);
		self::record_event( 'shipping_zone_method_added', $event_data );
		self::record_event( 'shipping_zone_' . $service_id . '_added', $event_data );
	}

	/**
	 * Record a shipping zone method deleted event.
	 *
	 * @param int    $instance_id The instance ID.
	 * @param string $service_id The service ID.
	 * @param int    $zone_id The zone ID.
	 *
	 * @return void
	 */
	public static function shipping_zone_method_deleted( $instance_id, $service_id, $zone_id ) {
		$event_data = array(
			'instance_id' => $instance_id,
			'service_id'  => $service_id,
			'zone_id'     => $zone_id,
		);
		self::record_event( 'shipping_zone_method_deleted', $event_data );
		self::record_event( 'shipping_zone_' . $service_id . '_deleted', $event_data );
	}

	/**
	 * Record a shipping zone method status toggled event.
	 *
	 * @param int    $instance_id The instance ID.
	 * @param string $service_id The service ID.
	 * @param int    $zone_id The zone ID.
	 * @param bool   $enabled Whether the method is enabled.
	 *
	 * @return void
	 */
	public static function shipping_zone_method_status_toggled( $instance_id, $service_id, $zone_id, $enabled ) {
		$event_data = array(
			'instance_id' => $instance_id,
			'service_id'  => $service_id,
			'zone_id'     => $zone_id,
			'enabled'     => $enabled,
		);
		if ( $enabled ) {
			self::record_event( 'shipping_zone_method_enabled', $event_data );
			self::record_event( 'shipping_zone_' . $service_id . '_enabled', $event_data );
		} else {
			self::record_event( 'shipping_zone_method_disabled', $event_data );
			self::record_event( 'shipping_zone_' . $service_id . '_disabled', $event_data );
		}
	}

	/**
	 * Record a saved settings event.
	 *
	 * @param array $settings The settings.
	 *
	 * @return void
	 */
	public static function settings_saved( array $settings ) {
		$event_data = array(
			'settings' => $settings, // This will get normalised to settings_* props due to it being an array.
		);
		self::record_event( 'settings_saved', $event_data );
	}

	/**
	 * Record when a banner is displayed.
	 *
	 * @param string $source The source aka what type of banner we are displaying.
	 *
	 * @return void
	 */
	public static function show_banner( $source = '' ) {
		self::record_event(
			'onboarding_banner_viewed',
			array(
				'source' => $source,
			)
		);
	}

	/**
	 * Record a TOS accepted event.
	 *
	 * @param string $source The source of the event, tos or connection.
	 *
	 * @return void
	 */
	public static function tos_accepted( $source = '' ) {
		self::record_event(
			'tos_accepted',
			array(
				'source' => $source,
			)
		);
	}

	/**
	 * Record when a flow would normally accept our Tos, but we already have agreement.
	 *
	 * @param string $source The source of the ToS acceptance.
	 *
	 * @return void
	 */
	public static function tos_already_accepted( $source = '' ) {
		self::record_event(
			'tos_already_accepted',
			array(
				'source' => $source,
			)
		);
	}

	/**
	 * Record a setup complete banner dismissed event.
	 *
	 * @return void
	 */
	public static function setup_complete_banner_dismissed() {
		self::record_event( 'setup_complete_banner_dismissed' );
	}

	/**
	 * Record a wpcom connect site start event when they get redirected to wp.com to connect.
	 *
	 * @param string $source The location the connection was initiated from.
	 * @return void
	 */
	public static function wpcom_connect_site_start( $source ) {
		self::record_event(
			'wpcom_connect_site_start',
			array(
				'source' => $source,
			)
		);
	}

	/**
	 * Record a WPCOM Connection error.
	 *
	 * @param \WP_Error $error The error thrown to explain why we cannot register a connection.
	 * @return void
	 */
	public static function wpcom_connect_site_error( \WP_Error $error ) {
		self::record_event(
			'wpcom_connect_site_error',
			array(
				'error_code'    => $error->get_error_code(),
				'error_message' => $error->get_error_message(),
			)
		);
	}

	/**
	 * Record a successful WPCOM registration return.
	 *
	 * @param string $source The location the connection was initiated from.
	 * @return void
	 */
	public static function wpcom_connect_site_connected( $source ) {
		self::record_event(
			'wpcom_connect_site_connected',
			array(
				'source' => $source,
			)
		);
	}

	/**
	 * Record a migration for settings started event.
	 *
	 * @return void
	 */
	public static function wcshipping_settings_migration_started() {
		self::record_event( 'settings_migration_started' );
	}

	/**
	 * Record a migration for settings completed event.
	 *
	 * @return void
	 */
	public static function wcshipping_settings_migration_completed() {
		self::record_event( 'settings_migration_completed' );
	}

	/**
	 * Record a migration for labels started event.
	 *
	 * @param array $data Contextual data about the migration.
	 * @return void
	 */
	public static function wcshipping_labels_migration_started( $data = array() ) {
		self::record_event(
			'labels_migration_started',
			array(
				'orders_to_migrate' => (int) $data['orders_to_migrate'],
			)
		);
	}

	/**
	 * Record a migration for labels completed event.
	 *
	 * @param array $data Contextual data about the migration.
	 * @return void
	 */
	public static function wcshipping_labels_migration_completed( $data = array() ) {
		self::record_event(
			'labels_migration_completed',
			array(
				'orders_migrated' => (int) $data['orders_migrated'],
			)
		);
	}

	/**
	 * Record a promo dismissed event.
	 *
	 * @param string $type The type of promo, e.g. notice, banner, etc.
	 * @param string $promo_id The ID of the promo that was dismissed.
	 *
	 * @return void
	 */
	public static function promo_dismissed( string $type, string $promo_id ) {
		self::record_event(
			"promo_{$type}_dismissed",
			array(
				'promo_id' => $promo_id,
			)
		);
	}

	/**
	 * Record a promo notice viewed event.
	 *
	 * @param string $promo_id The ID of the promo that was viewed.
	 * @return void
	 */
	public static function promo_notice_viewed( string $promo_id ) {
		self::record_event(
			'promo_notice_viewed',
			array(
				'promo_id' => $promo_id,
			)
		);
	}

	/**
	 * Record a feature banner viewed event.
	 *
	 * @param string $banner_id The ID of the feature banner that was viewed.
	 * @return void
	 */
	public static function feature_banner_viewed( string $banner_id ) {
		self::record_event(
			'banner_view',
			array(
				'banner_id' => $banner_id,
			)
		);
	}

	/**
	 * Record a feature banner dismissed event.
	 *
	 * @param string $banner_id The ID of the feature banner that was dismissed.
	 * @return void
	 */
	public static function feature_banner_dismissed( string $banner_id ) {
		self::record_event(
			'banner_dismiss',
			array(
				'banner_id' => $banner_id,
			)
		);
	}

	/**
	 * Record a feature banner button clicked event.
	 *
	 * @param string $banner_id The ID of the feature banner.
	 * @param string $button_action The action/title of the button that was clicked.
	 * @return void
	 */
	public static function feature_banner_button_clicked( string $banner_id, string $button_action ) {
		self::record_event(
			'banner_button_click',
			array(
				'banner_id'     => $banner_id,
				'button_action' => $button_action,
			)
		);
	}
}
