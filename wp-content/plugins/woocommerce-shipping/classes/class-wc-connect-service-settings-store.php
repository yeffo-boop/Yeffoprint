<?php

namespace Automattic\WCShipping\Connect;

use Automattic\WCShipping\Packages\PackageRepository;
use Automattic\WCShipping\Packages\PackageValidationException;
use Automattic\WCShipping\Fulfillments\ShippingFulfillmentsDataStore;
use Automattic\WCShipping\Fulfillments\FulfillmentNotificationType;
use Automattic\WCShipping\Utils;
use Automattic\WCShipping\Integrations\Garden;

use WC_Order;
use WC_Cache_Helper;
use WP_Error;

class WC_Connect_Service_Settings_Store {

	/**
	 * Destination address normalization key.
	 *
	 * @var string
	 */
	const IS_DESTINATION_NORMALIZED_KEY = '_wcshipping_destination_normalized';

	/**
	 * Origin address normalization key.
	 *
	 * @var string
	 */
	const IS_ORIGIN_NORMALIZED_KEY = 'origin_normalized';

	/**
	 * @var WC_Connect_Service_Schemas_Store
	 */
	protected $service_schemas_store;

	/**
	 * @var WC_Connect_API_Client
	 */
	protected $api_client;

	/**
	 * @var WC_Connect_Logger
	 */
	protected $logger;

	private PackageRepository $package_repository;

	/**
	 * @var ShippingFulfillmentsDataStore
	 */
	private $shipping_fulfillments_data_store;

	public function __construct(
		WC_Connect_Service_Schemas_Store $service_schemas_store,
		WC_Connect_API_Client $api_client,
		WC_Connect_Logger $logger,
		ShippingFulfillmentsDataStore $shipping_fulfillments_data_store
	) {
		$this->service_schemas_store            = $service_schemas_store;
		$this->api_client                       = $api_client;
		$this->logger                           = $logger;
		$this->package_repository               = new PackageRepository();
		$this->shipping_fulfillments_data_store = $shipping_fulfillments_data_store;
	}

	/**
	 * Gets woocommerce store options that are useful for all connect services
	 *
	 * @return object|array
	 */
	public function get_store_options() {
		// ENT_COMPAT is explicitly set for cross-version compatibility as it was the default prior to PHP v8.1
		$currency_symbol = sanitize_text_field( html_entity_decode( get_woocommerce_currency_symbol(), ENT_COMPAT ) );
		$dimension_unit  = sanitize_text_field( strtolower( get_option( 'woocommerce_dimension_unit' ) ) );
		$weight_unit     = sanitize_text_field( strtolower( get_option( 'woocommerce_weight_unit' ) ) );
		$base_location   = wc_get_base_location();

		return array(
			'currency_symbol' => $currency_symbol,
			'dimension_unit'  => $this->translate_unit( $dimension_unit ),
			'weight_unit'     => $this->translate_unit( $weight_unit ),
			'origin_country'  => $base_location['country'],
		);
	}

	/**
	 * Gets connect account settings (e.g. payment method)
	 *
	 * @return array
	 */
	public function get_account_settings() {
		$default = array(
			'selected_payment_method_id' => 0,
			'enabled'                    => true,
		);

		$result               = WC_Connect_Options::get_option( 'account_settings', $default );
		$result['paper_size'] = $this->get_preferred_paper_size();
		$result               = array_merge( $default, $result );

		if ( ! isset( $result['email_receipts'] ) ) {
			$result['email_receipts'] = true;
		}

		if ( ! isset( $result['use_last_service'] ) ) {
			$result['use_last_service'] = false;
		}

		if ( ! isset( $result['use_last_package'] ) ) {
			$result['use_last_package'] = true;
		}

		if ( ! isset( $result['checkout_address_validation'] ) ) {
			$result['checkout_address_validation'] = false;
		}

		if ( ! isset( $result['automatically_open_print_dialog'] ) ) {
			$result['automatically_open_print_dialog'] = false;
		}

		$result['tax_identifiers'] = $this->get_tax_identifiers();

		if ( ! isset( $result['remember_last_used_shipping_date'] ) ) {
			$result['remember_last_used_shipping_date'] = true;
		}

		if ( ! isset( $result['return_to_sender_default'] ) ) {
			$result['return_to_sender_default'] = false;
		}

		if ( ! isset( $result['scanform_enabled'] ) ) {
			$result['scanform_enabled'] = true;
		}

		if ( Utils::is_next() ) {
			$result['selected_payment_method_id'] = 0;
		}

		return $result;
	}

