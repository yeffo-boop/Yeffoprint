<?php
/**
 * Registers Venmo/Zelle with the Checkout *block* (Store API), a
 * completely separate registration from the classic WC_Payment_Gateway
 * itself (class-manual-payment-gateway.php) — a real gap found live:
 * the gateways worked (enabled, saved, appeared in wp-admin, even
 * present in the checkout page's initial server-rendered HTML) but
 * never showed as a selectable option, because this site's Checkout
 * page uses the block-based Checkout, not the classic
 * `[woocommerce_checkout]` shortcode. Core WooCommerce gateways (BACS,
 * Cheque, COD) ship this same registration built into WooCommerce
 * itself, which is why *those* worked while a plain custom
 * WC_Payment_Gateway didn't — third-party gateways have to add it
 * themselves. See docs/ARCHITECTURE.md §9.
 */

defined( 'ABSPATH' ) || exit;

abstract class YeffoPrint_Manual_Payment_Blocks_Support extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	protected $name;

	abstract protected function script_handle(): string;

	abstract protected function script_path(): string;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', [] );
	}

	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	public function get_payment_method_script_handles() {
		$handle = $this->script_handle();

		wp_register_script(
			$handle,
			YEFFOPRINT_CORE_URL . $this->script_path(),
			[ 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ],
			yeffoprint_core_asset_version( $this->script_path() ),
			true
		);

		return [ $handle ];
	}

	public function get_payment_method_data() {
		return [
			'title'       => $this->settings['title'] ?? '',
			'description' => $this->settings['description'] ?? '',
		];
	}
}
