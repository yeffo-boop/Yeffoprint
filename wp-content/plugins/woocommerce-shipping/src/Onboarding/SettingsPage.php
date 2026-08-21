<?php
/**
 * Class SettingsPage.
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\Onboarding;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WCShipping\Connect\WC_Connect_Jetpack;
use Automattic\WCShipping\Connect\WC_Connect_Service_Settings_Store;
use Automattic\WCShipping\Connect\WC_Connect_Settings_Pages;
use Automattic\WCShipping\DOM\Manipulation as DOM_Manipulation;
use Automattic\WCShipping\LabelPurchase\ViewService;

/**
 * Class SettingsPage.
 */
class SettingsPage {

	/**
	 * WC Shipping setting store.
	 *
	 * @var WC_Connect_Service_Settings_Store
	 */
	protected $service_settings_store;

	/**
	 * View service that combines a lot of internal dependencies.
	 *
	 * @var ViewService
	 */
	protected $view_service;

	/**
	 * Constructor
	 *
	 * @param WC_Connect_Service_Settings_Store $service_settings_store WC Shipping setting store.
	 * @param ViewService                       $view_service View service that combines a lot of internal dependencies.
	 * @return void
	 */
	public function __construct( WC_Connect_Service_Settings_Store $service_settings_store, ViewService $view_service ) {
		$this->service_settings_store = $service_settings_store;
		$this->view_service           = $view_service;
	}

	/**
	 * Register hooks used to initiate the onboarding settings page
	 *
	 * @return void
	 */
	public function register_hooks() {
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-settings-pages.php';
		WC_Connect_Settings_Pages::register_wc_section();
		add_action( 'wcshipping_render_wc_settings_page', array( $this, 'section_content' ) );
	}

	/**
	 * Register settings page section content
	 *
	 * @return void
	 */
	public function section_content() {
		// Hiding the normal WC Settings section save button because we render an independent React app.
		global $hide_save_button;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce global.
		$hide_save_button = true;

		printf(
			'<h2>%s</h2>',
			esc_html_x( 'WooCommerce Shipping', 'The WooCommerce Shipping brandname', 'woocommerce-shipping' )
		);

		DOM_Manipulation::create_root_script_element( 'woocommerce-shipping-onboarding' );

		$store_country_code = wc_get_base_location()['country'];
		$full_country_name  = isset( WC()->countries->countries[ $store_country_code ] ) ? WC()->countries->countries[ $store_country_code ] : '';
		$currency           = get_woocommerce_currency();

		do_action(
			'wcshipping_enqueue_script',
			'woocommerce-shipping-onboarding',
			array(
				'authReturnUrl'       => $this->get_wpcom_connection_redirect_url(),
				'isCountrySupported'  => $this->view_service->is_supported_country( $store_country_code ),
				'storeCountryName'    => $full_country_name,
				'isCurrencySupported' => $this->view_service->is_supported_currency( $currency ),
				'storeCurrency'       => $currency,
				'onboardingState'     => WC_Connect_Jetpack::is_connected() ? 'needs_tos_only' : 'needs_connection',
			)
		);
	}

	/**
	 * Get redirect location after WPCOM authorization
	 *
	 * @return string
	 */
	private function get_wpcom_connection_redirect_url() {
		return rawurldecode(
			add_query_arg(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'shipping',
					'section' => 'woocommerce-shipping-settings',
				),
				admin_url( 'admin.php' )
			)
		);
	}
}
