<?php
/**
 * E2E connection shim for running label flows without a real WPCOM/Jetpack connection.
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\Testing;

use Automattic\WCShipping\Connect\WC_Connect_Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCConnectE2EConnectionShim {
	private const E2E_PAYMENT_METHOD_ID = 4242;

	private static function is_logged_in_request() {
		if ( function_exists( 'is_user_logged_in' ) ) {
			return is_user_logged_in();
		}

		if ( ! defined( 'LOGGED_IN_COOKIE' ) ) {
			return false;
		}

		$cookie = filter_input( INPUT_COOKIE, LOGGED_IN_COOKIE, FILTER_UNSAFE_RAW );

		return is_string( $cookie ) && '' !== $cookie;
	}

	private static function get_query_flag_value() {
		if ( ! isset( $_GET['wcshipping_e2e_mock_connect'] ) ) {
			return null;
		}

		return sanitize_text_field( wp_unslash( (string) $_GET['wcshipping_e2e_mock_connect'] ) );
	}

	private static function get_cookie_flag_value() {
		if ( ! isset( $_COOKIE['wcshipping_e2e_mock_connect'] ) ) {
			return null;
		}

		return sanitize_text_field( wp_unslash( (string) $_COOKIE['wcshipping_e2e_mock_connect'] ) );
	}

	private static function is_truthy_flag( $value ) {
		return ! in_array(
			strtolower( trim( (string) $value ) ),
			array( '0', 'false', 'no', 'off' ),
			true
		);
	}

	public static function is_enabled() {
		if ( ! self::is_logged_in_request() ) {
			return false;
		}

		if ( '1' === getenv( 'WCSHIPPING_E2E_MOCK_CONNECT' ) ) {
			return true;
		}

		if ( '1' === getenv( 'WCSHIPPING_WPCOM_TEST_MODE' ) ) {
			return true;
		}

		if ( defined( 'WOOCOMMERCE_SERVICES_LOCAL_TEST_MODE' ) && WOOCOMMERCE_SERVICES_LOCAL_TEST_MODE ) {
			return true;
		}

		$query_flag = self::get_query_flag_value();
		if ( null !== $query_flag && self::is_truthy_flag( $query_flag ) ) {
			return true;
		}

		$cookie_flag = self::get_cookie_flag_value();

		return null !== $cookie_flag && self::is_truthy_flag( $cookie_flag );
	}

	public static function init() {
		self::persist_test_cookie();
		add_filter( 'wcshipping_jetpack_access_token', array( __CLASS__, 'provide_mock_access_token' ) );
		add_filter( 'wcshipping_jetpack_install_status', array( __CLASS__, 'force_connected_jetpack_status' ) );
		add_filter( 'wcshipping_connection_owner_wpcom_data', array( __CLASS__, 'provide_mock_connection_owner_data' ) );
		add_filter( 'wcshipping_account_settings_payload', array( __CLASS__, 'provide_mock_account_settings_payload' ), 10, 2 );
		add_filter( 'wcshipping_garden_is_config_enabled', array( __CLASS__, 'force_mock_garden_config_enabled' ) );
		add_filter( 'wcshipping_garden_wpcloud_config', array( __CLASS__, 'provide_mock_garden_config' ), 10, 2 );
		self::bootstrap_test_state();
		add_action( 'admin_init', array( __CLASS__, 'ensure_nux_state_for_tests' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_store_eligibility_for_tests' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_wcshipping_nux_banner_styles' ) );
		add_action( 'admin_head', array( __CLASS__, 'print_seed_state' ) );
		add_action( 'wp_ajax_wcshipping_e2e_seed_state', array( __CLASS__, 'get_seed_state' ) );
		add_action( 'wp_ajax_wcshipping_e2e_set_connect_server_scenario', array( WCConnectE2EAPIClientMock::class, 'set_connect_server_scenario' ) );
	}

	/**
	 * Print seeded e2e state into admin pages for browser-based tests.
	 *
	 * @return void
	 */
	public static function print_seed_state() {
		if ( ! self::is_enabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<script>window.wcshippingE2ESeedState = ' . wp_json_encode( E2ESeedData::get_seed_state() ) . ';</script>';
	}

	/**
	 * Return seeded e2e state for authenticated test-mode requests.
	 *
	 * @return void
	 */
	public static function get_seed_state() {
		if ( ! self::is_enabled() ) {
			wp_send_json_error(
				array(
					'message' => 'E2E seed state is only available in test mode.',
				),
				403
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => 'Insufficient permissions for e2e seed state.',
				),
				403
			);
		}

		wp_send_json_success( E2ESeedData::get_seed_state() );
	}
	private static function get_mock_payment_method() {
		return array(
			'payment_method_id' => self::E2E_PAYMENT_METHOD_ID,
			'name'              => 'E2E Visa',
			'card_type'         => 'visa',
			'card_digits'       => '4242',
			'expiry'            => '12/99',
		);
	}

	private static function persist_test_cookie() {
		$query_flag = self::get_query_flag_value();
		if ( null === $query_flag || ! self::is_truthy_flag( $query_flag ) ) {
			return;
		}

		$current_cookie = self::get_cookie_flag_value();
		if ( null !== $current_cookie && self::is_truthy_flag( $current_cookie ) ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		setcookie( 'wcshipping_e2e_mock_connect', '1', time() + DAY_IN_SECONDS, '/' );
		$_COOKIE['wcshipping_e2e_mock_connect'] = '1';
	}

	public static function force_connected_jetpack_status( $status ) {
		if ( ! self::is_enabled() ) {
			return $status;
		}

		return 'connected';
	}

	public static function bootstrap_test_state() {
		if ( ! self::is_enabled() ) {
			return;
		}

		self::ensure_nux_state_for_tests();
		self::ensure_store_eligibility_for_tests();
	}

	public static function ensure_nux_state_for_tests() {
		if ( ! self::is_enabled() ) {
			return;
		}

		// NUX banners are connection/TOS gated and block E2E interactions.
		if ( class_exists( '\Automattic\WCShipping\Connect\WC_Connect_Options' ) ) {
			WC_Connect_Options::update_option( 'tos_accepted', true );
			WC_Connect_Options::update_option( 'should_display_nux_after_jp_cxn_banner', false );
		}
	}

	public static function ensure_store_eligibility_for_tests() {
		if ( ! self::is_enabled() ) {
			return;
		}

		// Ensure label eligibility checks pass in E2E by pinning base store settings.
		$required_options = array(
			'woocommerce_currency'        => 'USD',
			'woocommerce_default_country' => 'US:CA',
			'woocommerce_store_address'   => '123 Main St',
			'woocommerce_store_address_2' => '',
			'woocommerce_store_city'      => 'San Francisco',
			'woocommerce_store_postcode'  => '94103',
		);

		foreach ( $required_options as $option_name => $required_value ) {
			$current_value = get_option( $option_name );
			if ( $current_value !== $required_value ) {
				update_option( $option_name, $required_value );
			}
		}
	}

	public static function provide_mock_access_token( $token ) {
		if ( ! self::is_enabled() ) {
			return $token;
		}

		$mock_token                   = new \stdClass();
		$mock_token->secret           = 'wcshipping_e2e_key.wcshipping_e2e_secret';
		$mock_token->external_user_id = 1;

		return $mock_token;
	}

	public static function provide_mock_connection_owner_data( $connected_data ) {
		if ( ! self::is_enabled() || $connected_data ) {
			return $connected_data;
		}

		return array(
			'login' => 'e2e-user',
			'email' => 'e2e@example.com',
		);
	}

	public static function provide_mock_account_settings_payload( $payload, $settings_store ) {
		if ( ! self::is_enabled() || is_array( $payload ) ) {
			return $payload;
		}

		$purchase_settings = array(
			'selected_payment_method_id'       => self::E2E_PAYMENT_METHOD_ID,
			'enabled'                          => true,
			'paper_size'                       => 'label',
			'email_receipts'                   => true,
			'use_last_service'                 => false,
			'use_last_package'                 => true,
			'checkout_address_validation'      => false,
			'automatically_open_print_dialog'  => false,
			'tax_identifiers'                  => array(),
			'remember_last_used_shipping_date' => true,
			'return_to_sender_default'         => false,
			'scanform_enabled'                 => false,
		);

		if ( is_object( $settings_store ) && method_exists( $settings_store, 'get_account_settings' ) ) {
			$purchase_settings = array_merge( $purchase_settings, $settings_store->get_account_settings() );
		}

		if ( is_object( $settings_store ) && method_exists( $settings_store, 'get_store_options' ) ) {
			$store_options = $settings_store->get_store_options();
		} else {
			$store_options = array();
		}

		$payment_method = self::get_mock_payment_method();

		return array(
			'storeOptions'     => $store_options,
			'purchaseSettings' => $purchase_settings,
			'purchaseMeta'     => array(
				'can_manage_payments'     => true,
				'can_edit_settings'       => true,
				'master_user_name'        => 'E2E User',
				'master_user_login'       => 'e2e-user',
				'master_user_wpcom_login' => 'e2e-user',
				'master_user_email'       => 'e2e@example.com',
				'payment_methods'         => array( $payment_method ),
				'add_payment_method_url'  => '',
				'warnings'                => array( 'payment_methods' => false ),
			),
			'userMeta'         => array(
				'last_box_id'          => '',
				'last_service_id'      => '',
				'last_carrier_id'      => '',
				'last_order_completed' => false,
				'last_shipping_date'   => '',
			),
			'enabledServices'  => array(),
		);
	}

	public static function force_mock_garden_config_enabled( $is_enabled ) {
		if ( ! self::is_enabled() ) {
			return $is_enabled;
		}

		return true;
	}

	public static function provide_mock_garden_config( $value, $key ) {
		if ( ! self::is_enabled() || 'plan_info' !== $key ) {
			return $value;
		}

		return wp_json_encode(
			array(
				'stored_details_id' => self::E2E_PAYMENT_METHOD_ID,
			)
		);
	}

	public static function hide_wcshipping_nux_banner_styles() {
		if ( ! self::is_enabled() ) {
			return;
		}
		?>
		<style id="wcshipping-e2e-hide-nux-banner">
			.notice.wcshipping-nux__notice {
				display: none !important;
			}
		</style>
		<?php
	}
}