	/**
	 * Updates connect account settings (e.g. payment method)
	 *
	 * @param array $settings
	 *
	 * @return true
	 */
	public function update_account_settings( $settings ) {
		// simple validation for now.
		if ( ! is_array( $settings ) ) {
			$this->logger->log( 'Array expected but not received', __METHOD__ );
			return false;

		}

		// Sanitize paper_size and save it separately.
		$paper_size = $settings['paper_size'];
		if ( ! in_array( $paper_size, array( 'label', 'letter', 'a4' ), true ) ) {
			// If the paper size is not valid, set it to label as the default.
			$paper_size = 'label';
		}
		$this->set_preferred_paper_size( $paper_size );
		unset( $settings['paper_size'] );

		$allowed_tax_identifier_ids = array( 'ioss', 'voec', 'pva' );
		foreach ( $allowed_tax_identifier_ids as $tax_identifier_id ) {
			$tax_input_key = 'tax_identifier_' . $tax_identifier_id;

			if ( ! isset( $settings[ $tax_input_key ] ) ) {
				continue;
			}

			$this->set_tax_identifier( $tax_identifier_id, wc_clean( $settings[ $tax_input_key ] ) ?? '' );
			unset( $settings[ $tax_input_key ] );
		}

		// Sanitize other fields.
		//
		// `enabled` is intentionally NOT in this allowlist (WOOSHIP-1448): WC Shipping is a
		// shipping-only plugin and the only supported way to turn it off is to deactivate
		// the plugin. Any incoming `enabled` value is silently stripped here so it cannot
		// flip the stored flag to false (e.g. from a partial settings save that omits it).
		$allowable_post = array(
			'email_receipts',
			'selected_payment_method_id',
			'use_last_package',
			'use_last_service',
			'checkout_address_validation',
			'automatically_open_print_dialog',
			'remember_last_used_shipping_date',
			'return_to_sender_default',
			'scanform_enabled',
		);

		$validated_settings = array();
		foreach ( $settings as $settings_key => $settings_value ) {
			if ( ! in_array( $settings_key, $allowable_post ) ) {
				continue;
			}
			$validated_settings[ $settings_key ] = $settings_value;
		}
		$validated_settings['selected_payment_method_id']       = isset( $validated_settings['selected_payment_method_id'] ) ? intval( $validated_settings['selected_payment_method_id'] ) : 0;
		$validated_settings['email_receipts']                   = isset( $validated_settings['email_receipts'] ) && $validated_settings['email_receipts'] ? true : false;
		$validated_settings['use_last_package']                 = isset( $validated_settings['use_last_package'] ) && $validated_settings['use_last_package'] ? true : false;
		$validated_settings['use_last_service']                 = isset( $validated_settings['use_last_service'] ) && $validated_settings['use_last_service'] ? true : false;
		$validated_settings['checkout_address_validation']      = isset( $validated_settings['checkout_address_validation'] ) && $validated_settings['checkout_address_validation'] ? true : false;
		$validated_settings['automatically_open_print_dialog']  = isset( $validated_settings['automatically_open_print_dialog'] ) && $validated_settings['automatically_open_print_dialog'] ? true : false;
		$validated_settings['remember_last_used_shipping_date'] = isset( $validated_settings['remember_last_used_shipping_date'] ) && $validated_settings['remember_last_used_shipping_date'] ? true : false;
		$validated_settings['return_to_sender_default']         = isset( $validated_settings['return_to_sender_default'] ) && $validated_settings['return_to_sender_default'] ? true : false;
		$validated_settings['scanform_enabled']                 = isset( $validated_settings['scanform_enabled'] ) && $validated_settings['scanform_enabled'] ? true : false;
		$saved = WC_Connect_Options::update_option( 'account_settings', $validated_settings );

		/**
		 * Action hook fired after successful settings save.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wcshipping_settings_saved', array_merge( $validated_settings, array( 'paper_size' => $paper_size ) ) );

		return $saved;
	}

	public function get_selected_payment_method_id() {
		if ( Garden::is_config_enabled() ) {
			return Garden::get_selected_payment_method_id();
		} else {
			$account_settings = $this->get_account_settings();
			return intval( $account_settings['selected_payment_method_id'] );
		}
	}

	public function set_selected_payment_method_id( $new_payment_method_id ) {
		$new_payment_method_id = intval( $new_payment_method_id );
		$account_settings      = $this->get_account_settings();
		$old_payment_method_id = intval( $account_settings['selected_payment_method_id'] );
		if ( $old_payment_method_id === $new_payment_method_id ) {
			return;
		}
		$account_settings['selected_payment_method_id'] = $new_payment_method_id;
		$this->update_account_settings( $account_settings );
	}

	public function can_user_manage_payment_methods() {
		global $current_user;
			$master_user = WC_Connect_Jetpack::get_connection_owner();
			return WC_Connect_Jetpack::is_offline_mode() ||
			( is_a( $master_user, 'WP_User' ) && $current_user->ID === $master_user->ID );
	}

	public function get_origin_address() {
		$wc_address_fields            = array();
		$wc_address_fields['company'] = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ); // HTML entities may be saved in the option.
		$wc_address_fields['name']    = wp_get_current_user()->display_name;
		$wc_address_fields['phone']   = '';

		$wc_countries = WC()->countries;
		// WC 3.2 introduces ability to configure a full address in the settings
		// Use it for address defaults if available
		if ( method_exists( $wc_countries, 'get_base_address' ) ) {
			$wc_address_fields['country']   = $wc_countries->get_base_country();
			$wc_address_fields['state']     = $wc_countries->get_base_state();
			$wc_address_fields['address']   = $wc_countries->get_base_address();
			$wc_address_fields['address_2'] = $wc_countries->get_base_address_2();
			$wc_address_fields['city']      = $wc_countries->get_base_city();
			$wc_address_fields['postcode']  = $wc_countries->get_base_postcode();
		} else {
			$base_location                  = wc_get_base_location();
			$wc_address_fields['country']   = $base_location['country'];
			$wc_address_fields['state']     = $base_location['state'];
			$wc_address_fields['address']   = '';
			$wc_address_fields['address_2'] = '';
			$wc_address_fields['city']      = '';
			$wc_address_fields['postcode']  = '';
		}

		$stored_address_fields    = WC_Connect_Options::get_option( 'origin_address', array() );
		$merged_fields            = is_array( $stored_address_fields ) ? array_merge( $wc_address_fields, $stored_address_fields ) : $wc_address_fields;
		$merged_fields['company'] = html_entity_decode( $merged_fields['company'], ENT_QUOTES ); // Decode again for any existing stores that had some html entities saved in the option.
		return $merged_fields;
	}

	public function get_preferred_paper_size() {
		$paper_size = WC_Connect_Options::get_option( 'paper_size', '' );
		if ( $paper_size ) {
			return $paper_size;
		}
		// According to https://en.wikipedia.org/wiki/Letter_(paper_size) US, Mexico, Canada and Dominican Republic
		// use "Letter" size, and pretty much all the rest of the world use A4, so those are sensible defaults.
		$base_location = wc_get_base_location();
		if ( in_array( $base_location['country'], array( 'US', 'CA', 'MX', 'DO' ), true ) ) {
			return 'letter';
		}
		return 'a4';
	}

	public function set_preferred_paper_size( $size ) {
		return WC_Connect_Options::update_option( 'paper_size', $size );
	}

	/**
	 * Return all shipping tax identifiers for the store.
	 *
	 * @return array
	 */
	public function get_tax_identifiers() {
		$tax_identifiers = WC_Connect_Options::get_option( 'tax_identifiers', array() );

		if ( empty( $tax_identifiers ) || ! is_array( $tax_identifiers ) ) {
			return array();
		}

		return $tax_identifiers;
	}

	/**
	 * Sets a tax identifier for the store.
	 *
	 * @param string $tax_id_type The type of tax identifier, e.g. 'ioss'.
	 * @param string $tax_id The tax identifier number / ID.
	 *
	 * @return bool true if the tax identifier was successfully set.
	 */
	public function set_tax_identifier( $tax_id_type, $tax_id ) {
		$tax_identifiers                 = WC_Connect_Options::get_option( 'tax_identifiers', array() );
		$tax_identifiers[ $tax_id_type ] = $tax_id;

		return WC_Connect_Options::update_option( 'tax_identifiers', $tax_identifiers );
	}

	/**
	 * Attempts to recover faulty json string fields that might contain strings with unescaped quotes
	 *
	 * @param string $field_name
	 * @param string $json
	 *
	 * @return string
	 */
	public function try_recover_invalid_json_string( $field_name, $json ) {
		$regex = '/"' . $field_name . '":"(.+?)","/';
		preg_match_all( $regex, $json, $match_groups );
		if ( 2 === count( $match_groups ) ) {
			foreach ( $match_groups[0] as $idx => $match ) {
				$value         = $match_groups[1][ $idx ];
				$escaped_value = preg_replace( '/(?<!\\\)"/', '\\"', $value );
				$json          = str_replace( $match, '"' . $field_name . '":"' . $escaped_value . '","', $json );
			}
		}
		return $json;
	}

	/**
	 * Attempts to recover faulty json string array fields that might contain strings with unescaped quotes
	 *
	 * @param string $field_name
	 * @param string $json
	 *
	 * @return string
	 */
	public function try_recover_invalid_json_array( $field_name, $json ) {
		$regex = '/"' . $field_name . '":\["(.+?)"\]/';
		preg_match_all( $regex, $json, $match_groups );
		if ( 2 === count( $match_groups ) ) {
			foreach ( $match_groups[0] as $idx => $match ) {
				$array         = $match_groups[1][ $idx ];
				$escaped_array = preg_replace( '/(?<![,\\\])"(?!,)/', '\\"', $array );
				$json          = str_replace( '["' . $array . '"]', '["' . $escaped_array . '"]', $json );
			}
		}
		return $json;
	}

	public function try_deserialize_labels_json( $label_data ) {
		// attempt to decode the JSON (legacy way of storing the labels data).
		$decoded_labels = json_decode( $label_data, true );
		if ( $decoded_labels ) {
			return $decoded_labels;
		}

		$label_data     = $this->try_recover_invalid_json_string( 'package_name', $label_data );
		$decoded_labels = json_decode( $label_data, true );
		if ( $decoded_labels ) {
			return $decoded_labels;
		}

		$label_data     = $this->try_recover_invalid_json_array( 'product_names', $label_data );
		$decoded_labels = json_decode( $label_data, true );
		if ( $decoded_labels ) {
			return $decoded_labels;
		}

		return array();
	}

	/**
	 * Returns labels for the specific order ID
	 *
	 * @param $order_id
	 * @param bool $use_legacy_key should the legacy key be used to retrieve the order labels, this is most useful for
	 * migration purposes.
	 *
	 * @return array
	 */
	public function get_label_order_meta_data( $order_id, $use_legacy_key = false ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$label_data = $order->get_meta( $use_legacy_key ? 'wc_connect_labels' : 'wcshipping_labels', true );
		// return an empty array if the data doesn't exist.
		if ( ! $label_data ) {
			return array();
		}

		// labels stored as an array, return.
		if ( is_array( $label_data ) ) {
			return $label_data;
		}

		return $this->try_deserialize_labels_json( $label_data );
	}

	/**
	 * Updates the existing label data
	 *
	 * @param $order_id
	 * @param $new_label_data
	 *
	 * @return array updated label info
	 */
	public function update_label_order_meta_data( $order_id, $new_label_data ) {

		if ( Utils::should_use_fulfillment_api() ) {
			$label_as_array       = (array) $new_label_data;
			$shipping_fulfillment = $this->shipping_fulfillments_data_store->get_by_label_id( $label_as_array['label_id'] );
			if ( $shipping_fulfillment ) {
				// Check if this is a refund operation
				if ( ! empty( $label_as_array['refund'] ) ) {
					// Set the fulfillment back to unfulfilled status after refund
					$shipping_fulfillment->set_status( 'unfulfilled' );
				} elseif ( $shipping_fulfillment->will_status_change( $label_as_array['label_id'], $label_as_array ) ) {
					// Only notify customer if status is changing TO 'PURCHASED'
					// As it's the first time the label status is changing to 'PURCHASED', we need to notify the customer with fulfillment created notification.
					$shipping_fulfillment->set_notify_customer( FulfillmentNotificationType::CREATED );
					$shipping_fulfillment->set_status( 'fulfilled' );
				}

				$shipping_fulfillment->update_label( $label_as_array['label_id'], $label_as_array );
				$shipping_fulfillment->save();
				return $shipping_fulfillment->get_label_by_id( $label_as_array['label_id'] ) ?? $label_as_array;
			}
			wc_get_logger()->error( 'Label not found in fulfillment' . print_r( $label_as_array, true ) );
			return $label_as_array;
		}

		$result      = $new_label_data;
		$labels_data = $this->get_label_order_meta_data( $order_id );
		foreach ( $labels_data as $index => $label_data ) {
			if ( $label_data['label_id'] === $new_label_data->label_id ) {
				$result                = array_merge( $label_data, (array) $new_label_data );
				$labels_data[ $index ] = $result;

				if ( ! isset( $label_data['tracking'] )
					&& isset( $result['tracking'] ) ) {
						$tracking_nr = $result['tracking'];
						$carrier_id  = $result['carrier_id'];
						$service     = $result['service_name'];
						WC_Connect_Extension_Compatibility::on_new_tracking_number( $order_id, $carrier_id, $tracking_nr, $service );
				}
			}
		}

		$order = wc_get_order( $order_id );
		$order->update_meta_data( 'wcshipping_labels', $labels_data );
		$order->save();

		return $result;
	}

	/**
	 * Adds new labels to the order
	 *
	 * @param $order_id
	 * @param array $new_labels - labels to be added
	 */
	public function add_labels_to_order( $order_id, $new_labels ) {
		$labels_data = $this->get_label_order_meta_data( $order_id );
		$labels_data = array_merge( $new_labels, $labels_data );
		$order       = wc_get_order( $order_id );

		$order->update_meta_data( 'wcshipping_labels', $labels_data );
		$order->save();
	}

	public function update_origin_address( $address ) {
		WC_Connect_Options::update_option( self::IS_ORIGIN_NORMALIZED_KEY, true );
		return WC_Connect_Options::update_option( 'origin_address', $address );
	}

	public function update_destination_address( $order_id, $api_address ) {
		$order      = wc_get_order( $order_id );
		$wc_address = $order->get_address( 'shipping' );

		$new_address = array_merge( array(), (array) $wc_address, (array) $api_address );
		if ( isset( $new_address['address'] ) ) {
			// rename address to address_1.
			$new_address['address_1'] = $new_address['address'];
			// remove api-specific fields.
			unset( $new_address['address'], $new_address['name'] );
		}

		foreach ( $new_address as $key => $value ) {
			if ( method_exists( $order, 'set_shipping_' . $key ) ) {
				call_user_func( array( $order, 'set_shipping_' . $key ), $value );
			}
		}

		if ( isset( $new_address['email'] ) ) {
			$order->set_billing_email( $new_address['email'] );
		}

		$order->save();
		return true;
	}

	public function is_origin_address_normalized() {
		$is_normalized = WC_Connect_Options::get_option( self::IS_ORIGIN_NORMALIZED_KEY );
		return is_null( $is_normalized ) ? false : $is_normalized;
	}

	public function set_is_origin_address_normalized( $value ) {
		WC_Connect_Options::update_option( self::IS_ORIGIN_NORMALIZED_KEY, $value );
	}

	protected function sort_services( $a, $b ) {

		if ( $a->zone_order === $b->zone_order ) {
			return ( $a->instance_id > $b->instance_id ) ? 1 : -1;
		}

		if ( is_null( $a->zone_order ) ) {
			return 1;
		}

		if ( is_null( $b->zone_order ) ) {
			return -1;
		}

		return ( $a->instance_id > $b->instance_id ) ? 1 : -1;
	}

	/**
	 * Returns the service type and id for each enabled WooCommerce Shipping service
	 *
	 * Shipping services also include instance_id and shipping zone id
	 *
	 * Note that at this time, only shipping services exist, but this method will
	 * return other services in the future
	 *
	 * @return array
	 */
	public function get_enabled_services() {
		$shipping_services = $this->service_schemas_store->get_all_shipping_method_ids();
		if ( empty( $shipping_services ) ) {
			return array();
		}
		return $this->get_enabled_services_by_ids( $shipping_services );
	}

	public function get_enabled_services_by_ids( $service_ids ) {
		if ( empty( $service_ids ) ) {
			return array();
		}

		$enabled_services = array();

		// Note: We use esc_sql here instead of prepare because we are using WHERE IN
		// https://codex.wordpress.org/Function_Reference/esc_sql.

		$escaped_list = '';
		foreach ( $service_ids as $shipping_service ) {
			if ( ! empty( $escaped_list ) ) {
				$escaped_list .= ',';
			}
			$escaped_list .= "'" . esc_sql( $shipping_service ) . "'";
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$methods = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zone_methods " .
			"LEFT JOIN {$wpdb->prefix}woocommerce_shipping_zones " .
			"ON {$wpdb->prefix}woocommerce_shipping_zone_methods.zone_id = {$wpdb->prefix}woocommerce_shipping_zones.zone_id " .
			// No need to prepare as we are using esc_sql.
			// https://codex.wordpress.org/Function_Reference/esc_sql.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"WHERE method_id IN ({$escaped_list}) " .
			'ORDER BY zone_order, instance_id;'
		);

		if ( empty( $methods ) ) {
			return $enabled_services;
		}

		foreach ( (array) $methods as $method ) {
			$service_schema   = $this->service_schemas_store->get_service_schema_by_method_id( $method->method_id );
			$service_settings = $this->get_service_settings( $method->method_id, $method->instance_id );
			if ( is_object( $service_settings ) && property_exists( $service_settings, 'title' ) ) {
				$title = $service_settings->title;
			} elseif ( is_object( $service_schema ) && property_exists( $service_schema, 'method_title' ) ) {
				$title = $service_schema->method_title;
			} else {
				$title = _x( 'Unknown', 'A service with an unknown title and unknown method_title', 'woocommerce-shipping' );
			}
			$method->service_type = 'shipping';
			$method->title        = $title;
			$method->zone_name    = empty( $method->zone_name ) ? __( 'Rest of the World', 'woocommerce-shipping' ) : $method->zone_name;
			$enabled_services[]   = $method;
		}

		usort( $enabled_services, array( $this, 'sort_services' ) );
		return $enabled_services;
	}

	/**
	 * Given a service's id and optional instance, returns the settings for that
	 * service or an empty array
	 *
	 * @param string  $service_id
	 * @param integer $service_instance
	 *
	 * @return object|array
	 */
	public function get_service_settings( $service_id, $service_instance = false ) {
		return WC_Connect_Options::get_shipping_method_option( 'form_settings', array(), $service_id, $service_instance );
	}

	/**
	 * Given id and possibly instance, validates the settings and, if they validate, saves them to options
	 *
	 * @return bool|WP_Error
	 */
	public function validate_and_possibly_update_settings( $settings, $id, $instance = false ) {

		// Validate instance or at least id if no instance is given.
		if ( ! empty( $instance ) ) {
			$service_schema = $this->service_schemas_store->get_service_schema_by_instance_id( $instance );
			if ( ! $service_schema ) {
				return new WP_Error( 'bad_instance_id', __( 'An invalid service instance was received.', 'woocommerce-shipping' ) );
			}
		} else {
			$service_schema = $this->service_schemas_store->get_service_schema_by_method_id( $id );
			if ( ! $service_schema ) {
				return new WP_Error( 'bad_service_id', __( 'An invalid service ID was received.', 'woocommerce-shipping' ) );
			}
		}

		// Validate settings with WCC server.
		$response_body = $this->api_client->validate_service_settings( $service_schema->id, $settings );

		if ( is_wp_error( $response_body ) ) {
			// TODO - handle multiple error messages when the validation endpoint can return them
			return $response_body;
		}

		// On success, save the settings to the database and exit.
		WC_Connect_Options::update_shipping_method_option( 'form_settings', $settings, $id, $instance );
		// Invalidate shipping rates session cache.
		WC_Cache_Helper::get_transient_version( 'shipping', /* $refresh = */ true );

		return true;
	}

	/**
	 * Return all saved packages templates.
	 *
	 * @since 1.1.2 - Add a unique ID to the package if it doesn't already have one.
	 * @since 1.0.0
	 *
	 * @return array[] Array of packages-as-arrays.
	 */
	public function get_packages(): array {
		return $this->package_repository->get_custom_packages();
	}

	/**
	 * Extends the global list of packages with a list of new packages.
	 *
	 * @since 1.1.2 - Add a unique ID to the package if it doesn't already have one.
	 * @since 1.0.0
	 *
	 * @param array $new_packages Packages to extend.
	 *
	 * @return void
	 * @throws PackageValidationException If at least one of the provided packages doesn't pass validation.
	 */
	public function create_packages( $new_packages ) {
		$this->package_repository->add_custom_packages( $new_packages );
	}

	/**
	 * Updates the global list of packages.
	 *
	 * @since 1.1.2 - Add a unique ID to the package if it doesn't already have one.
	 * @since 1.0.0
	 *
	 * @param array $packages The packages we wish to update.
	 *
	 * @return void
	 * @throws PackageValidationException If at least one of the provided packages doesn't pass validation.
	 */
	public function update_packages( $packages ) {
		$this->package_repository->replace_custom_packages( $packages );
	}

	/**
	 * Returns a global list of enabled predefined packages for all services
	 *
	 * @return array
	 */
	public function get_predefined_packages() {
		return WC_Connect_Options::get_option( 'predefined_packages', array() );
	}

	/**
	 * Returns a list of enabled predefined packages for the specified service
	 *
	 * @param $service_id
	 * @return array
	 */
	public function get_predefined_packages_for_service( $service_id ) {
		$packages = $this->get_predefined_packages();
		if ( ! isset( $packages[ $service_id ] ) ) {
			return array();
		}

		return $packages[ $service_id ];
	}

	/**
	 * Extends the global list of enabled predefined packages with a list of new packages
	 *
	 * @param array new_packages - packages to extend
	 */
	public function create_predefined_packages( $new_packages ) {
		if ( is_null( $new_packages ) ) {
			return;
		}
		$packages = $this->get_predefined_packages();
		$packages = array_merge_recursive( $packages, $new_packages );
		WC_Connect_Options::update_option( 'predefined_packages', $packages );
	}

	/**
	 * Updates the global list of enabled predefined packages for all services
	 *
	 * @param array packages
	 */
	public function update_predefined_packages( $packages ) {
		WC_Connect_Options::update_option( 'predefined_packages', $packages );
	}

	public function get_package_lookup() {
		$lookup = array();

		$custom_packages = $this->get_packages();
		foreach ( $custom_packages as $custom_package ) {
			$lookup[ $custom_package['id'] ] = $custom_package;
		}

		$predefined_packages_schema = $this->service_schemas_store->get_predefined_packages_schema();
		if ( is_null( $predefined_packages_schema ) ) {
			return $lookup;
		}

		foreach ( $predefined_packages_schema as $service_id => $groups ) {
			foreach ( $groups as $group ) {
				foreach ( $group->definitions as $predefined ) {
					$lookup[ $predefined->id ] = (array) $predefined;
				}
			}
		}

		return $lookup;
	}

	private function translate_unit( $value ) {
		switch ( $value ) {
			case 'kg':
				return __( 'kg', 'woocommerce-shipping' );
			case 'g':
				return __( 'g', 'woocommerce-shipping' );
			case 'lbs':
				return __( 'lbs', 'woocommerce-shipping' );
			case 'oz':
				return __( 'oz', 'woocommerce-shipping' );
			case 'm':
				return __( 'm', 'woocommerce-shipping' );
			case 'cm':
				return __( 'cm', 'woocommerce-shipping' );
			case 'mm':
				return __( 'mm', 'woocommerce-shipping' );
			case 'in':
				return __( 'in', 'woocommerce-shipping' );
			case 'yd':
				return __( 'yd', 'woocommerce-shipping' );
			default:
				$this->logger->log( 'Unexpected measurement unit: ' . $value, __METHOD__ );
				return $value;
		}
	}
}
