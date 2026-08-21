<?php
/**
 * Makes the card-surcharge fee (class-card-surcharge.php) reactive on
 * the block-based Checkout page. Without this, a customer switching
 * payment methods there never sees the fee change until something else
 * happens to recalculate the cart (e.g. a page refresh) — the Blocks
 * Checkout talks to WooCommerce entirely through the Store API, which
 * never writes `chosen_payment_method` into the session the way the
 * classic checkout's `update_order_review` AJAX call does, so
 * class-card-surcharge.php's own read of that session value goes stale
 * the moment the customer clicks a different gateway.
 *
 * Two pieces, both required:
 *
 *  - PHP (here): registers a Store API "cart extension" update
 *    callback. Calling it (via `POST /wc/store/v1/cart/extensions`)
 *    writes whatever payment method the client sends into the session,
 *    and WooCommerce Blocks itself then recalculates cart totals in
 *    that same request/response — which re-runs
 *    class-card-surcharge.php's `woocommerce_cart_calculate_fees` hook
 *    against the now-current session value and returns the updated
 *    cart, fee included, straight back to the client.
 *  - JS (assets/blocks/card-surcharge.js): watches the Checkout
 *    block's own `wc/store/payment` data store for the customer's
 *    active payment method and calls that extension endpoint
 *    (`extensionCartUpdate`, from the `@woocommerce/blocks-checkout`
 *    package) whenever it changes.
 *
 * See class-manual-payment-blocks-support.php for the parallel,
 * differently-shaped gap between the classic and block checkouts that
 * class exists to close.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Card_Surcharge_Blocks_Integration {

	// Must match the NAMESPACE constant assets/blocks/card-surcharge.js sends.
	const NAMESPACE = 'yeffoprint-card-surcharge';

	public function __construct() {
		// `ExtendSchema` (which register_update_callback() reaches into)
		// only exists once WooCommerce Blocks has finished its own
		// bootstrap — this is the hook it fires to announce that.
		add_action( 'woocommerce_blocks_loaded', [ $this, 'register_update_callback' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function register_update_callback(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		woocommerce_store_api_register_update_callback( [
			'namespace' => self::NAMESPACE,
			'callback'  => [ $this, 'set_chosen_payment_method' ],
		] );
	}

	/**
	 * @param array<string, mixed> $data The extension's own `data` payload
	 *        from the `/wc/store/v1/cart/extensions` request.
	 */
	public function set_chosen_payment_method( $data ): void {
		if ( ! WC()->session ) {
			return;
		}

		$method = is_array( $data ) && isset( $data['payment_method'] ) ? sanitize_key( (string) $data['payment_method'] ) : '';
		WC()->session->set( 'chosen_payment_method', $method );
	}

	public function enqueue(): void {
		if ( ! is_checkout() ) {
			return;
		}

		$path = 'assets/blocks/card-surcharge.js';
		wp_enqueue_script(
			'yeffoprint-card-surcharge',
			YEFFOPRINT_CORE_URL . $path,
			[ 'wp-data', 'wc-blocks-checkout' ],
			yeffoprint_core_asset_version( $path ),
			true
		);
	}
}
