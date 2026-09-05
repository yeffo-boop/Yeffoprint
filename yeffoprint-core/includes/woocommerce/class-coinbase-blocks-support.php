<?php
/**
 * Checkout-block (Store API) registration for Coinbase Commerce — see
 * class-manual-payment-blocks-support.php's own docblock for why a
 * custom WC_Payment_Gateway needs this at all on this site (the classic
 * gateway works everywhere except actually showing up as a selectable
 * option on this site's block-based Checkout page). Simpler than the
 * Venmo/Zelle version: there's no "send payment to" handle to surface
 * here, just the standard title/description every gateway has.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Coinbase_Blocks_Support extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	protected $name = 'yeffoprint_coinbase';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', [] );
	}

	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'yeffoprint-coinbase-blocks',
			YEFFOPRINT_CORE_URL . 'assets/blocks/coinbase-payment-method.js',
			[ 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ],
			yeffoprint_core_asset_version( 'assets/blocks/coinbase-payment-method.js' ),
			true
		);

		return [ 'yeffoprint-coinbase-blocks' ];
	}

	public function get_payment_method_data() {
		return [
			'title'       => $this->settings['title'] ?? '',
			'description' => $this->settings['description'] ?? '',
		];
	}
}
