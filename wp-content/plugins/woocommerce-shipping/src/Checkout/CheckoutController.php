<?php
/**
 * CheckoutController class.
 *
 * Controller class for checkout-related hooks.
 *
 * @package Automattic/WCShipping
 */

namespace Automattic\WCShipping\Checkout;

use Automattic\WCShipping\Connect\WC_Connect_Logger;
use Automattic\WCShipping\Connect\WC_Connect_Service_Settings_Store;
use Automattic\WCShipping\LabelPurchase\AddressNormalizationService;
use Automattic\WCShipping\Utils;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Class CheckoutController
 */
class CheckoutController {

	/**
	 * Checkout service.
	 *
	 * @var CheckoutService
	 */
	private CheckoutService $checkout_service;

	/**
	 * Notifier instance.
	 *
	 * @var CheckoutNotifier
	 */
	private CheckoutNotifier $notifier;

	/**
	 * The settings store.
	 *
	 * @var WC_Connect_Service_Settings_Store
	 */
	private WC_Connect_Service_Settings_Store $settings_store;

	/**
	 * Address normalization service.
	 *
	 * @var AddressNormalizationService
	 */
	private AddressNormalizationService $address_normalization_service;

	/**
	 * CheckoutController constructor.
	 *
	 * @param WC_Connect_Logger                 $wc_connect_logger The WC_Connect_Logger instance.
	 * @param CheckoutService                   $checkout_service Checkout service.
	 * @param WC_Connect_Service_Settings_Store $settings_store The settings store.
	 * @param AddressNormalizationService       $address_normalization_service Address normalization service.
	 */
	public function __construct(
		WC_Connect_Logger $wc_connect_logger,
		CheckoutService $checkout_service,
		WC_Connect_Service_Settings_Store $settings_store,
		AddressNormalizationService $address_normalization_service
	) {
		$this->checkout_service              = $checkout_service;
		$this->notifier                      = new CheckoutNotifier( $wc_connect_logger->is_debug_enabled() );
		$this->settings_store                = $settings_store;
		$this->address_normalization_service = $address_normalization_service;

		add_action( 'wp_enqueue_scripts', array( $this, 'load_assets' ) );
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'maybe_display_address_validation_notices' ) );
		add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'print_billing_address_validation_notice_container' ) );
		add_action( 'woocommerce_after_checkout_shipping_form', array( $this, 'print_shipping_address_validation_notice_container' ) );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'maybe_set_destination_normalized_order_meta' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'maybe_set_destination_normalized_order_meta' ) );
		add_filter( 'woocommerce_shipping_packages', array( $this, 'maybe_add_address_validation_notices' ) );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'add_address_validation_notice_fragment' ) );
	}

	/**
	 * Load assets.
	 */
	public function load_assets() {
		if ( ! CheckoutService::is_address_validation_enabled() || ! CheckoutService::is_checkout_page() ) {
			return;
		}

		wp_enqueue_style(
			'wcshipping-checkout',
			Utils::get_enqueue_base_url() . 'woocommerce-shipping-checkout-address-validation.css',
			array(),
			Utils::get_file_version( WCSHIPPING_PLUGIN_DIST_DIR . 'woocommerce-shipping-checkout-address-validation.css' )
		);

		if ( ! has_block( 'woocommerce/checkout' ) ) {
			$address_validation_handle = 'woocommerce-shipping-checkout-address-validation-classic';
			$script_asset_path         = WCSHIPPING_PLUGIN_DIST_DIR . $address_validation_handle . '.asset.php';
			$script_asset              = file_exists( $script_asset_path )
				? require $script_asset_path : array(); // nosemgrep: audit.php.lang.security.file.inclusion-arg --- This is a safe file inclusion.
			$script_path               = WCSHIPPING_PLUGIN_DIST_DIR . $address_validation_handle . '.js';
			// The classic script listens to WooCommerce checkout jQuery events.
			$script_dependencies = array_values(
				array_unique(
					array_merge(
						array( 'jquery' ),
						Utils::filter_dev_dependencies( $script_asset['dependencies'] ?? array() )
					)
				)
			);
			$script_version      = $script_asset['version'] ?? Utils::get_file_version( $script_path );

			wp_enqueue_script(
				$address_validation_handle,
				Utils::get_enqueue_base_url() . $address_validation_handle . '.js',
				$script_dependencies,
				$script_version,
				array(
					'in_footer' => true,
				)
			);

			wp_set_script_translations( $address_validation_handle, 'woocommerce-shipping', WCSHIPPING_PLUGIN_DIR . '/languages' );
		}

		$handle = 'wcshipping-checkout';

		wp_register_script(
			$handle,
			WCSHIPPING_JAVASCRIPT_URL . 'checkout.js',
			array( 'wp-i18n' ),
			Utils::get_file_version( WCSHIPPING_JAVASCRIPT_DIR . 'checkout.js' ),
			true
		);

		wp_localize_script(
			$handle,
			'wcShippingSettings',
			array_merge(
				Utils::get_settings_object(),
				array(
					'checkout' => CheckoutService::get_checkout_script_data(),
				)
			)
		);

		wp_enqueue_script( $handle );
	}

	/**
	 * Maybe display address validation notices.
	 */
	public function maybe_display_address_validation_notices() {
		if ( ! CheckoutService::is_address_validation_enabled() || ! CheckoutService::is_checkout_page() ) {
			return;
		}

		if ( CheckoutService::is_update_order_review_request() ) {
			return;
		}

		$this->notifier->print_notices();
		$this->notifier->clear_notices();
	}

	/**
	 * Print the classic checkout billing target for address validation notices.
	 */
	public function print_billing_address_validation_notice_container() {
		$this->print_address_validation_notice_container_for_address_type( 'billing' );
	}

	/**
	 * Print the classic checkout shipping target for address validation notices.
	 */
	public function print_shipping_address_validation_notice_container() {
		$this->print_address_validation_notice_container_for_address_type( 'shipping' );
	}

	/**
	 * Print a classic checkout target for address validation notices.
	 */
	public function print_address_validation_notice_container() {
		$this->print_shipping_address_validation_notice_container();
	}

	/**
	 * Print a classic checkout target for a given address type.
	 *
	 * @param string $address_type The checkout address section.
	 */
	private function print_address_validation_notice_container_for_address_type( string $address_type ) {
		if ( ! CheckoutService::is_address_validation_enabled() || ! CheckoutService::is_checkout_page() ) {
			return;
		}

		printf(
			'<div class="wcshipping-checkout-address-validation-notices wcshipping-checkout-address-validation-notices--%s" aria-live="polite"></div>',
			esc_attr( $address_type )
		);
	}

	/**
	 * Add address validation notices as a checkout fragment during update_order_review.
	 *
	 * WooCommerce core marks update_order_review as failed whenever the global
	 * notice queue has any message. Returning WC Shipping's soft address
	 * validation notices as a dedicated fragment keeps the classic checkout
	 * response successful and avoids core's failure branch re-blurring fields.
	 *
	 * @param array $fragments Checkout fragments.
	 *
	 * @return array
	 */
	public function add_address_validation_notice_fragment( array $fragments ): array {
		if (
			! CheckoutService::is_address_validation_enabled()
			|| ! CheckoutService::is_classic_checkout()
			|| ! CheckoutService::is_update_order_review_request()
		) {
			return $fragments;
		}

		$address_type = $this->get_classic_notice_address_type();
		$selector     = sprintf( '.wcshipping-checkout-address-validation-notices--%s', $address_type );

		$fragments[ $selector ] = sprintf(
			'<div class="wcshipping-checkout-address-validation-notices wcshipping-checkout-address-validation-notices--%1$s" aria-live="polite">%2$s</div>',
			esc_attr( $address_type ),
			$this->notifier->get_notices_html( 'address-validation' )
		);

		$this->notifier->clear_notices( 'address-validation' );

		return $fragments;
	}

	/**
	 * Get the active classic checkout address section for address validation notices.
	 *
	 * @return string
	 */
	private function get_classic_notice_address_type(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies checkout updates.
		if ( ! isset( $_POST['post_data'] ) ) {
			return 'billing';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce verifies checkout updates and the decoded post data is sanitized before parsing.
		$sanitized_post_data = wc_clean( urldecode( wp_unslash( $_POST['post_data'] ) ) );
		$post_data           = array();
		parse_str( $sanitized_post_data, $post_data );

		if (
			isset( $post_data['ship_to_different_address'] )
			&& true === wc_string_to_bool( $post_data['ship_to_different_address'] )
		) {
			return 'shipping';
		}

		return 'billing';
	}

	/**
	 * Maybe set destination normalized order meta.
	 *
	 * @param int|WC_Order $order_id_or_object The order ID or WC_Order instance depending on the context.
	 */
	public function maybe_set_destination_normalized_order_meta( $order_id_or_object ) {
		if ( ! CheckoutService::is_address_validation_enabled() ) {
			return;
		}

		if ( ! $this->checkout_service->get_destination_normalized_session_value() ) {
			return;
		}

		$order = wc_get_order( $order_id_or_object );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->address_normalization_service->set_is_destination_address_normalized( $order->get_id(), true );
	}

	/**
	 * If the right conditions are met, add address validation notices for entered shipping address.
	 *
	 * @param array $packages Shipping packages.
	 *
	 * @return array
	 */
	public function maybe_add_address_validation_notices( array $packages ): array {
		if (
			CheckoutService::is_address_validation_enabled()
			&& CheckoutService::is_classic_checkout()
		) {
			$this->add_address_validation_notices();
		}

		return $packages;
	}


	/**
	 * Add address validation notices for entered shipping address.
	 */
	private function add_address_validation_notices() {
		static $has_run = false;

		if ( $has_run ) {
			return;
		}

		$has_run = true;

		// Validate the shipping address.
		$response = $this->checkout_service->validate_shipping_address();
		if ( ! $response['success'] || empty( $response['notices'] ) ) {
			return;
		}

		foreach ( $response['notices'] as $notice ) {
			$this->notifier->info( $notice->get_message(), $notice->get_data() ?? array(), 'address-validation' );
		}
	}
}
