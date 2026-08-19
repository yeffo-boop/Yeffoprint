<?php
namespace Automattic\WCShipping\Connect;

use Automattic\WCShipping\DOM\Manipulation as DOM_Manipulation;
use Automattic\WCShipping\FeatureFlags\FeatureFlags;
use Automattic\WCShipping\OriginAddresses\OriginAddressService;
use Automattic\WCShipping\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Connect_Settings_Pages {
	/**
	 * @var WC_Connect_Service_Schemas_Store
	 */
	protected $service_schemas_store;

	/**
	 * @var WC_Connect_Continents
	 */
	protected $continents;

	/**
	 * @var WC_Connect_API_Client
	 */
	protected $api_client;

	/**
	 * @var WC_Connect_Account_Settings
	 */
	protected $account_settings;

	/**
	 * @var OriginAddressService
	 */
	protected $origin_address_service;

	protected string $id;
	protected string $label;

	public function __construct(
		WC_Connect_API_Client $api_client,
		WC_Connect_Service_Schemas_Store $service_schemas_store,
		OriginAddressService $origin_address_service,
		WC_Connect_Service_Settings_Store $settings_store,
		WC_Connect_Payment_Methods_Store $payment_methods_store
	) {
		$this->id                     = 'connect';
		$this->label                  = _x( 'WooCommerce Shipping', 'The WooCommerce Shipping brandname', 'woocommerce-shipping' );
		$this->continents             = new WC_Connect_Continents();
		$this->api_client             = $api_client;
		$this->service_schemas_store  = $service_schemas_store;
		$this->origin_address_service = $origin_address_service;
		$this->account_settings       = new WC_Connect_Account_Settings(
			$settings_store,
			$payment_methods_store
		);

		self::register_wc_section();
		add_action( 'wcshipping_render_wc_settings_page', array( $this, 'output_shipping_settings_screen' ) );
	}

	/**
	 * Register WooCommerce settings section
	 *
	 * This class is meant to handle the settings page after a successful connection exists,
	 * but we also want to be able to use it to prompt merchants to e.g. connect their site
	 * to WPCOM or flag issues preventing them from using WC Shipping.
	 * So this method exists to be able to trigger a consistent
	 *
	 * @return void
	 */
	public static function register_wc_section() {
		add_filter( 'woocommerce_get_sections_shipping', array( self::class, 'get_sections' ), 30 );
		add_action( 'woocommerce_settings_shipping', array( self::class, 'output_settings_screen' ), 5 );
	}

	/**
	 * Get sections.
	 *
	 * @return array
	 */
	public static function get_sections( $shipping_tabs ) {
		if ( ! is_array( $shipping_tabs ) ) {
			$shipping_tabs = array();
		}

		$shipping_tabs['woocommerce-shipping-settings'] = __( 'WooCommerce Shipping', 'woocommerce-shipping' );
		return $shipping_tabs;
	}

	/**
	 * Output the settings.
	 */
	public static function output_settings_screen() {
		global $current_section;

		if ( 'woocommerce-shipping-settings' !== $current_section ) {
			return;
		}

		add_filter( 'woocommerce_get_settings_shipping', '__return_empty_array' );

		/**
		 * Determine which render callback to use for the settings page
		 *
		 * @since $$next-version$$
		 */
		do_action( 'wcshipping_render_wc_settings_page' );
	}

	/**
	 * Localizes the bootstrap, enqueues the script and styles for the settings page
	 */
	public function output_shipping_settings_screen() {
		// hiding the save button because the react container has its own.
		global $hide_save_button;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce global.
		$hide_save_button = true;

		if ( WC_Connect_Jetpack::is_offline_mode() ) {
			if ( WC_Connect_Jetpack::is_active() ) {
				$message = __( 'Note: Jetpack is connected, but development mode is also enabled on this site. Please disable development mode.', 'woocommerce-shipping' );
			} else {
				$message = __( 'Note: Jetpack development mode is enabled on this site. This site will not be able to obtain payment methods from WooCommerce Shipping production servers.', 'woocommerce-shipping' );
			}
			?>
				<div class="wc-connect-admin-dev-notice">
					<p>
					<?php echo esc_html( $message ); ?>
					</p>
				</div>
			<?php
		}

		$extra_args = array();

		$carriers_response = $this->api_client->get_carrier_accounts();

		if ( ! is_wp_error( $carriers_response ) && ! empty( $carriers_response->carriers ) ) {
			$extra_args['carrier_accounts'] = $carriers_response->carriers;
		}

		// check the helper auth before calling wccom subscription api.
		if ( ! is_wp_error( WC_Connect_Functions::get_wc_helper_auth_info() ) ) {
			$subscriptions_usage_response = $this->api_client->get_wccom_subscriptions();

			if ( ! is_wp_error( $subscriptions_usage_response ) && ! empty( $subscriptions_usage_response->subscriptions ) ) {
				$extra_args['subscriptions'] = $subscriptions_usage_response->subscriptions;
			}
		}

		$extra_args['nonce'] = wp_create_nonce( 'wp_rest' );
		$origin_addresses    = $this->origin_address_service->get_origin_addresses();
		if ( count( $origin_addresses ) === 1 ) {
			$origin_addresses[0]['default_address'] = true;
		}

		$extra_args['origin_addresses']   = $origin_addresses;
		$extra_args['continents']         = $this->continents->get();
		$extra_args['constants']          = Utils::get_constants_for_js();
		$extra_args['accountSettings']    = $this->account_settings->get();
		$extra_args['carrier_strategies'] = array(
			'upsdap' => array(
				'origin_address' => array(),
			),
		);
		$extra_args['scanFormEnabled']    = FeatureFlags::is_scanform_enabled();

		DOM_Manipulation::create_root_script_element( 'woocommerce-shipping-settings' );

		do_action( 'wcshipping_enqueue_script', 'woocommerce-shipping-settings', $extra_args );
	}
}
