<?php

namespace Automattic\WCShipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WCShipping\Carrier\FedEx\FedExCarrierStrategyRESTController;
use Automattic\WCShipping\Carrier\FedEx\FedExCarrierStrategyService;
use Automattic\WCShipping\Carrier\FedEx\FedExTosErrorInterceptor;
use Automattic\WCShipping\Carrier\UPSDAP\UPSDAPCarrierStrategyRESTController;
use Automattic\WCShipping\Carrier\UPSDAP\UPSDAPCarrierStrategyService;
use Automattic\WCShipping\Checkout\CheckoutController;
use Automattic\WCShipping\Checkout\CheckoutService;
use Automattic\WCShipping\Bulk\BatchableApiClient;
use Automattic\WCShipping\Banners\BulkLabelsBanner;
use Automattic\WCShipping\Connect\WC_Connect_API_Client;
use Automattic\WCShipping\Connect\WC_Connect_Debug_Tools;
use Automattic\WCShipping\Connect\WC_Connect_Error_Notice;
use Automattic\WCShipping\Connect\WC_Connect_Extension_Compatibility;
use Automattic\WCShipping\Connect\WC_Connect_Help_View;
use Automattic\WCShipping\Connect\WC_Connect_Jetpack;
use Automattic\WCShipping\Connect\WC_Connect_Logger;
use Automattic\WCShipping\Connect\WC_Connect_Nux;
use Automattic\WCShipping\Connect\WC_Connect_Options;
use Automattic\WCShipping\Connect\WC_Connect_Package_Settings;
use Automattic\WCShipping\Connect\WC_Connect_Payment_Methods_Store;
use Automattic\WCShipping\Connect\WC_Connect_Privacy;
use Automattic\WCShipping\Connect\WC_Connect_Service_Schemas_Store;
use Automattic\WCShipping\Connect\WC_Connect_Service_Schemas_Validator;
use Automattic\WCShipping\Connect\WC_Connect_Service_Settings_Store;
use Automattic\WCShipping\ScanForm\ScanFormHistoryRESTController;
use Automattic\WCShipping\ScanForm\ScanFormService;
use Automattic\WCShipping\ScanForm\ScanForm;
use Automattic\WCShipping\Connect\WC_Connect_Settings_Pages;
use Automattic\WCShipping\Connect\WC_Connect_Shipping_Label;
use Automattic\WCShipping\Connect\WC_Connect_Account_Settings;
use Automattic\WCShipping\FeatureFlags\FeatureFlags;
use Automattic\WCShipping\Integrations\AssetsRESTController;
use Automattic\WCShipping\Integrations\ConfigRESTController;
use Automattic\WCShipping\Integrations\TosRESTController;
use Automattic\WCShipping\Integrations\WooCommerceBlocksIntegration;
use Automattic\WCShipping\Integrations\WooCommerceShipmentTracking;
use Automattic\WCShipping\LabelPurchase\AddressNormalizationService;
use Automattic\WCShipping\LabelPurchase\AddressRESTController;
use Automattic\WCShipping\LabelPurchase\LabelPreviewRESTController;
use Automattic\WCShipping\LabelPurchase\LabelPrintController;
use Automattic\WCShipping\LabelPurchase\LabelPrintService;
use Automattic\WCShipping\LabelPurchase\LabelPurchaseRESTController;
use Automattic\WCShipping\LabelPurchase\LabelPurchaseService;
use Automattic\WCShipping\LabelPurchase\OrdersShippingContextRESTController;
use Automattic\WCShipping\LabelPurchase\LabelRefundRESTController;
use Automattic\WCShipping\LabelPurchase\LabelStatusController;
use Automattic\WCShipping\LabelPurchase\View;
use Automattic\WCShipping\LabelPurchase\ViewService;
use Automattic\WCShipping\LabelRate\LabelRateRESTController;
use Automattic\WCShipping\LabelRate\LabelRateService;
use Automattic\WCShipping\LabelSettings\AccountSettingsRestController;
use Automattic\WCShipping\LabelSettings\SelfHelpRestController;
use Automattic\WCShipping\LabelSettings\ServiceDataRefreshRestController;
use Automattic\WCShipping\ServiceData\ServicesErrorNotice;
use Automattic\WCShipping\ServiceData\ServiceSchemasFetcherService;
use Automattic\WCShipping\ServiceData\ServiceStatusRESTController;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Account_Settings_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Address_Normalization_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Assets_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Packages_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Self_Help_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Service_Data_Refresh_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Services_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Shipping_Carrier_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Shipping_Carrier_Delete_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Shipping_Carrier_Types_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Shipping_Carriers_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Shipping_Label_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Shipping_Label_Preview_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Shipping_Label_Print_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Shipping_Label_Refund_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Shipping_Label_Status_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Shipping_Rates_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Subscription_Activate_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Subscriptions_Controller;
use Automattic\WCShipping\LegacyAPIControllers\WC_REST_Connect_Tos_Controller;
use Automattic\WCShipping\Migration\LegacyLabelMigrator;
use Automattic\WCShipping\Migration\LegacySettingsMigrator;
use Automattic\WCShipping\Migration\MigrationController;
use Automattic\WCShipping\Migration\MigrationNotices;
use Automattic\WCShipping\Migration\MigrationState;
use Automattic\WCShipping\Onboarding\SettingsPage;
use Automattic\WCShipping\OriginAddresses\OriginAddressService;
use Automattic\WCShipping\PackageAssignment\PackageAssignmentRESTController;
use Automattic\WCShipping\PackageAssignment\PackageAssignmentService;
use Automattic\WCShipping\Packages\PackagesRESTController;
use Automattic\WCShipping\Shipments\ShipmentsRESTController;
use Automattic\WCShipping\ScanForm\ScanFormRESTController;
use Automattic\WCShipping\Shipments\ShipmentsService;
use Automattic\WCShipping\StoreApi\Extensions\BlocksCheckoutAddressValidationExtension;
use Automattic\WCShipping\StoreApi\StoreApiExtendSchema;
use Automattic\WCShipping\StoreApi\StoreApiExtensionController;
use Automattic\WCShipping\Testing\WCConnectE2EAPIClientMock;
use Automattic\WCShipping\Testing\WCConnectE2EConnectionShim;
use Automattic\WCShipping\Utils as WCShippingUtils;
use Automattic\WCShipping\WPCOMConnection\WPCOMConnectionRESTController;
use Automattic\WCShipping\WCShippingRESTController;
use Automattic\WCShipping\Analytics\ShippingLabel;
use Automattic\WCShipping\Analytics\ShippingLabelRESTController;
use Automattic\WCShipping\Analytics\LabelsService;
use Automattic\WCShipping\Abilities\Abilities;
use Automattic\WCShipping\Banners\Banners;
use Automattic\WCShipping\LabelPurchase\EligibilityRESTController;
use Automattic\WCShipping\Promo\PromoRESTController;
use Automattic\WCShipping\Promo\PromoService;
use Automattic\WCShipping\Fulfillments\FulfillmentsService;
use Automattic\WCShipping\Fulfillments\ShippingFulfillmentsDataStore;
use Automattic\WCShipping\RestApi\Routes\V2\Addresses\Controller as AddressesV2Controller;

use Exception;
use WC_Data_Store;
use WC_Logger;
use WC_Order;
use WC_Shipping_Zones;
use WP_HTTP_Response;
use WP_Post;
use WP_REST_Request;
use WP_REST_Server;

class Loader {

	/**
	 * @var WC_Connect_Logger
	 */
	protected $logger;

	/**
	 * @var WC_Connect_Logger
	 */
	protected $shipping_logger;

	/**
	 * @var WC_Connect_API_Client
	 */
	protected $api_client;

	/**
	 * @var WC_Connect_Service_Schemas_Store
	 */
	protected $service_schemas_store;

	/**
	 * @var ServiceSchemasFetcherService
	 */
	protected $service_schemas_fetcher;

	/**
	 * @var WC_Connect_Service_Settings_Store
	 */
	protected $service_settings_store;

	/**
	 * @var WC_Connect_Payment_Methods_Store
	 */
	protected $payment_methods_store;

	/**
	 * @var WC_REST_Connect_Account_Settings_Controller
	 */
	protected $rest_account_settings_controller;

	/**
	 * @var WC_REST_Connect_Packages_Controller
	 */
	protected $rest_packages_controller;

	/**
	 * @var WC_REST_Connect_Services_Controller
	 */
	protected $rest_services_controller;

	/**
	 * @var WC_REST_Connect_Self_Help_Controller
	 */
	protected $rest_self_help_controller;

	/**
	 * @var WC_REST_Connect_Shipping_Label_Controller
	 */
	protected $rest_shipping_label_controller;

	/**
	 * @var WC_REST_Connect_Shipping_Label_Status_Controller
	 */
	protected $rest_shipping_label_status_controller;

	/**
	 * @var WC_REST_Connect_Shipping_Label_Refund_Controller
	 */
	protected $rest_shipping_label_refund_controller;

	/**
	 * @var WC_REST_Connect_Shipping_Label_Preview_Controller
	 */
	protected $rest_shipping_label_preview_controller;

	/**
	 * @var WC_REST_Connect_Shipping_Label_Print_Controller
	 */
	protected $rest_shipping_label_print_controller;

	/**
	 * @var WC_REST_Connect_Shipping_Rates_Controller
	 */
	protected $rest_shipping_rates_controller;

	/**
	 * @var WC_REST_Connect_Address_Normalization_Controller
	 */
	protected $rest_address_normalization_controller;

	/**
	 *
	 * WC_REST_Connect_Shipping_Carrier_Types_Controller
	 *
	 * @var WC_REST_Connect_Shipping_Carrier_Types_Controller
	 */
	protected $rest_carrier_types_controller;

	/**
	 * @var WC_Connect_Service_Schemas_Validator
	 */
	protected $service_schemas_validator;

	/**
	 * @var WC_Connect_Settings_Pages
	 */
	protected $settings_pages;

	/**
	 * @var WC_Connect_Help_View
	 */
	protected $help_view;

	/**
	 * @var View
	 */
	protected $shipping_label;

	/**
	 * @var WC_Connect_Shipping_Label
	 */
	protected $legacy_shipping_label;

	/**
	 * @var WC_Connect_Nux
	 */
	protected $nux;

	/**
	 * @var Banners
	 */
	protected $feature_banners;

	/**
	 * @var WC_REST_Connect_Tos_Controller
	 */
	protected $rest_tos_controller;

	/**
	 * @var LabelRateService
	 */
	protected $label_rate_service;

	/**
	 * @var PromoService
	 */
	protected $promo_service;

	/**
	 * @var ?FulfillmentsService
	 */
	protected ?FulfillmentsService $fulfillments_service = null;

	/**
	 * @var ?LabelPurchaseService
	 */
	protected ?LabelPurchaseService $label_purchase_service = null;

	/**
	 * @var MigrationController
	 */
	protected $migration_controller;

	/**
	 * @var WC_REST_Connect_Assets_Controller
	 */
	protected $rest_assets_controller;

	/**
	 * @var WC_REST_Connect_Subscriptions_Controller
	 */
	protected $rest_subscriptions_controller;

	/**
	 * @var WC_REST_Connect_Shipping_Carriers_Controller
	 */
	protected $rest_carriers_controller;

	/**
	 * @var WC_REST_Connect_Subscription_Activate_Controller
	 */
	protected $rest_subscription_activate_controller;

	/**
	 * @var WC_REST_Connect_Shipping_Carrier_Controller
	 */
	protected $rest_carrier_controller;

	/**
	 * @var WC_REST_Connect_Shipping_Carrier_Delete_Controller
	 */
	protected $rest_carrier_delete_controller;

	protected $services = array();

	protected $service_object_cache = array();

	protected $wc_connect_base_url;

