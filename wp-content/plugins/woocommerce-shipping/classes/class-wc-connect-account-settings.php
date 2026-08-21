<?php

namespace Automattic\WCShipping\Connect;

use Automattic\WCShipping\Integrations\WCST;
use Automattic\WCShipping\Testing\WCConnectE2EConnectionShim;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Connect_Account_Settings {
	protected WC_Connect_Service_Settings_Store $settings_store;
	protected WC_Connect_Payment_Methods_Store $payment_methods_store;

	public function __construct(
		WC_Connect_Service_Settings_Store $settings_store,
		WC_Connect_Payment_Methods_Store $payment_methods_store
	) {
			$this->settings_store        = $settings_store;
			$this->payment_methods_store = $payment_methods_store;
	}


	public function get( $isWCShipping = false ) {
		$payment_methods_warning = false;
		$payment_methods_success = $this->payment_methods_store->fetch_payment_methods_from_connect_server();

		if ( ! $payment_methods_success ) {
			$payment_methods_warning = __( 'There was a problem updating your saved credit cards.', 'woocommerce-shipping' );
		}

		$master_user                = WC_Connect_Jetpack::get_connection_owner();
		if ( ! $master_user instanceof \WP_User && WCConnectE2EConnectionShim::is_enabled() ) {
			$current_user = wp_get_current_user();
			if ( $current_user instanceof \WP_User && $current_user->ID > 0 ) {
				$master_user = $current_user;
			}
		}

		$connected_data = array(
			'login' => '',
			'email' => '',
		);
		if ( $master_user instanceof \WP_User ) {
			$connected_data_value = WC_Connect_Jetpack::get_connected_user_data( $master_user->ID );
			if ( is_array( $connected_data_value ) ) {
				$connected_data = array_merge( $connected_data, $connected_data_value );
			}
		}

		$master_user_name     = $master_user instanceof \WP_User ? $master_user->display_name : '';
		$master_user_login    = $master_user instanceof \WP_User ? $master_user->user_login : '';
		$master_user_email    = $master_user instanceof \WP_User ? $master_user->user_email : '';
		$last_box_id          = get_user_meta( get_current_user_id(), 'wcshipping_last_box_id', true );
		$last_box_id          = $last_box_id === 'individual' ? '' : $last_box_id;
		$last_service_id      = get_user_meta( get_current_user_id(), 'wcshipping_last_service_id', true );
		$last_carrier_id      = get_user_meta( get_current_user_id(), 'wcshipping_last_carrier_id', true );
		$last_order_completed = (bool) get_user_meta( get_current_user_id(), 'wcshipping_last_order_completed', true );
		$last_shipping_date   = get_user_meta( get_current_user_id(), 'wcshipping_last_shipping_date', true );

		$purchaseSettingsKey     = $isWCShipping ? 'purchaseSettings' : 'formData';
		$purchaseSettingsMetaKey = $isWCShipping ? 'purchaseMeta' : 'formMeta';

		return array(
			'storeOptions'           => $this->settings_store->get_store_options(),
			$purchaseSettingsKey     => $this->settings_store->get_account_settings(),
			$purchaseSettingsMetaKey => array(
				'can_manage_payments'     => $this->settings_store->can_user_manage_payment_methods(),
				'can_edit_settings'       => true,
				'master_user_name'        => $master_user_name,
				'master_user_login'       => $master_user_login,
				'master_user_wpcom_login' => $connected_data['login'] ? $connected_data['login'] : $master_user_login,
				'master_user_email'       => $connected_data['email'] ? $connected_data['email'] : $master_user_email,
				'payment_methods'         => $this->payment_methods_store->get_payment_methods(),
				'add_payment_method_url'  => $this->payment_methods_store->get_add_payment_method_url(),
				'warnings'                => array( 'payment_methods' => $payment_methods_warning ),
			),
			'userMeta'               => array(
				'last_box_id'          => $last_box_id,
				'last_service_id'      => $last_service_id,
				'last_carrier_id'      => $last_carrier_id,
				'last_order_completed' => $last_order_completed,
				'last_shipping_date'   => $last_shipping_date,
			),
			// Make sure there's an active WCS&T installation that supports parallel loading.
			'enabledServices'        => WCST::is_wcst_active( '2.8.2' ) ? $this->settings_store->get_enabled_services() : array(),
		);
	}
}
