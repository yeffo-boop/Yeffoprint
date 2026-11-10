<?php
/**
 * Pause all sales — direct request: "I need a way to pause sales on the
 * site entirely. My printer is down until a tech comes out to look at
 * it. I don't want people ordering in the meantime."
 *
 * Unlike Away Mode (a delay notice only, orders still come in), this
 * stops new orders: nothing can be added to the cart and checkout
 * refuses to place an order, with a site-wide bar telling customers
 * why. Carts are left as they are (not emptied), so shoppers can finish
 * once sales are back on. Pay links for orders that already exist keep
 * working, and the admin app's Create Order is unaffected.
 *
 * One switch, off by default: Store › Quick switches, or Settings ›
 * General › Pause all sales (with the customer message).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Sales_Pause {

	const ENABLED_OPTION = 'yeffoprint_sales_paused';
	const MESSAGE_OPTION = 'yeffoprint_sales_paused_message';

	public function __construct() {
		// Every add-to-cart path in this plugin (custom labels, stickers,
		// 3D prints, templates, reorders) goes through WC_Cart::add_to_cart(),
		// which runs this filter inside its try block: an Exception there
		// becomes an error notice and add_to_cart() returns false, which
		// each REST controller already turns into a message for the shopper.
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'block_add_to_cart' ], 1 );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 1 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', [ $this, 'block_store_api' ], 1 );

		// Checkout: block (Store API) and classic.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'block_store_api' ], 1 );
		add_action( 'woocommerce_store_api_cart_errors', [ $this, 'cart_errors' ], 1 );
		add_action( 'woocommerce_checkout_process', [ $this, 'block_classic_checkout' ], 1 );

		add_action( 'wp_head', [ $this, 'hide_buy_buttons' ] );
		add_filter( 'body_class', [ $this, 'body_class' ] );
	}

	public static function is_paused(): bool {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	public static function default_message(): string {
		return __( 'We’re not taking new orders right now while our printer is being repaired. Please check back soon — thanks for your patience!', 'yeffoprint-core' );
	}

	/** Saved through sanitize_text_field() (no tags), so it's safe in notices and errors as is. */
	public static function message(): string {
		$message = trim( (string) get_option( self::MESSAGE_OPTION, '' ) );
		return '' !== $message ? $message : self::default_message();
	}

	/** @throws Exception When sales are paused. */
	public function block_add_to_cart( $cart_item_data ) {
		if ( self::is_paused() ) {
			throw new Exception( self::message() );
		}
		return $cart_item_data;
	}

	public function validate_add_to_cart( $passed ) {
		if ( $passed && self::is_paused() ) {
			wc_add_notice( self::message(), 'error' );
			return false;
		}
		return $passed;
	}

	public function block_store_api(): void {
		if ( self::is_paused() && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'yeffoprint_sales_paused', self::message(), 403 );
		}
	}

	public function cart_errors( $errors ): void {
		if ( self::is_paused() && $errors instanceof \WP_Error ) {
			$errors->add( 'yeffoprint_sales_paused', self::message() );
		}
	}

	public function block_classic_checkout(): void {
		if ( self::is_paused() ) {
			wc_add_notice( self::message(), 'error' );
		}
	}

	public function body_class( array $classes ): array {
		if ( self::is_paused() ) {
			$classes[] = 'yp-sales-paused';
		}
		return $classes;
	}

	/** Hides the standard buy buttons so nobody fills a cart they can't check out. */
	public function hide_buy_buttons(): void {
		if ( ! self::is_paused() ) {
			return;
		}
		echo '<style id="yp-sales-paused-css">'
			. '.yp-sales-paused .single_add_to_cart_button,'
			. '.yp-sales-paused .add_to_cart_button,'
			. '.yp-sales-paused .wc-block-components-product-button,'
			. '.yp-sales-paused .wc-block-cart__submit-container,'
			. '.yp-sales-paused .wc-block-components-checkout-place-order-button,'
			. '.yp-sales-paused #place_order'
			. '{display:none!important}'
			. '.yp-paused-bar{background:#1f1f24;color:#fff;padding:12px 16px;text-align:center;font-weight:600;line-height:1.4}'
			. '.yp-paused-bar p{margin:0}'
			. '</style>';
	}
}
