<?php

namespace Automattic\WCShipping\LabelSettings;

use Automattic\WCShipping\WCShippingRESTController;
use Automattic\WCShipping\Connect\WC_Connect_Logger;
use Automattic\WCShipping\Connect\WC_Connect_Functions;
use Automattic\WCShipping\Connect\WC_Connect_Service_Settings_Store;
use Automattic\WCShipping\Connect\WC_Connect_Payment_Methods_Store;
use Automattic\WCShipping\Connect\WC_Connect_Account_Settings;
use WP_REST_Server;
use WP_Error;

class AccountSettingsRestController extends WCShippingRESTController {

	protected $rest_base = 'account/settings';

	private $settings_store;

	private $logger;

	/**
	 * @var WC_Connect_Account_Settings
	 */
	protected $account_settings;

	public function __construct( WC_Connect_Service_Settings_Store $settings_store, WC_Connect_Payment_Methods_Store $payment_methods_store, WC_Connect_Logger $logger ) {
		$this->settings_store   = $settings_store;
		$this->logger           = $logger;
		$this->account_settings = new WC_Connect_Account_Settings(
			$settings_store,
			$payment_methods_store
		);
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_account_settings' ),
					'permission_callback' => array( WC_Connect_Functions::class, 'user_can_manage_labels' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_account_settings' ),
					'permission_callback' => array( WC_Connect_Functions::class, 'user_can_manage_labels' ),
				),
			)
		);
	}

	public function get_account_settings() {
		$settings = $this->account_settings->get();

		if ( isset( $settings['formData'] ) && is_array( $settings['formData'] ) ) {
			$settings['formData']['enabled'] = true;
		}

		return rest_ensure_response(
			array_merge(
				$settings,
				array( 'success' => true ),
			)
		);
	}

	public function save_account_settings( $request ) {
		$settings = $request->get_json_params();

		if ( is_array( $settings ) ) {
			unset( $settings['enabled'] );

			if ( empty( $settings ) ) {
				return rest_ensure_response( array( 'success' => true ) );
			}
		}

		if ( ! $this->settings_store->can_user_manage_payment_methods() ) {
			// Ignore the user-provided payment method ID if they don't have permission to change it.
			$old_settings                           = $this->settings_store->get_account_settings();
			$settings['selected_payment_method_id'] = $old_settings['selected_payment_method_id'];
		}

		$result = $this->settings_store->update_account_settings( $settings );

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			if ( ! is_array( $error_data ) ) {
				$error_data = array();
			}

			$error = new WP_Error(
				'save_failed',
				sprintf(
					// translators: %s: error message
					__( 'Unable to update settings. %s', 'woocommerce-shipping' ),
					$result->get_error_message()
				),
				array_merge(
					array( 'status' => 400 ),
					$error_data
				)
			);
			$this->logger->log( $error, __CLASS__ );
			return $error;
		}

		return rest_ensure_response( array( 'success' => true ) );
	}
}