	/**
	 * @var AddressNormalizationService
	 */
	protected $address_normalization_service;

	/**
	 * @var ViewService
	 */
	protected $view_service;

	/**
	 * @var CheckoutService
	 */
	protected CheckoutService $checkout_service;

	/**
	 * @var FedExCarrierStrategyService
	 */
	private $fedex_carrier_strategy_service;

	/**
	 * @var UPSDAPCarrierStrategyService
	 */
	protected $upsdap_carrier_strategy_service;

	/**
	 * Shipping fulfillments data store instance.
	 *
	 * @var ShippingFulfillmentsDataStore
	 */
	protected $shipping_fulfillments_data_store;

	/**
	 * Account settings instance.
	 *
	 * @var WC_Connect_Account_Settings
	 */
	protected WC_Connect_Account_Settings $account_settings;

	/**
	 * Origin address service instance.
	 *
	 * @var OriginAddressService
	 */
	protected OriginAddressService $origin_address_service;

	/**
	 * Plugin deactivation hook.
	 */
	public static function plugin_deactivation() {
		wp_clear_scheduled_hook( 'wcshipping_fetch_service_schemas' );

		// @todo Something with our load order is messing with our action hook, so we're
		// initiating "Tracks" directly to ensure that our hook(s) will be caught.
		Tracks::init();

		/**
		 * Action hook for when the plugin is deactivated.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wcshipping_plugin_deactivation' );
	}

	/**
	 * Plugin activation hook.
	 */
	public static function plugin_activation() {
		// @todo Something with our load order is messing with our action hook, so we're
		// initiating "Tracks" directly to ensure that our hook(s) will be caught.
		Tracks::init();

		// We need to support data migration from WCS&T if the plugin is activated "manually"
		// aka without the use of the WCS&T Migration flow.
		// This is to allow our migration banners to be displayed, even if WC Shipping was installed manually.
		if ( ! MigrationState::get_state() || MigrationState::get_state() < MigrationState::INSTALLATION_COMPLETED ) {
			MigrationState::set_state( MigrationState::INSTALLATION_COMPLETED );
		}

		/**
		 * Action hook for when the plugin is activated.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wcshipping_plugin_activation' );
	}

	public static function plugin_uninstall() {
		WC_Connect_Options::delete_all_options();
		self::delete_notices();
	}

	/**
	 * Update DB version and fire the updated action if plugin was updated.
	 */
	public static function maybe_plugin_updated(): void {
		$current_version = get_option( 'wcshipping_version', '1.0.0' );

		if ( version_compare( $current_version, WCSHIPPING_VERSION, '<' ) ) {
			update_option( 'wcshipping_version', WCSHIPPING_VERSION, false );

			// If the plugin was updated from a version that did not have migration support, we want to offer migration from this point on.
			if ( ! MigrationState::get_state() ) {
				MigrationState::set_state( MigrationState::INSTALLATION_COMPLETED );
			}

			/**
			 * Action triggered when the wcshipping plugin is updated.
			 *
			 * @since 1.1.0
			 *
			 * @param string $current_version The old version of the plugin.
			 * @param string $new_version The new version of the plugin.
			 */
			do_action( 'wcshipping_updated', $current_version, WCSHIPPING_VERSION );
		}
	}

	/**
	 * Deletes WC Admin notices.
	 */
	public static function delete_notices() {
	}

	/**
	 * Checks if WC Admin is active and includes needed classes.
	 *
	 * @return bool true|false.
	 */
	public static function can_add_wc_admin_notice() {
		if ( ! class_exists( 'WC_Data_Store' ) ) {
			return false;
		}

		try {
			WC_Data_Store::load( 'admin-note' );
		} catch ( Exception $e ) {
			return false;
		}

		return trait_exists( '\Automattic\WooCommerce\Admin\Notes\NoteTraits' ) && class_exists( '\Automattic\WooCommerce\Admin\Notes\Note' );
	}

	/**
	 * Get base url.
	 *
	 * @return string
	 */
	private static function get_wc_connect_base_url() {
		return WCShippingUtils::get_enqueue_base_url();
	}

	/**
	 * Get WCS admin script url.
	 *
	 * @return string
	 */
	public static function get_wcs_admin_script_url() {
		return self::get_wc_connect_base_url() . 'woocommerce-shipping-create-shipping-label.js';
	}

	public static function get_wcs_shipment_tracking_script_url() {
		return self::get_wc_connect_base_url() . 'woocommerce-shipping-shipment-tracking.js';
	}

	/**
	 * Get WCS admin css url.
	 *
	 * @return string
	 */
	public static function get_wcs_admin_style_url() {
		return self::get_wc_connect_base_url() . 'style-woocommerce-shipping-create-shipping-label.css';
	}

	public static function get_wcs_shipment_tracking_style_url() {
		return self::get_wc_connect_base_url() . 'style-woocommerce-shipping-shipment-tracking.css';
	}

	public function wpcom_static_url( $file ) {
		$i   = hexdec( substr( md5( $file ), - 1 ) ) % 2;
		$url = 'http://s' . $i . '.wp.com' . $file;

		return set_url_scheme( $url );
	}

	public function __construct() {
		$this->wc_connect_base_url = self::get_wc_connect_base_url();

		add_action(
			'before_woocommerce_init',
			function () {
				if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', 'woocommerce-shipping/woocommerce-shipping.php' );
				}
			}
		);

		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		add_action( 'init', array( $this, 'jetpack_on_plugins_loaded' ), 1 );
	}

	public function get_logger() {
		return $this->logger;
	}

	public function set_logger( WC_Connect_Logger $logger ) {
		$this->logger = $logger;
	}

	public function get_shipping_logger() {
		return $this->shipping_logger;
	}

	public function set_shipping_logger( WC_Connect_Logger $logger ) {
		$this->shipping_logger = $logger;
	}

	public function get_api_client() {
		return $this->api_client;
	}

	public function set_api_client( WC_Connect_API_Client $api_client ) {
		$this->api_client = $api_client;
	}

	public function get_service_schemas_store() {
		return $this->service_schemas_store;
	}

	public function set_service_schemas_store( WC_Connect_Service_Schemas_Store $schemas_store ) {
		$this->service_schemas_store = $schemas_store;
	}

	public function get_service_settings_store() {
		return $this->service_settings_store;
	}

	public function set_service_settings_store( WC_Connect_Service_Settings_Store $settings_store ) {
		$this->service_settings_store = $settings_store;
	}

	public function get_payment_methods_store() {
		return $this->payment_methods_store;
	}

	public function set_payment_methods_store( WC_Connect_Payment_Methods_Store $payment_methods_store ) {
		$this->payment_methods_store = $payment_methods_store;
	}

	public function get_rest_account_settings_controller() {
		return $this->rest_account_settings_controller;
	}

	public function set_rest_tos_controller( WC_REST_Connect_Tos_Controller $rest_tos_controller ) {
		$this->rest_tos_controller = $rest_tos_controller;
	}

	public function set_rest_assets_controller( WC_REST_Connect_Assets_Controller $rest_assets_controller ) {
		$this->rest_assets_controller = $rest_assets_controller;
	}

	public function set_rest_carriers_controller( WC_REST_Connect_Shipping_Carriers_Controller $rest_carriers_controller ) {
		$this->rest_carriers_controller = $rest_carriers_controller;
	}

	public function set_rest_subscriptions_controller( WC_REST_Connect_Subscriptions_Controller $rest_subscriptions_controller ) {
		$this->rest_subscriptions_controller = $rest_subscriptions_controller;
	}

	public function set_rest_subscription_activate_controller( WC_REST_Connect_Subscription_Activate_Controller $rest_subscription_activate_controller ) {
		$this->rest_subscription_activate_controller = $rest_subscription_activate_controller;
	}

	public function set_rest_carrier_controller( WC_REST_Connect_Shipping_Carrier_Controller $rest_carrier_controller ) {
		$this->rest_carrier_controller = $rest_carrier_controller;
	}

	public function set_rest_carrier_delete_controller( WC_REST_Connect_Shipping_Carrier_Delete_Controller $rest_carrier_delete_controller ) {
		$this->rest_carrier_delete_controller = $rest_carrier_delete_controller;
	}

	public function set_rest_packages_controller( WC_REST_Connect_Packages_Controller $rest_packages_controller ) {
		$this->rest_packages_controller = $rest_packages_controller;
	}

	public function set_rest_account_settings_controller( WC_REST_Connect_Account_Settings_Controller $rest_account_settings_controller ) {
		$this->rest_account_settings_controller = $rest_account_settings_controller;
	}

	public function get_rest_services_controller() {
		return $this->rest_services_controller;
	}

	public function set_rest_services_controller( WC_REST_Connect_Services_Controller $rest_services_controller ) {
		$this->rest_services_controller = $rest_services_controller;
	}

	public function get_rest_self_help_controller() {
		return $this->rest_self_help_controller;
	}

	public function set_rest_self_help_controller( WC_REST_Connect_Self_Help_Controller $rest_self_help_controller ) {
		$this->rest_self_help_controller = $rest_self_help_controller;
	}

	public function get_rest_shipping_label_controller() {
		return $this->rest_shipping_label_controller;
	}

	public function set_rest_shipping_label_controller( WC_REST_Connect_Shipping_Label_Controller $rest_shipping_label_controller ) {
		$this->rest_shipping_label_controller = $rest_shipping_label_controller;
	}

	public function get_rest_shipping_label_status_controller() {
		return $this->rest_shipping_label_status_controller;
	}

	public function set_rest_shipping_label_status_controller( WC_REST_Connect_Shipping_Label_Status_Controller $rest_shipping_label_status_controller ) {
		$this->rest_shipping_label_status_controller = $rest_shipping_label_status_controller;
	}

	public function get_rest_shipping_label_refund_controller() {
		return $this->rest_shipping_label_refund_controller;
	}

	public function set_rest_shipping_label_refund_controller( WC_REST_Connect_Shipping_Label_Refund_Controller $rest_shipping_label_refund_controller ) {
		$this->rest_shipping_label_refund_controller = $rest_shipping_label_refund_controller;
	}

	public function get_rest_shipping_label_preview_controller() {
		return $this->rest_shipping_label_preview_controller;
	}

	public function set_rest_shipping_label_preview_controller( WC_REST_Connect_Shipping_Label_Preview_Controller $rest_shipping_label_preview_controller ) {
		$this->rest_shipping_label_preview_controller = $rest_shipping_label_preview_controller;
	}

	public function get_rest_shipping_label_print_controller() {
		return $this->rest_shipping_label_print_controller;
	}

	public function set_rest_shipping_label_print_controller( WC_REST_Connect_Shipping_Label_Print_Controller $rest_shipping_label_print_controller ) {
		$this->rest_shipping_label_print_controller = $rest_shipping_label_print_controller;
	}

	public function set_rest_shipping_rates_controller( WC_REST_Connect_Shipping_Rates_Controller $rest_shipping_rates_controller ) {
		$this->rest_shipping_rates_controller = $rest_shipping_rates_controller;
	}

	public function set_rest_address_normalization_controller( WC_REST_Connect_Address_Normalization_Controller $rest_address_normalization_controller ) {
		$this->rest_address_normalization_controller = $rest_address_normalization_controller;
	}

	public function set_carrier_types_controller( WC_REST_Connect_Shipping_Carrier_Types_Controller $rest_carrier_types_controller ) {
		$this->rest_carrier_types_controller = $rest_carrier_types_controller;
	}

	public function get_carrier_types_controller() {
		return $this->rest_carrier_types_controller;
	}

	public function get_service_schemas_validator() {
		return $this->service_schemas_validator;
	}

	public function set_service_schemas_validator( WC_Connect_Service_Schemas_Validator $validator ) {
		$this->service_schemas_validator = $validator;
	}

	public function get_settings_pages() {
		return $this->settings_pages;
	}

	public function set_settings_pages( WC_Connect_Settings_Pages $settings_pages ) {
		$this->settings_pages = $settings_pages;
	}

	public function get_help_view() {
		return $this->help_view;
	}

	public function set_help_view( WC_Connect_Help_View $help_view ) {
		$this->help_view = $help_view;
	}

	public function set_shipping_label( View $shipping_label ) {
		$this->shipping_label = $shipping_label;
	}

	public function get_shipping_label() {
		return $this->shipping_label;
	}

	public function set_legacy_shipping_label( WC_Connect_Shipping_Label $legacy_shipping_label ) {
		$this->legacy_shipping_label = $legacy_shipping_label;
	}

	public function set_nux( WC_Connect_Nux $nux ) {
		$this->nux = $nux;
	}

	public function set_feature_banners( Banners $feature_banners ) {
		$this->feature_banners = $feature_banners;
	}

	public function get_feature_banners() {
		return $this->feature_banners;
	}

	/**
	 * Get the checkout service instance.
	 *
	 * @return CheckoutService
	 */
	public function get_checkout_service(): CheckoutService {
		return $this->checkout_service;
	}

	/**
	 * Set the checkout service instance.
	 *
	 * @param CheckoutService $checkout_service The checkout service instance.
	 */
	public function set_checkout_service( CheckoutService $checkout_service ) {
		$this->checkout_service = $checkout_service;
	}


	public function on_plugins_loaded() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					/* translators: %s WC download URL link. */
					echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'WooCommerce Shipping requires the WooCommerce plugin to be installed and active. You can download %s here.', 'woocommerce-shipping' ), '<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
				}
			);

			return;
		}

		if (
			in_array( 'woocommerce-services/woocommerce-services.php', get_option( 'active_plugins' ) )
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WCS&T filter.
			&& ! apply_filters( 'wc_services_will_handle_coexistence_with_woo_shipping_and_woo_tax', false )
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WCS&T filter.
			&& ! apply_filters( 'wc_services_will_disable_shipping_logic', false )
		) {
			// Show informative message.
			add_action(
				'admin_notices',
				function () {
					echo '<div class="error"><p><strong>' . wp_kses( 'Please update the WooCommerce Shipping & Tax plugin to the latest version to ensure compatibility with WooCommerce Shipping.', 'woocommerce-shipping' ) . '</strong></p></div>';
				}
			);

			// Bail, so none of our shipping code will be initiated to avoid conflicts with older versions of WCS&T.
			return;
		}

		Abilities::init();

		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_integration' ) );
		add_action( 'after_plugin_row_woocommerce-services/woocommerce-services.php', array( $this, 'add_custom_message_to_wcst_plugin_list_entry' ), 10, 2 );
		add_action( 'before_woocommerce_init', array( $this, 'pre_wc_init' ) );
	}

	/**
	 * Deactivates the WooCommerce Shipping & Tax plugin.
	 *
	 * @return void
	 */
	public function deactivate_wcst() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		check_admin_referer( 'action' );

		deactivate_plugins( 'woocommerce-services/woocommerce-services.php' );

		wp_safe_redirect( wp_get_referer() );
		exit;
	}

	/**
	 * Register the WooCommerceBlocks integration.
	 */
	public function register_blocks_integration() {
		add_action(
			'woocommerce_blocks_checkout_block_registration',
			function ( $integration_registry ) {
				$integration_registry->register( new WooCommerceBlocksIntegration() );
			}
		);
	}

	/**
	 * Perform plugin bootstrapping that needs to happen before WC init.
	 *
	 * This allows the modification of extensions, integrations, etc.
	 */
	public function pre_wc_init() {
		$this->load_dependencies();

		// Set up feature flag support.
		( new FeatureFlags() )->register_hooks();

		// Add settings and docs links to the plugin page.
		add_action( 'plugin_action_links_' . plugin_basename( WCSHIPPING_PLUGIN_FILE ), array( $this, 'add_plugin_action_links' ) );
		add_action( 'plugin_row_meta', array( $this, 'add_plugin_description_links' ), 10, 2 );

		$tos_accepted                        = WC_Connect_Options::get_option( 'tos_accepted' );
		$wcshipping_e2e_mock_connect_enabled =
			class_exists( WCConnectE2EConnectionShim::class ) &&
			WCConnectE2EConnectionShim::is_enabled();

		// Prevent presenting users with TOS they've already
		// accepted in the core WC Setup Wizard or on WP.com.
		if ( ! $tos_accepted && WC_Connect_Jetpack::is_atomic_site() ) {
			WC_Connect_Options::update_option( 'tos_accepted', true );

			$tos_accepted = true;
		}

		if ( ! $tos_accepted && $wcshipping_e2e_mock_connect_enabled ) {
			WC_Connect_Options::update_option( 'tos_accepted', true );
			$tos_accepted = true;
		}

		// Handle the dismiss action for the after-connection banner early, before set_up_nux_notices.
		// This must happen before headers are sent so that wp_safe_redirect() can work.
		// Using priority 9 to run before set_up_nux_notices (default priority 10).
		add_action( 'admin_init', array( $this, 'maybe_dismiss_after_connection_banner' ), 9 );
		add_action( 'admin_init', array( $this->nux, 'set_up_nux_notices' ) );
		add_action( 'admin_init', array( $this, 'determine_migration_eligibility' ) );
		add_action( 'admin_init', array( $this, 'handle_migration_form_submission' ) );

		// Plugin should be enabled if dev mode or connected + TOS.
		$jetpack_status       = $this->nux->get_jetpack_install_status();
		$is_jetpack_connected = WC_Connect_Nux::JETPACK_CONNECTED === $jetpack_status;
		$is_jetpack_dev_mode  = WC_Connect_Nux::JETPACK_DEV === $jetpack_status;

		// We initiate our tracking early because there are several instances where we're allowed to track usage data
		// and our "record event" functionality will do on-demand checking for all of these scenarios.
		// Examples:
		// * A WPCOM Connection is already established by another service.
		// * WooCommerce Usage Tracking is enabled.
		Tracks::init();

		add_action( 'wcshipping_enqueue_script', array( $this, 'wcshipping_enqueue_script' ), 10, 2 );

		if ( ( ! $is_jetpack_connected || ! $tos_accepted ) && ! $is_jetpack_dev_mode ) {
			$this->init_onboarding_dependencies();

			return;
		}

		add_action( 'rest_api_init', array( $this, 'tos_rest_init' ) );
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );

		if ( ! $tos_accepted ) {
			return;
		}

		add_action( 'woocommerce_init', array( $this, 'after_wc_init' ) );
	}

	/**
	 * Initialise onboarding dependencies.
	 *
	 * @since $$next-version$$
	 *
	 * @return void
	 */
	public function init_onboarding_dependencies() {
		// Register settings page with basic onboarding instructions.
		$settings_page = new SettingsPage( $this->service_settings_store, $this->view_service );
		$settings_page->register_hooks();

		// Register WPCOM Connection API.
		$rest_controller = new WPCOMConnectionRESTController();
		add_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );
	}

	/**
	 * Add WC Shipping to the list of WPCOM dependent plugins
	 *
	 * @return void
	 */
	public function jetpack_on_plugins_loaded() {
		$jetpack_config = new \Automattic\Jetpack\Config();
		$jetpack_config->ensure(
			'connection',
			array(
				'slug' => WC_Connect_Jetpack::JETPACK_PLUGIN_SLUG,
				'name' => _x( 'WooCommerce Shipping', 'The WooCommerce Shipping brandname', 'woocommerce-shipping' ),
			)
		);
	}

	public function get_service_schema_defaults( $schema ) {
		$defaults = array();

		if ( ! property_exists( $schema, 'properties' ) ) {
			return $defaults;
		}

		foreach ( get_object_vars( $schema->properties ) as $prop_id => $prop_schema ) {
			if ( property_exists( $prop_schema, 'default' ) ) {
				$defaults[ $prop_id ] = $prop_schema->default;
			}

			if (
				property_exists( $prop_schema, 'type' ) &&
				'object' === $prop_schema->type
			) {
				$defaults[ $prop_id ] = $this->get_service_schema_defaults( $prop_schema );
			}
		}

		return $defaults;
	}

	public function save_defaults_to_shipping_method( $instance_id, $service_id, $zone_id ) {
		$shipping_method = WC_Shipping_Zones::get_shipping_method( $instance_id );
		$schema          = $shipping_method->get_service_schema();
		$defaults        = (object) $this->get_service_schema_defaults( $schema->service_settings );
		WC_Connect_Options::update_shipping_method_option( 'form_settings', $defaults, $service_id, $instance_id );
	}

	protected function add_method_to_shipping_zone( $zone_id, $method_id ) {
		$method = $this->get_service_schemas_store()->get_service_schema_by_id( $method_id );
		if ( empty( $method ) ) {
			return;
		}

		$zone        = WC_Shipping_Zones::get_zone( $zone_id );
		$instance_id = $zone->add_shipping_method( $method->method_id );
		$zone->save();
	}

	/**
	 * Bootstrap our plugin and hook into WP/WC core.
	 *
	 * @codeCoverageIgnore
	 */
	public function after_wc_init() {
		$this->schedule_service_schemas_fetch();
		$this->attach_hooks();
		$this->extend_checkout();
		$this->extend_store_api();
	}

	/**
	 * Extend WC Checkout.
	 */
	public function extend_checkout() {
		$this->set_checkout_service( new CheckoutService( $this->address_normalization_service ) );

		new CheckoutController( $this->get_logger(), $this->get_checkout_service(), $this->get_service_settings_store(), $this->address_normalization_service );
	}

	/**
	 * Extend the Store API.
	 */
	public function extend_store_api() {
		$store_api_extend_schema        = StoreApiExtendSchema::instance();
		$store_api_extension_controller = new StoreApiExtensionController( $store_api_extend_schema );

		// Register Store API extensions.
		$store_api_extension_controller->register_extension( new BlocksCheckoutAddressValidationExtension( $store_api_extend_schema, $this->get_checkout_service() ) );

		// Extend the Store API.
		$store_api_extension_controller->extend_store();
	}

	/**
	 * Load all plugin dependencies.
	 */
	public function load_dependencies() {
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-utils.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-logger.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-service-schemas-validator.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-error-notice.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-compatibility.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-service-schemas-store.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-service-settings-store.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-payment-methods-store.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-help-view.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-nux.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-privacy.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-account-settings.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-package-settings.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-continents.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-order-presenter.php';
		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-shipping-label.php';

		$core_logger     = new WC_Logger();
		$logger          = new WC_Connect_Logger( $core_logger );
		$shipping_logger = new WC_Connect_Logger( $core_logger, 'shipping' );

		$validator = new WC_Connect_Service_Schemas_Validator();

		$wcshipping_test_mode_enabled =
			defined( 'WOOCOMMERCE_SERVICES_LOCAL_TEST_MODE' ) &&
			WOOCOMMERCE_SERVICES_LOCAL_TEST_MODE;

		if ( $wcshipping_test_mode_enabled ) {
			$api_client = new WCConnectE2EAPIClientMock( $validator, $this );
		} else {
			require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-api-client-live.php';
			$api_client = new BatchableApiClient( $validator, $this );
		}
		$this->shipping_fulfillments_data_store = new ShippingFulfillmentsDataStore();
		$services_error_notice                  = null;
		if ( ! defined( 'NEXT_ADMIN_PLUGIN_DIR' ) ) {
			$services_error_notice = new ServicesErrorNotice();
			$services_error_notice->init();
		}
		$schemas_store                         = new WC_Connect_Service_Schemas_Store( $api_client, $logger );
		$this->service_schemas_fetcher         = new ServiceSchemasFetcherService( $api_client, $schemas_store, $services_error_notice );
		$settings_store                        = new WC_Connect_Service_Settings_Store( $schemas_store, $api_client, $logger, $this->shipping_fulfillments_data_store );
		$payment_methods_store                 = new WC_Connect_Payment_Methods_Store( $settings_store, $api_client, $logger );
		$this->account_settings                = new WC_Connect_Account_Settings( $settings_store, $payment_methods_store );
		$shipments_service                     = new ShipmentsService( $settings_store );
		$this->origin_address_service          = new OriginAddressService();
		$this->view_service                    = new ViewService( $this->account_settings, $schemas_store );
		$this->upsdap_carrier_strategy_service = new UPSDAPCarrierStrategyService( $api_client );
		$this->fedex_carrier_strategy_service  = new FedExCarrierStrategyService( $api_client );
		FedExTosErrorInterceptor::init();
		$promo_service                       = new PromoService( $schemas_store, $settings_store );
		$this->address_normalization_service = new AddressNormalizationService( $settings_store, $api_client, $logger, $this->origin_address_service );
		$this->fulfillments_service          = new FulfillmentsService( $this->shipping_fulfillments_data_store );
		$shipping_label                      = new View(
			$api_client,
			$settings_store,
			$schemas_store,
			$payment_methods_store,
			$shipments_service,
			$this->origin_address_service,
			$this->view_service,
			$this->account_settings,
			$promo_service,
			$this->address_normalization_service,
			$this->fulfillments_service,
			$this->shipping_fulfillments_data_store
		);

		$legacy_shipping_label = new WC_Connect_Shipping_Label(
			$api_client,
			$settings_store,
			$schemas_store,
			$payment_methods_store,
		);
		$nux                   = new WC_Connect_Nux( $this->view_service );
		$feature_banners       = new Banners( $schemas_store, $logger );
		$label_rate_service    = new LabelRateService( $api_client, $logger, $settings_store );

		new WC_Connect_Privacy( $settings_store, $api_client );

		$this->set_logger( $logger );
		$this->set_shipping_logger( $shipping_logger );
		$this->set_api_client( $api_client );
		$this->set_service_schemas_validator( $validator );
		$this->set_service_schemas_store( $schemas_store );
		$this->set_service_settings_store( $settings_store );
		$this->set_payment_methods_store( $payment_methods_store );
		$this->set_shipping_label( $shipping_label );
		$this->set_legacy_shipping_label( $legacy_shipping_label );
		$this->set_nux( $nux );
		$this->set_feature_banners( $feature_banners );
		$this->label_rate_service = $label_rate_service;
		$this->promo_service      = $promo_service;

		$label_migrator             = new LegacyLabelMigrator( $settings_store );
		$settings_migrator          = new LegacySettingsMigrator();
		$this->migration_controller = new MigrationController( $label_migrator, $settings_migrator );
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing links.
	 * @return array New links.
	 */
	public function add_plugin_action_links( $links ) {
		$settings = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=shipping&section=woocommerce-shipping-settings' ) . '">' . esc_html__( 'Settings', 'woocommerce-shipping' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	public function add_plugin_description_links( $plugin_meta, $plugin_file ) {
		if ( plugin_basename( WCSHIPPING_PLUGIN_FILE ) === $plugin_file ) {
			$plugin_meta[] = '<a href="https://woocommerce.com/document/woocommerce-shipping/" target="_blank">' . esc_html__( 'Documentation', 'woocommerce-shipping' ) . '</a>';
			$plugin_meta[] = '<a href="https://wordpress.org/support/plugin/woocommerce-shipping/" target="_blank">' . esc_html__( 'Support', 'woocommerce-shipping' ) . '</a>';
		}
		return $plugin_meta;
	}

	/**
	 * Load admin-only plugin dependencies.
	 */
	public function load_admin_dependencies() {
		$schema          = $this->get_service_schemas_store();
		$settings        = $this->get_service_settings_store();
		$logger          = $this->get_logger();
		$payment_methods = $this->get_payment_methods_store();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-debug-tools.php';
		new WC_Connect_Debug_Tools( $this->api_client );

		if ( FeatureFlags::is_scanform_enabled() ) {
			new ScanForm();
		}

		if ( FeatureFlags::is_bulk_labels_enabled() ) {
			new BulkLabelsBanner( $this->service_settings_store );
		}

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-settings-pages.php';
		$settings_pages = new WC_Connect_Settings_Pages(
			$this->api_client,
			$this->get_service_schemas_store(),
			$this->origin_address_service,
			$settings,
			$payment_methods
		);
		$this->set_settings_pages( $settings_pages );
		$this->set_help_view( new WC_Connect_Help_View( $schema, $settings, $logger ) );
		add_action( 'admin_notices', array( WC_Connect_Error_Notice::instance(), 'render_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_schema_notices' ) );

		if ( ! defined( 'NEXT_ADMIN_PLUGIN_DIR' ) ) {
			// Queue up hooks for data migration admin messages.
			MigrationNotices::init( $this->migration_controller );
		}
	}

	/**
	 * Hook plugin classes into WP/WC core.
	 */
	public function attach_hooks() {
		$schemas_store = $this->get_service_schemas_store();
		$schemas       = $schemas_store->get_service_schemas();

		if ( $schemas ) {
			add_filter( 'woocommerce_payment_gateways', array( $this, 'woocommerce_payment_gateways' ) );
			add_action( 'woocommerce_shipping_zone_method_added', array( $this, 'shipping_zone_method_added' ), 10, 3 );
			add_action(
				'wcshipping_shipping_zone_method_added',
				array(
					$this,
					'save_defaults_to_shipping_method',
				),
				10,
				3
			);
			add_action(
				'woocommerce_shipping_zone_method_deleted',
				array(
					$this,
					'shipping_zone_method_deleted',
				),
				10,
				3
			);
			add_action(
				'woocommerce_shipping_zone_method_status_toggled',
				array(
					$this,
					'shipping_zone_method_status_toggled',
				),
				10,
				4
			);
		}

		/**
		 * Queue a cron job to refetch the schema data from the WooCommerce Connect Server.
		 *
		 * The schema data fetched from the WooCommerce Connect Server varies based on configuration options.
		 * Updating these options requires that the schema data be refetched to reflect the new configuration.
		 *
		 * @since 1.6.1
		 */
		$options = apply_filters(
			'wcshipping_schema_dependent_options',
			array(
				'woocommerce_store_postcode',
				'woocommerce_currency',
				'woocommerce_weight_unit',
				'woocommerce_dimension_unit',
			)
		);
		foreach ( $options as $option ) {
			add_action( "update_option_{$option}", array( $this, 'queue_service_schema_refresh' ) );
		}

		$address_options = array(
			'woocommerce_store_address',
			'woocommerce_store_address_2',
			'woocommerce_store_city',
			'woocommerce_store_postcode',
			'woocommerce_default_country',
		);

		$origin_address_service = new OriginAddressService();
		foreach ( $address_options as $option ) {
			add_action( "update_option_{$option}", array( $origin_address_service, 'on_woocommerce_store_address_option_updated' ), 10, 3 );
		}

		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
		add_action( 'rest_api_init', array( $this, 'rest_api_v2_init' ) );
		add_action( 'rest_api_init', array( $this, 'wc_api_dev_init' ), 9999 );
		add_action(
			'wcshipping_fetch_service_schemas',
			array(
				$this->service_schemas_fetcher,
				'fetch',
			)
		);
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_wc_connect_package_meta_data' ) );
		add_filter( 'is_protected_meta', array( $this, 'hide_wc_connect_order_meta_data' ), 10, 3 );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( $this, 'add_order_meta_boxes' ), 9999, 1 );
		add_action( 'add_meta_boxes_shop_order', array( $this, 'add_order_meta_boxes_legacy_support' ), 9999, 1 );
		add_filter( 'woocommerce_shipping_fields', array( $this, 'add_shipping_phone_to_checkout' ) );
		add_action( 'woocommerce_admin_shipping_fields', array( $this, 'add_shipping_phone_to_order_fields' ) );
		add_filter( 'woocommerce_get_order_address', array( $this, 'get_shipping_or_billing_phone_from_order' ), 10, 3 );
		add_filter( 'wcshipping_shipping_service_settings', array( $this, 'shipping_service_settings' ), 10, 3 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'add_tracking_info_to_emails' ), 10, 3 );
		add_filter( 'woocommerce_email_classes', array( $this, 'add_return_label_email_class' ) );
		add_action( 'wcshipping_cleanup_temp_file', array( $this, 'cleanup_temp_file' ) );
		add_action( 'wcshipping_send_return_label_email_delayed', array( $this, 'send_return_label_email_delayed' ), 10, 2 );
		add_action( 'admin_print_footer_scripts', array( $this, 'add_sift_js_tracker' ) );
		// Hooks for migration processing.
		MigrationState::init();
		// Hooks for Shipment Tracking.
		WooCommerceShipmentTracking::init();

		if ( is_admin() ) {
			$this->init_analytics();
			$this->load_admin_dependencies();
		}
	}

	/**
	 * Queue up a service schema refresh (on shutdown) if there isn't one already.
	 */
	public function queue_service_schema_refresh() {
		if ( has_action( 'shutdown', array( $this->service_schemas_fetcher, 'fetch' ) ) ) {
			return;
		}

		add_action( 'shutdown', array( $this->service_schemas_fetcher, 'fetch' ) );
	}

	public function tos_rest_init() {
		$settings_store = $this->get_service_settings_store();
		$logger         = $this->get_logger();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-base-controller.php';

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-tos-controller.php';
		$rest_tos_controller = new WC_REST_Connect_Tos_Controller( $this->api_client, $settings_store, $logger );
		$this->set_rest_tos_controller( $rest_tos_controller );
		$rest_tos_controller->register_routes();
		( new TosRESTController() )->register_routes();
	}

	/**
	 * Hook the REST API
	 * Note that we cannot load our controller until this time, because prior to
	 * rest_api_init firing, WP_REST_Controller is not yet defined
	 */
	public function rest_api_init() {
		$schemas_store         = $this->get_service_schemas_store();
		$settings_store        = $this->get_service_settings_store();
		$payment_methods_store = $this->get_payment_methods_store();
		$logger                = $this->get_logger();

		if ( ! class_exists( 'WP_REST_Controller' ) ) {
			$this->logger->debug( 'Error. WP_REST_Controller could not be found', __FUNCTION__ );

			return;
		}

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-base-controller.php';

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-packages-controller.php';
		$legacy_rest_packages_controller = new WC_REST_Connect_Packages_Controller( $this->api_client, $settings_store, $logger, $this->service_schemas_store );
		$this->set_rest_packages_controller( $legacy_rest_packages_controller );
		$legacy_rest_packages_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-account-settings-controller.php';
		$legacy_rest_account_settings_controller = new WC_REST_Connect_Account_Settings_Controller( $this->api_client, $settings_store, $logger, $this->payment_methods_store );
		$this->set_rest_account_settings_controller( $legacy_rest_account_settings_controller );
		$legacy_rest_account_settings_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-services-controller.php';
		$legacy_rest_services_controller = new WC_REST_Connect_Services_Controller( $this->api_client, $settings_store, $logger, $schemas_store );
		$this->set_rest_services_controller( $legacy_rest_services_controller );
		$legacy_rest_services_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-self-help-controller.php';
		$legacy_rest_self_help_controller = new WC_REST_Connect_Self_Help_Controller( $this->api_client, $settings_store, $logger );
		$this->set_rest_self_help_controller( $legacy_rest_self_help_controller );
		$legacy_rest_self_help_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-service-data-refresh-controller.php';
		$legacy_rest_service_data_refresh_controller = new WC_REST_Connect_Service_Data_Refresh_Controller( $this->api_client, $settings_store, $logger );
		$legacy_rest_service_data_refresh_controller->set_service_schemas_store( $this->get_service_schemas_store() );
		$legacy_rest_service_data_refresh_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-shipping-label-controller.php';
		$legacy_rest_shipping_label_controller = new WC_REST_Connect_Shipping_Label_Controller( $this->api_client, $settings_store, $logger, $this->legacy_shipping_label, $this->payment_methods_store );
		$this->set_rest_shipping_label_controller( $legacy_rest_shipping_label_controller );
		$legacy_rest_shipping_label_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-shipping-label-status-controller.php';
		$legacy_rest_shipping_label_status_controller = new WC_REST_Connect_Shipping_Label_Status_Controller( $this->api_client, $settings_store, $logger );
		$this->set_rest_shipping_label_status_controller( $legacy_rest_shipping_label_status_controller );
		$legacy_rest_shipping_label_status_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-shipping-label-refund-controller.php';
		$legacy_rest_shipping_label_refund_controller = new WC_REST_Connect_Shipping_Label_Refund_Controller( $this->api_client, $settings_store, $logger );
		$this->set_rest_shipping_label_refund_controller( $legacy_rest_shipping_label_refund_controller );
		$legacy_rest_shipping_label_refund_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-shipping-label-preview-controller.php';
		$legacy_rest_shipping_label_preview_controller = new WC_REST_Connect_Shipping_Label_Preview_Controller( $this->api_client, $settings_store, $logger );
		$this->set_rest_shipping_label_preview_controller( $legacy_rest_shipping_label_preview_controller );
		$legacy_rest_shipping_label_preview_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-shipping-label-print-controller.php';
		$legacy_rest_shipping_label_print_controller = new WC_REST_Connect_Shipping_Label_Print_Controller( $this->api_client, $settings_store, $logger );
		$this->set_rest_shipping_label_print_controller( $legacy_rest_shipping_label_print_controller );
		$legacy_rest_shipping_label_print_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-shipping-rates-controller.php';
		$legacy_rest_shipping_rates_controller = new WC_REST_Connect_Shipping_Rates_Controller( $this->api_client, $settings_store, $logger );
		$this->set_rest_shipping_rates_controller( $legacy_rest_shipping_rates_controller );
		$legacy_rest_shipping_rates_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-address-normalization-controller.php';
		$legacy_rest_address_normalization_controller = new WC_REST_Connect_Address_Normalization_Controller( $this->api_client, $settings_store, $logger );
		$this->set_rest_address_normalization_controller( $legacy_rest_address_normalization_controller );
		$legacy_rest_address_normalization_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-assets-controller.php';
		$legacy_rest_assets_controller = new WC_REST_Connect_Assets_Controller( $this->api_client, $settings_store, $logger );
		$this->set_rest_assets_controller( $legacy_rest_assets_controller );
		$legacy_rest_assets_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-shipping-carrier-controller.php';
		$legacy_rest_carrier_controller = new WC_REST_Connect_Shipping_Carrier_Controller( $this->api_client, $settings_store, $logger );
		$this->set_rest_carrier_controller( $legacy_rest_carrier_controller );
		$legacy_rest_carrier_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-shipping-carriers-controller.php';
		$legacy_rest_carriers_controller = new WC_REST_Connect_Shipping_Carriers_Controller( $this->api_client, $settings_store, $logger );
		$this->set_rest_carriers_controller( $legacy_rest_carriers_controller );
		$legacy_rest_carriers_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-subscriptions-controller.php';
		$legacy_rest_subscriptions_controller = new WC_REST_Connect_Subscriptions_Controller( $this->api_client, $settings_store, $logger );
		$this->set_rest_subscriptions_controller( $legacy_rest_subscriptions_controller );
		$legacy_rest_subscriptions_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-subscription-activate-controller.php';
		$legacy_rest_subscription_activate_controller = new WC_REST_Connect_Subscription_Activate_Controller( $this->api_client, $settings_store, $logger );
		$this->set_rest_subscription_activate_controller( $legacy_rest_subscription_activate_controller );
		$legacy_rest_subscription_activate_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-shipping-carrier-delete-controller.php';
		$legacy_rest_carrier_delete_controller = new WC_REST_Connect_Shipping_Carrier_Delete_Controller( $this->api_client, $settings_store, $logger );
		$this->set_rest_carrier_delete_controller( $legacy_rest_carrier_delete_controller );
		$legacy_rest_carrier_delete_controller->register_routes();

		require_once WCSHIPPING_PLUGIN_DIR . '/classes/legacy-api-controllers/class-wc-rest-connect-shipping-carrier-types-controller.php';
		$legacy_rest_carrier_types_controller = new WC_REST_Connect_Shipping_Carrier_Types_Controller( $this->api_client, $settings_store, $logger );
		$this->set_carrier_types_controller( $legacy_rest_carrier_types_controller );
		$legacy_rest_carrier_types_controller->register_routes();

		add_filter( 'rest_request_before_callbacks', array( $this, 'log_rest_api_errors' ), 10, 3 );

		$rest_self_help_controller = new SelfHelpRestController( $this->logger );
		$rest_self_help_controller->register_routes();

		$rest_service_data_refresh_controller = new ServiceDataRefreshRestController( $this->service_schemas_store );
		$rest_service_data_refresh_controller->register_routes();

		$rest_account_settings_controller = new AccountSettingsRestController( $settings_store, $this->payment_methods_store, $logger );
		$rest_account_settings_controller->register_routes();
		$origin_addresses_service = $this->origin_address_service;

		( new AddressRESTController(
			$this->address_normalization_service,
			$origin_addresses_service
		) )->register_routes();

		( new LabelRateRESTController( $this->label_rate_service ) )->register_routes();
		( new OrdersShippingContextRESTController( $settings_store ) )->register_routes();

		$package_settings = new WC_Connect_Package_Settings(
			$settings_store,
			$this->service_schemas_store
		);
		( new PackagesRESTController( $settings_store, $package_settings ) )->register_routes();

		$shipments_service            = new ShipmentsService( $settings_store );
		$fulfillments_service         = $this->fulfillments_service ?? new FulfillmentsService( $this->shipping_fulfillments_data_store );
		$this->fulfillments_service   = $fulfillments_service;
		$label_purchase_service       = new LabelPurchaseService( $settings_store, $this->api_client, $this->shipping_label, $logger, $this->promo_service, $fulfillments_service );
		$this->label_purchase_service = $label_purchase_service;
		( new LabelPurchaseRESTController( $label_purchase_service ) )->register_routes();
		( new PackageAssignmentRESTController( new PackageAssignmentService( $settings_store, $this->service_schemas_store, $logger ) ) )->register_routes();
		( new ShipmentsRESTController( $shipments_service, $this->fulfillments_service ) )->register_routes();

		( new LabelStatusController( $label_purchase_service, $logger, $this->shipping_fulfillments_data_store ) )->register_routes();

		( new LabelRefundRESTController( $label_purchase_service ) )->register_routes();

		$label_print_service = new LabelPrintService( $this->api_client, $logger, $label_purchase_service );
		( new LabelPrintController(
			$settings_store,
			$this->api_client,
			$logger,
			$label_print_service,
			$this->shipping_fulfillments_data_store,
			$fulfillments_service
		) )->register_routes();
		$rest_label_preview_controller = new LabelPreviewRESTController( $label_print_service, $logger );
		$rest_label_preview_controller->register_routes();

		( new AssetsRESTController() )->register_routes();
		( new ConfigRESTController( $this->shipping_label, $this->account_settings, $this->origin_address_service ) )->register_routes();

		( new UPSDAPCarrierStrategyRESTController( $this->upsdap_carrier_strategy_service ) )->register_routes();
		( new FedExCarrierStrategyRESTController( $this->fedex_carrier_strategy_service ) )->register_routes();
		// Ensure all shipping endpoints are not cached.
		WCShippingRESTController::prevent_route_caching();

		$labels_service = new LabelsService();
		( new ShippingLabelRESTController( $labels_service ) )->register_routes();

		( new EligibilityRESTController( $this->view_service, $settings_store, $this->get_payment_methods_store() ) )->register_routes();

		( new PromoRESTController( $this->promo_service ) )->register_routes();

		( new ServiceStatusRESTController( $this->api_client ) )->register_routes();

		if ( FeatureFlags::is_scanform_enabled() ) {
			$scanform_service = new ScanFormService();

			/**
			 * The ScanForm is one document that can be scanned to mark all included
			 * tracking codes as "Accepted for Shipment" by the carrier.
			 */
			( new ScanFormRESTController( $scanform_service, $this->api_client, $logger ) )->register_routes();
			( new ScanFormHistoryRESTController( $scanform_service ) )->register_routes();
		}
	}

	/**
	 * Hook the REST API V2
	 * Note that we cannot load our controller until this time, because prior to
	 * rest_api_init firing, WP_REST_Controller is not yet defined
	 */
	public function rest_api_v2_init() {
		if ( ! class_exists( 'WP_REST_Controller' ) ) {
			$this->logger->debug( 'Error. WP_REST_Controller could not be found', __FUNCTION__ );
			return;
		}

		// Load V2 controllers only if the request is for a V2 route.
		$rest_route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
		if ( ! str_contains( $rest_route, '/wcshipping/v2/' ) ) {
			return;
		}

		require_once WCSHIPPING_PLUGIN_DIR . '/src/RestAPi/Routes/V2/AdbstractSchema.php';

		( new AddressesV2Controller( $this->origin_address_service ) )->register_routes();
	}

	/**
	 * If the required v3 REST API endpoints haven't been loaded at this point, load the local copies of said endpoints.
	 * Delete this when the "v3" REST API is included in all the WC versions we support.
	 */
	public function wc_api_dev_init() {
		$rest_server     = rest_get_server();
		$existing_routes = $rest_server->get_routes();
		if ( ! isset( $existing_routes['/wc/v3/data/continents'] ) ) {
			require_once WCSHIPPING_PLUGIN_DIR . '/classes/wc-api-dev/class-wc-rest-dev-data-controller.php';
			require_once WCSHIPPING_PLUGIN_DIR . '/classes/wc-api-dev/class-wc-rest-dev-data-continents-controller.php';
			$continents = new WC_REST_Dev_Data_Continents_Controller();
			$continents->register_routes();
		}
	}

	/**
	 * Log any WP_Errors encountered before our REST API callbacks
	 *
	 * Note: intended to be hooked into 'rest_request_before_callbacks'
	 *
	 * @param WP_HTTP_Response $response Result to send to the client. Usually a WP_REST_Response.
	 * @param WP_REST_Server   $handler  ResponseHandler instance (usually WP_REST_Server).
	 * @param WP_REST_Request  $request  Request used to generate the response.
	 *
	 * @return mixed - pass through value of $response.
	 */
	public function log_rest_api_errors( $response, $handler, $request ) {
		if ( ! is_wp_error( $response ) ) {
			return $response;
		}

		if ( 0 === strpos( $request->get_route(), '/wc/v1/connect/' ) ) {
			$route_info = $request->get_method() . ' ' . $request->get_route();

			$this->get_logger()->error( $response, $route_info );
			$this->get_logger()->error( $route_info, $request->get_body() );
		}

		return $response;
	}

	/**
	 * Added to the wcshipping_shipping_service_settings filter, returns service settings
	 *
	 * @param $settings
	 * @param $method_id
	 * @param $instance_id
	 *
	 * @return array
	 */
	public function shipping_service_settings( $settings, $method_id, $instance_id ) {
		$settings_store = $this->get_service_settings_store();
		$schemas_store  = $this->get_service_schemas_store();
		$service_schema = $schemas_store->get_service_schema_by_id_or_instance_id( $instance_id ? $instance_id : $method_id );
		if ( ! $service_schema ) {
			return array_merge(
				$settings,
				array(
					'formType'   => 'services',
					'methodId'   => $method_id,
					'instanceId' => $instance_id,
				)
			);
		}

		return array_merge(
			$settings,
			array(
				'storeOptions' => $settings_store->get_store_options(),
				'formSchema'   => $service_schema->service_settings,
				'formLayout'   => $service_schema->form_layout,
				'formData'     => $settings_store->get_service_settings( $method_id, $instance_id ),
				'formType'     => 'services',
				'methodId'     => $method_id,
				'instanceId'   => $instance_id,
			)
		);
	}

	/**
	 * Add tracking info (if available) to completed emails using the woocommerce_email_after_order_table hook
	 *
	 * @param bool|\WC_Order|\WC_Order_Refund $order
	 * @param                                 $sent_to_admin
	 * @param                                 $plain_text
	 */
	public function add_tracking_info_to_emails( $order, $sent_to_admin, $plain_text ) {

		// Abort if no $order was passed, if the order is not marked as 'completed' or if another extension is handling the emailing.
		if ( ! $order
			|| ! $order->has_status( 'completed' )
			|| ! WC_Connect_Extension_Compatibility::should_email_tracking_details( $order->get_id() ) ) {
			return;
		}

		$labels = $this->service_settings_store->get_label_order_meta_data( $order->get_id() );

		// Abort if there are no labels.
		if ( empty( $labels ) ) {
			return;
		}

		$markup     = '';
		$link_color = get_option( 'woocommerce_email_text_color' );

		// Generate a table row for each label.
		foreach ( $labels as $label ) {
			$carrier         = $label['carrier_id'];
			$carrier_service = $this->get_service_schemas_store()->get_service_schema_by_id( $carrier );
			$carrier_label   = ( ! $carrier_service || empty( $carrier_service->carrier_name ) ) ? strtoupper( $carrier ) : $carrier_service->carrier_name;
			$tracking        = $label['tracking'] ?? null;
			$error           = array_key_exists( 'error', $label );
			$refunded        = array_key_exists( 'refund', $label );
			$return          = $label['is_return'] ?? false;

			// Skip labels with errors, refunds, returns, or no tracking number (tracking can be null/missing, which would fatal Utils::get_tracking_url()).
			if ( $error || $refunded || $return || empty( $tracking ) ) {
				continue;
			}

			if ( $plain_text ) {
				// Should look like '- USPS: 9405536897846173912345' in plain text mode.
				$markup .= '- ' . $carrier_label . ': ' . $tracking . "\n";
				continue;
			}

			$markup .= '<tr>';
			$markup .= '<td class="td" scope="col">' . esc_html( $carrier_label ) . '</td>';

			$tracking_url = Utils::get_tracking_url( $carrier, $tracking );

			$markup .= '<td class="td" scope="col">';
			$markup .= '<a href="' . esc_url( $tracking_url ) . '" style="color: ' . esc_attr( $link_color ) . '">' . esc_html( $tracking ) . '</a>';
			$markup .= '</td>';
			$markup .= '</tr>';
		}

		// Abort if all labels are refunded.
		if ( empty( $markup ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
			echo esc_html( mb_strtoupper( __( 'Tracking', 'woocommerce-shipping' ), 'UTF-8' ) ) . "\n\n";
			echo wp_kses( $markup, array() );

			return;
		}

		?>
		<div style="font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; margin-bottom: 40px;">
			<h2><?php esc_html_e( 'Tracking', 'woocommerce-shipping' ); ?></h2>
			<table class="td" cellspacing="0" cellpadding="6" style="margin-top: 10px; width: 100%;">
				<thead>
					<tr>
						<th class="td" scope="col"><?php esc_html_e( 'Provider', 'woocommerce-shipping' ); ?></th>
						<th class="td"
							scope="col"><?php esc_html_e( 'Tracking number', 'woocommerce-shipping' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php echo wp_kses_post( $markup ); ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Hook fetching the available services from the connect server
	 */
	public function schedule_service_schemas_fetch() {
		$schemas_store     = $this->get_service_schemas_store();
		$schemas           = $schemas_store->get_service_schemas();
		$last_fetch_result = $schemas_store->get_last_fetch_result_code();

		if ( ! $schemas && '401' !== $last_fetch_result ) { // Don't retry auth failures wait for next scheduled time.
			$this->service_schemas_fetcher->fetch();
		} elseif ( defined( 'WOOCOMMERCE_CONNECT_FREQUENT_FETCH' ) && WOOCOMMERCE_CONNECT_FREQUENT_FETCH ) {
			$this->service_schemas_fetcher->fetch();
		} elseif ( ! wp_next_scheduled( 'wcshipping_fetch_service_schemas' ) ) {
			wp_schedule_event( time(), 'daily', 'wcshipping_fetch_service_schemas' );
		}
	}

	public function woocommerce_payment_gateways( $payment_gateways ) {
		return $payment_gateways;
	}

	public function get_active_shipping_services() {
		global $wpdb;
		$active_shipping_services = array();
		$shipping_service_ids     = $this->get_service_schemas_store()->get_all_shipping_method_ids();

		foreach ( $shipping_service_ids as $shipping_service_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$is_active = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT instance_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE is_enabled = 1 AND method_id = %s LIMIT 1;",
					$shipping_service_id
				)
			);

			if ( $is_active ) {
				$active_shipping_services[] = $shipping_service_id;
			}
		}

		return $active_shipping_services;
	}

	public function get_active_services() {
		return $this->get_active_shipping_services();
	}

	public function is_wc_connect_shipping_service( $service_id ) {
		$shipping_service_ids = $this->get_service_schemas_store()->get_all_shipping_method_ids();

		return in_array( $service_id, $shipping_service_ids );
	}

	public function shipping_zone_method_added( $instance_id, $service_id, $zone_id ) {
		if ( $this->is_wc_connect_shipping_service( $service_id ) ) {
			do_action( 'wcshipping_shipping_zone_method_added', $instance_id, $service_id, $zone_id );
		}
	}

	public function shipping_zone_method_deleted( $instance_id, $service_id, $zone_id ) {
		if ( $this->is_wc_connect_shipping_service( $service_id ) ) {
			WC_Connect_Options::delete_shipping_method_options( $service_id, $instance_id );
			do_action( 'wcshipping_shipping_zone_method_deleted', $instance_id, $service_id, $zone_id );
		}
	}

	public function shipping_zone_method_status_toggled( $instance_id, $service_id, $zone_id, $enabled ) {
		if ( $this->is_wc_connect_shipping_service( $service_id ) ) {
			do_action( 'wcshipping_shipping_zone_method_status_toggled', $instance_id, $service_id, $zone_id, $enabled );
		}
	}

	/**
	 * If we should display the shipment tracking meta box.
	 * WC Shipment Tracking has it's own meta box, so we don't want to show ours if it's installed.
	 *
	 * @return bool
	 */
	public function should_show_shipment_tracking_meta_box() {
		return ! WooCommerceShipmentTracking::is_st_installed();
	}

	/**
	 * Add meta boxes to the order screen.
	 *
	 * WooCommerce has implemented their own version of "add_meta_boxes",
	 * so it different from how e.g. Posts, Pages, and WooCommerce products work.
	 *
	 * @see Automattic\WooCommerce\Internal\Admin\Orders\Edit::setup
	 *
	 * @param WC_Order $order The order object.
	 *
	 * @return void
	 */
	public function add_order_meta_boxes( $order ) {
		// We need this check to make sure we do not try to show the meta-box on unexpected
		// screens like the order creation page.
		// "action" is empty for other actions than add (read: "edit").
		if ( 'add' === get_current_screen()->action ) {
			return;
		}

		$should_show_meta_box     = $this->shipping_label->throw_error_or_show_order_meta_box( $order );
		$allowed_errors_in_banner = array(
			'wcshipping_banner_order_not_found',
			'wcshipping_banner_jetpack_connection_failed',
			'wcshipping_banner_store_ineligible',
		);

		// Bail early if it's an error we shouldn't display.
		if ( is_wp_error( $should_show_meta_box ) && ! in_array( $should_show_meta_box->get_error_code(), $allowed_errors_in_banner ) ) {
			return;
		}

		$label_purchase_meta_box_id = 'woocommerce-order-label';
		$this->maybe_move_meta_box_to_top( $label_purchase_meta_box_id );
		add_meta_box(
			$label_purchase_meta_box_id,
			__( 'Shipping Label', 'woocommerce-shipping' ),
			array(
				$this->shipping_label,
				'meta_boxes',
			),
			null,
			'normal',
			'high',
			array( 'context' => 'shipping_label' )
		);

		if ( $this->should_show_shipment_tracking_meta_box() ) {
			add_meta_box( 'woocommerce-order-shipment-tracking', __( 'Shipment Tracking', 'woocommerce-shipping' ), array( $this->shipping_label, 'meta_boxes' ), null, 'side', 'high', array( 'context' => 'shipment_tracking' ) );
		}
	}

	/**
	 * Add legacy WordPress posts storage support for order meta boxes.
	 *
	 * WooCommerce introduced "High Performance Order Storage" in WooCommerce 8.2, where orders are implemented as
	 * an independent entity type (read: not as a custom post type).
	 *
	 * This method functions as a "polyfill" for installations of WooCommerce that still use the legacy post storage
	 * which used to be the default on older WC versions, and makes it easy for us
	 * to remove this hook the day we no longer have to support the CPT version.
	 *
	 * @link https://developer.woocommerce.com/docs/how-to-enable-high-performance-order-storage/
	 *
	 * @param WP_Post $post The order as a post object.
	 *
	 * @return void
	 */
	public function add_order_meta_boxes_legacy_support( $post ) {
		$order = wc_get_order( $post->ID );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->add_order_meta_boxes( $order );
	}

	public function hide_wc_connect_package_meta_data( $hidden_keys ) {
		$hidden_keys[] = 'wcshipping_packages';
		$hidden_keys[] = 'wcshipping_packing_log';

		return $hidden_keys;
	}

	public function hide_wc_connect_order_meta_data( $protected, $meta_key, $meta_type ) {
		if ( in_array(
			$meta_key,
			array(
				'wcshipping_labels',
				WC_Connect_Service_Settings_Store::IS_DESTINATION_NORMALIZED_KEY,
				AddressNormalizationService::DESTINATION_NORMALIZED_HASH_KEY,
			),
			true
		) ) {
			$protected = true;
		}

		return $protected;
	}

	public function add_shipping_phone_to_checkout( $fields ) {
		$defaults = array(
			'label'        => __( 'Phone', 'woocommerce-shipping' ),
			'type'         => 'tel',
			'required'     => false,
			'class'        => array( 'form-row-wide' ),
			'clear'        => true,
			'validate'     => array( 'phone' ),
			'autocomplete' => 'tel',
		);

		// Use existing settings if the field exists.
		$field = isset( $fields['shipping_phone'] )
			? array_merge( $defaults, $fields['shipping_phone'] )
			: $defaults;

		// Enforce phone type, autocomplete, and validation.
		$field['type']         = 'tel';
		$field['autocomplete'] = 'tel';
		if ( ! in_array( 'tel', $field['validate'], true ) ) {
			$field['validate'][] = 'tel';
		}

		// Add to the list.
		$fields['shipping_phone'] = $field;

		return $fields;
	}

	public function add_shipping_phone_to_order_fields( $fields ) {
		$fields['phone'] = array(
			'label' => __( 'Phone', 'woocommerce-shipping' ),
		);

		return $fields;
	}

	public function get_shipping_or_billing_phone_from_order( $fields, $address_type, WC_Order $order ) {
		if ( 'shipping' !== $address_type ) {
			return $fields;
		}

		$fields['phone'] = $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone();

		return $fields;
	}

	/*
	 * Adds the Sift JS page tracker if needed. See the comments for the detailed logic.
	 *
	 * @return  void
	 */
	public function add_sift_js_tracker() {
		$sift_configurations = $this->api_client->get_sift_configuration();

		$connected_data = WC_Connect_Jetpack::get_connection_owner_wpcom_data();

		if ( is_wp_error( $sift_configurations ) || empty( $sift_configurations->beacon_key ) || empty( $connected_data['ID'] ) ) {
			// Don't add sift tracking if we can't have the parameters to initialize Sift
			return;
		}

		$fraud_config = wp_json_encode(
			array(
				'beacon_key' => esc_attr( $sift_configurations->beacon_key ),
				'user_id'    => esc_attr( $connected_data['ID'] ),
			)
		);

		wp_register_script(
			'sift',
			'https://cdn.sift.com/s.js',
			array(),
			// Sift scripts are not versioned like e.g. "jquery-3.7.1.min.js", so we cannot declare anything explicit.
			// We could, alternatively, use the current plugin version, but that would be misleading if other plugins
			// call "sift" as a dependency, so we default to use the WordPress version (default behaviour when defining
			// this value as "false").
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NoExplicitVersion
			false,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_enqueue_script(
			'wcshipping-sift',
			WCSHIPPING_JAVASCRIPT_URL . 'sift.js',
			array( 'sift' ),
			WCShippingUtils::get_wcshipping_version(),
			array( 'in_footer' => true )
		);

		wp_add_inline_script(
			'wcshipping-sift',
			"var wcShippingSiftConfig = Object.assign( {}, wcShippingSiftConfig, $fraud_config );",
			'before'
		);
	}

	/**
	 * Enqueue entry point scripts, localized data, and stylesheets.
	 * Remember to call Automattic\WCShipping\DOM\Manipulation::create_root_script_element before calling do_action( 'wcshipping_enqueue_script' )
	 * in your calling function.
	 *
	 * @param string $handle The name of the entry point script to enqueue.
	 * @param array  $extra_args Extra data to pass to the entry point script, this gets added as localised data.
	 *
	 * @return void
	 */
	public function wcshipping_enqueue_script( $handle, $extra_args = array() ) {
		$script_name         = "$handle.js";
		$script_path         = WCSHIPPING_PLUGIN_DIST_DIR . $script_name;
		$script_url          = Utils::get_enqueue_base_url() . $script_name;
		$script_asset_path   = WCSHIPPING_PLUGIN_DIST_DIR . $handle . '.asset.php';
		$script_asset        = file_exists( $script_asset_path )
			? require $script_asset_path : array();  // nosemgrep: audit.php.lang.security.file.inclusion-arg --- This is a safe file inclusion.
		$script_dependencies = Utils::filter_dev_dependencies( $script_asset['dependencies'] ?? array() );
		$script_version      = $script_asset['version'] ?? Utils::get_file_version( $script_path );

		// Enqueue the entry point script.
		wp_enqueue_script(
			$handle,
			$script_url,
			$script_dependencies,
			$script_version,
			array(
				'in_footer' => true,
			)
		);

		// Enqueue the stylesheet.
		$style_name = "style-$handle.css";
		wp_enqueue_style(
			$handle,
			Utils::get_enqueue_base_url() . $style_name,
			array(),
			Utils::get_file_version( WCSHIPPING_PLUGIN_DIST_DIR . $style_name ),
		);

		$encoded_extras = wp_json_encode( $extra_args );
		wp_add_inline_script(
			$handle,
			"var WCShipping_Config = Object.assign({}, WCShipping_Config, $encoded_extras);",
			'before'
		);

		// Declare a wcShippingSettings object containing all the important settings for the plugin accessible via JS.
		$wcshipping_settings = wp_json_encode( WCShippingUtils::get_settings_object() );
		wp_add_inline_script(
			$handle,
			"var wcShippingSettings = Object.assign({}, wcShippingSettings, $wcshipping_settings);",
			'before'
		);

		wp_set_script_translations( $handle, 'woocommerce-shipping', WCSHIPPING_PLUGIN_DIR . '/languages' );
	}

	/**
	 * Register WCShipping plugin assets with proper HMR support.
	 * This static method can be called externally to register
	 * the woocommerce-shipping-plugin script with the correct URL based on whether
	 * HMR is active or not.
	 */
	public static function load_shipping_dependencies() {
		$handle            = 'woocommerce-shipping-plugin';
		$script_asset_path = WCSHIPPING_PLUGIN_DIST_DIR . $handle . '.asset.php';

		// Always read asset file from local filesystem.
		// Webpack writes .asset.php files to disk even during HMR, so we can always read from disk.
		$script_asset = file_exists( $script_asset_path )
			? require $script_asset_path : array();  // nosemgrep: audit.php.lang.security.file.inclusion-arg --- This is a safe file inclusion.

		$script_dependencies = Utils::filter_dev_dependencies( $script_asset['dependencies'] ?? array() );

		foreach ( $script_dependencies as $key => $dependency ) {
			if ( false === wp_script_is( $dependency, 'enqueued' ) ) {
				wp_enqueue_script( $dependency );
			}
		}
	}

	/**
	 * Enqueue the next module script and its dependencies.
	 */
	public static function load_next_module() {
		$handle            = 'woocommerce-shipping-next';
		$script_name       = "$handle.js";
		$script_path       = WCSHIPPING_PLUGIN_DIST_DIR . 'next/' . $script_name;
		$script_url        = Utils::get_enqueue_base_url() . 'next/' . $script_name;
		$script_asset_path = WCSHIPPING_PLUGIN_DIST_DIR . 'next/' . $handle . '.asset.php';
		$script_asset      = file_exists( $script_asset_path )
		? require $script_asset_path : array();  // nosemgrep: audit.php.lang.security.file.inclusion-arg --- This is a safe file inclusion.
		$script_version    = $script_asset['version'] ?? Utils::get_file_version( $script_path );
		wp_enqueue_script_module(
			$handle,
			$script_url,
			array(),
			$script_version,
		);
	}

	public function render_schema_notices() {
		$schemas = $this->get_service_schemas_store()->get_service_schemas();
		if ( empty( $schemas ) || ! property_exists( $schemas, 'notices' ) || empty( $schemas->notices ) ) {
			return;
		}
		$allowed_html = array(
			'a'      => array( 'href' => array() ),
			'strong' => array(),
			'br'     => array(),
		);
		foreach ( $schemas->notices as $notice ) {
			$dismissible = false;
			// check if the notice is dismissible.
			if ( property_exists( $notice, 'id' ) && ! empty( $notice->id ) && property_exists( $notice, 'dismissible' ) && $notice->dismissible ) {
				// check if the notice is being dismissed right now.
				if (
					isset( $_GET['wcshipping-dismiss-server-notice'] )
					&& isset( $_GET['_wpnonce'] )
					&& check_admin_referer( 'wcshipping_dismiss_server_notice' )
					&& $_GET['wcshipping-dismiss-server-notice'] === $notice->id
				) {
					set_transient( 'wcc_notice_dismissed_' . $notice->id, true, MONTH_IN_SECONDS );
					continue;
				}
				// check if the notice has already been dismissed.
				if ( false !== get_transient( 'wcc_notice_dismissed_' . $notice->id ) ) {
					continue;
				}

				$dismissible  = true;
				$link_dismiss = add_query_arg(
					array(
						'wcshipping-dismiss-server-notice' => $notice->id,
						'_wpnonce'                         => wp_create_nonce( 'wcshipping_dismiss_server_notice' ),
					)
				);
			}
			?>
			<div class='<?php echo esc_attr( 'notice notice-' . $notice->type ); ?>' style="position: relative;">
				<?php if ( $dismissible ) : ?>
					<a href="<?php echo esc_url( $link_dismiss ); ?>"
						style="text-decoration: none;"
						class="notice-dismiss"
						title="<?php esc_attr_e( 'Dismiss this notice', 'woocommerce-shipping' ); ?>"></a>
				<?php endif; ?>
				<p><?php echo wp_kses( $notice->message, $allowed_html ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Add a "deactivated" message to the plugin list table for WooCommerce Shipping & Tax
	 *
	 * @param string $plugin_file
	 * @param array  $plugin_data
	 */
	public function add_custom_message_to_wcst_plugin_list_entry() {
		$migration_state                       = MigrationState::get_state();
		$wcst_plugin_deactivated_message_shown = get_option( 'wcst_plugin_deactivated_message_shown' );
		if ( MigrationState::INSTALLATION_COMPLETED === $migration_state && ! $wcst_plugin_deactivated_message_shown && ! is_plugin_active( 'woocommerce-services/woocommerce-services.php' ) ) {
			printf(
				'<style>
						.plugins tr.wcst-deactivated-message td, .plugins tr.wcst-deactivated-message th { box-shadow: none; }
					</style>
					<tr class="plugin-update-tr wcst-deactivated-message">
						<td colspan="4" class="colspanchange">
							<div class="notice inline notice-warning" style="border:0; border-left: 5px solid #4AB866; padding: 12px; background-color: #EFF9F1;">
								<p>%s</p>
							</div>
						</td>
					</tr>',
				wp_kses_post( __( 'WooCommerce Shipping & Tax has been deactivated. Your data and settings have been carried over to the dedicated WooCommerce Shipping and WooCommerce Tax extensions.<br />For support, please <a href="https://woocommerce.com/my-account/create-a-ticket/">contact our Happiness Engineers</a>.', 'woocommerce-shipping' ) )
			);

			update_option( 'wcst_plugin_deactivated_message_shown', true );
		}
	}

	/**
	 * Maybe sticks the meta box with $box_id id to the top of the order screen.
	 *
	 * If the meta box is not in the ordering thread, move it to the top, this is to respect users' ordering.
	 *
	 * @param string $meta_box_id The meta box ID (machine name) that we want to display at the top by default.
	 *
	 * @return void
	 */
	private function maybe_move_meta_box_to_top( string $meta_box_id ) {
		$screen        = get_current_screen()->id;
		$order_key     = "meta-box-order_{$screen}";
		$current_value = get_user_meta( get_current_user_id(), $order_key, true );

		// If the current value is empty, then it means that the user has never moved any of the boxes, so we have
		// to generate the order structure before we can inject the location of our own meta box.
		if ( empty( $current_value ) ) {
			$current_value = $this->generate_meta_box_order_structure( $screen, $meta_box_id );
		}

		// If the meta box is not in the ordering thread, move it to the top.
		// The reason we check if the box already exists is that, if it exists in the order, then a user has already
		// moved mata boxes around the screen while they had the meta box present, and we do not want to force a
		// specific location on the user.
		if ( isset( $current_value['normal'] ) && strpos( $current_value['normal'], $meta_box_id ) === false ) {
			$new_value           = $current_value;
			$new_value['normal'] = $meta_box_id . ',' . $new_value['normal'];
			update_user_meta( get_current_user_id(), $order_key, $new_value, $current_value );
		}
	}

	/**
	 * Generate meta box order structure for a specific screen.
	 *
	 * WordPress do not store any meta box order before a user has changes the order on a screen for the first time.
	 * This means that we have to create the entire default order structure, so we can insert our own meta box.
	 *
	 * Sadly, there isn't a specific core function to create this order, but we can borrow the logic that renders
	 * the default box order in "do_meta_boxes()" (which is also why we have to create this method to begin with).
	 *
	 * @since 1.0.0
	 *
	 * @see   do_meta_boxes
	 * @global array $wp_meta_boxes
	 *
	 * @param string $screen      The screen identifier that represents which admin page is being rendered.
	 * @param string $meta_box_id The meta box ID we want to inject.
	 *
	 * @return string[]
	 */
	private function generate_meta_box_order_structure( string $screen, string $meta_box_id ) {
		global $wp_meta_boxes;

		$new_value = array(
			'side'     => array(),
			'normal'   => array(),
			'advanced' => array(),
		);

		foreach ( array_keys( $new_value ) as $context ) {
			foreach ( array( 'high', 'sorted', 'core', 'default', 'low' ) as $priority ) {
				if ( isset( $wp_meta_boxes[ $screen ][ $context ][ $priority ] ) ) {
					foreach ( (array) $wp_meta_boxes[ $screen ][ $context ][ $priority ] as $box ) {
						// The meta box can be represented as a "false" bool if it has been removed/hidden.
						// @see remove_meta_box()
						if ( ! is_array( $box ) ) {
							continue;
						}

						// Make sure we do not include the meta box we want to inject.
						// Technically, this wouldn't be needed if wait to register our meta box until after we have
						// created the new order, but this seems like a good practice to put in place to prevent
						// future issues and not rely on execution order.
						if ( $meta_box_id === $box['id'] ) {
							continue;
						}

						$new_value[ $context ][] = $box['id'];
					}
				}
			}

			// WordPress expects this to be a comma separated list of meta box ids, so we have to implode our array.
			// We could have concatenated the ids immediately, but we'd need similar logic to make sure we do not
			// add a comma at the beginning/end of the string to prevent empty results after exploding the string,
			// so this implementation seemed easier to read.
			$new_value[ $context ] = implode( ',', $new_value[ $context ] );
		}

		return $new_value;
	}

	/**
	 * Handle dismiss action for the after-connection banner.
	 *
	 * This must be called during admin_init (before headers are sent) so that
	 * wp_safe_redirect() can work. The banner's dismiss logic in WC_Connect_Nux::show_banner_after_connection()
	 * runs during admin_notices, but at that point headers have already been sent
	 * and the redirect fails silently on some admin pages (e.g., WooCommerce Orders).
	 *
	 * @return void
	 */
	public function maybe_dismiss_after_connection_banner(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended --- Nonce is verified below with check_admin_referer.
		if ( ! isset( $_GET['wcshipping-nux-notice'] ) || 'dismiss' !== $_GET['wcshipping-nux-notice'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended --- Nonce is verified below with check_admin_referer.
		if ( ! isset( $_GET['_wpnonce'] ) ) {
			return;
		}

		// Verify the nonce. This will wp_die() if invalid.
		check_admin_referer( 'wcshipping_dismiss_notice' );

		// No longer need to keep track of whether the before connection banner was displayed.
		WC_Connect_Options::delete_option( WC_Connect_Nux::SHOULD_SHOW_AFTER_CXN_BANNER );

		/**
		 * Fires when the user dismisses the setup complete banner.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wcshipping_setup_complete_banner_dismissed' );

		// Remove all related query args and redirect.
		// This redirect works because we're in admin_init, before headers are sent.
		wp_safe_redirect(
			remove_query_arg(
				array(
					'_wpnonce',
					'wcshipping-nux-notice',
					WC_Connect_Nux::AUTH_SUCCESS_SOURCE_RETURN_PARAM,
					WC_Connect_Nux::AUTH_SUCCESS_NONCE_RETURN_PARAM,
				)
			)
		);
		exit;
	}

	/**
	 * Determine if the migration is eligible to run and set the migration type if needed.
	 *
	 * @return void
	 */
	public function determine_migration_eligibility(): void {
		// Only run this check if the migration state is INSTALLATION_COMPLETED.
		if ( MigrationState::INSTALLATION_COMPLETED !== MigrationState::get_state() ) {
			return;
		}
		$previous_wc_version = get_option( 'wcshipping_previous_woocommerce_version' );
		$current_wc_version  = get_option( 'woocommerce_version' );

		$upgraded_to_wc_9_0         = $previous_wc_version && version_compare( $previous_wc_version, '9.0.0', '<' ) && version_compare( $current_wc_version, '9.0.0', '>=' );
		$current_wc_is_9_0_or_later = version_compare( $current_wc_version, '9.0.0', '>=' );

		$wcst_needs_labels_migration   = ( $upgraded_to_wc_9_0 || $current_wc_is_9_0_or_later ) && $this->migration_controller->needs_labels_migration();
		$wcst_needs_settings_migration = $this->migration_controller->needs_settings_migration();

		update_option( 'wcshipping_previous_woocommerce_version', $current_wc_version, false );

		// Bail early if no migration is needed.
		if ( ! $wcst_needs_labels_migration && ! $wcst_needs_settings_migration ) {
			return;
		}

		// Only update the migration type if it's not already set.
		// This is to prevent the migration type from being reset if the user has already started the migration.
		$type = MigrationState::get_data_migration_required_type();
		if ( ! $type ) {
			$migration_required_type = MigrationState::NO_TYPE;
			if ( $wcst_needs_labels_migration && $wcst_needs_settings_migration ) {
				$migration_required_type = MigrationState::ALL_TYPE;
			} elseif ( $wcst_needs_labels_migration ) {
				$migration_required_type = MigrationState::LABELS_TYPE;
			} elseif ( $wcst_needs_settings_migration ) {
				$migration_required_type = MigrationState::SETTINGS_TYPE;
			}
			MigrationState::set_data_migration_required_type( $migration_required_type );
		}
	}

	/**
	 * Handle the form submission to start the data migration process.
	 */
	public function handle_migration_form_submission() {
		if ( current_user_can( 'manage_woocommerce' ) && isset( $_POST['wcst_start_migration'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is not needed here, we just check for existence
			switch ( MigrationState::get_data_migration_required_type() ) {
				case MigrationState::SETTINGS_TYPE:
					MigrationState::set_state( MigrationState::DATA_MIGRATION_STARTED );
					$this->migration_controller->migrate_settings();
					break;
				case MigrationState::LABELS_TYPE:
					MigrationState::set_state( MigrationState::DATA_MIGRATION_STARTED );
					$this->migration_controller->migrate_labels();
					break;
				case MigrationState::ALL_TYPE:
					MigrationState::set_state( MigrationState::DATA_MIGRATION_STARTED );
					$this->migration_controller->migrate_all();
					break;
				default:
					return;
			}
		}
	}

	public function init_analytics() {
		if ( is_admin() && current_user_can( 'manage_woocommerce' ) ) {
			( new ShippingLabel() )->init();
		}
	}

	/**
	 * Add return label email class to WooCommerce email classes.
	 *
	 * @param array $email_classes Array of email class names.
	 * @return array Modified array of email class names.
	 */
	public function add_return_label_email_class( $email_classes ) {
		// Include the customer return label email class.
		require_once WCSHIPPING_PLUGIN_DIR . '/src/Emails/WC_Return_Label_Email.php';

		// Add the customer email class to the list of email classes.
		$email_classes['WC_Return_Label_Email'] = new \Automattic\WCShipping\Emails\WC_Return_Label_Email();

		// Include the admin return label email class.
		require_once WCSHIPPING_PLUGIN_DIR . '/src/Emails/WC_Admin_Return_Label_Email.php';

		// Add the admin email class to the list of email classes.
		$email_classes['WC_Admin_Return_Label_Email'] = new \Automattic\WCShipping\Emails\WC_Admin_Return_Label_Email();

		return $email_classes;
	}

	/**
	 * Clean up temporary file.
	 *
	 * @param string $filepath Path to file to delete.
	 */
	public function cleanup_temp_file( $filepath ) {
		if ( file_exists( $filepath ) ) {
			wp_delete_file( $filepath );
		}
	}

	/**
	 * Send return label email with delay (for labels that were in progress).
	 *
	 * @param int   $order_id Order ID.
	 * @param array $label_meta Label metadata.
	 */
	public function send_return_label_email_delayed( $order_id, $label_meta ) {
		// Get fresh label data to check current status.
		$labels = $this->service_settings_store->get_label_order_meta_data( $order_id );

		if ( empty( $labels ) ) {
			return;
		}

		// Find the matching label.
		$current_label = null;
		foreach ( $labels as $label ) {
			if ( isset( $label['label_id'] ) && $label['label_id'] === $label_meta['label_id'] ) {
				$current_label = $label;
				break;
			}
		}

		if ( ! $current_label ) {
			return;
		}

		// Check if label is now ready.
		$label_purchase_service = $this->get_label_purchase_service_instance();

		if ( ! $label_purchase_service ) {
			return;
		}

		if ( isset( $current_label['status'] ) && 'PURCHASE_IN_PROGRESS' === $current_label['status'] ) {
			$updated_label = $current_label;

			if ( ! empty( $current_label['label_id'] ) ) {
				$status_response = $label_purchase_service->get_status( $current_label['label_id'] );
				if ( ! is_wp_error( $status_response ) && isset( $status_response->label ) ) {
					$maybe_updated = $label_purchase_service->update_order_label( $order_id, $status_response->label );
					if ( is_array( $maybe_updated ) ) {
						$updated_label = $maybe_updated;
					}
				}
			}

			if ( isset( $updated_label['status'] ) && 'PURCHASE_IN_PROGRESS' === $updated_label['status'] ) {
				if ( function_exists( 'as_schedule_single_action' ) ) {
					as_schedule_single_action(
						time() + 60, // Try again in 1 minute
						'wcshipping_send_return_label_email_delayed',
						array( $order_id, $updated_label ),
						'wcshipping'
					);
				} else {
					wp_schedule_single_event(
						time() + 60,
						'wcshipping_send_return_label_email_delayed',
						array( $order_id, $updated_label )
					);
				}
				return;
			}

			$current_label = $updated_label;
		}

		$attachments = array();

		// Try to get the PDF.
		if ( ! empty( $current_label['label_id'] ) ) {
			$pdf_method = new \ReflectionMethod( $label_purchase_service, 'get_label_pdf_for_email' );
			$pdf_method->setAccessible( true );
			$pdf_attachment = $pdf_method->invoke( $label_purchase_service, $current_label['label_id'], $order_id );

			if ( ! is_wp_error( $pdf_attachment ) && ! empty( $pdf_attachment ) ) {
				$attachments[] = $pdf_attachment;
			}
		}

		// Ensure WooCommerce emails are loaded.
		if ( ! did_action( 'woocommerce_email' ) ) {
			WC()->mailer();
		}

		// Check if any hooks are attached to our action.

		// Trigger the email.
		do_action( 'wcshipping_return_label_created', $order_id, $current_label, $attachments );

		// Clean up attachment.
		if ( ! empty( $attachments ) ) {
			foreach ( $attachments as $attachment ) {
				if ( function_exists( 'as_schedule_single_action' ) ) {
					as_schedule_single_action(
						time() + 300,
						'wcshipping_cleanup_temp_file',
						array( $attachment ),
						'wcshipping'
					);
				} else {
					wp_schedule_single_event( time() + 300, 'wcshipping_cleanup_temp_file', array( $attachment ) );
				}
			}
		}
	}

	/**
	 * @return LabelPurchaseService|null
	 */
	private function get_label_purchase_service_instance() {
		if ( $this->label_purchase_service instanceof LabelPurchaseService ) {
			return $this->label_purchase_service;
		}

		if ( ! $this->service_settings_store || ! $this->api_client || ! $this->shipping_label || ! $this->logger ) {
			return null;
		}

		$promo_service = $this->promo_service;
		if ( ! $promo_service ) {
			if ( ! $this->service_schemas_store ) {
				return null;
			}
			$promo_service       = new PromoService( $this->service_schemas_store, $this->service_settings_store );
			$this->promo_service = $promo_service;
		}

		$fulfillments_service       = $this->fulfillments_service ?? new FulfillmentsService();
		$this->fulfillments_service = $fulfillments_service;

		$this->label_purchase_service = new LabelPurchaseService(
			$this->service_settings_store,
			$this->api_client,
			$this->shipping_label,
			$this->logger,
			$promo_service,
			$fulfillments_service
		);

		return $this->label_purchase_service;
	}
}
