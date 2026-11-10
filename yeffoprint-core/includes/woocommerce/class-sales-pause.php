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
 * 3D prints stay open: they use a different printer (Jeff: "3d prints
 * do use a different printer"), so print items can still be added and a
 * cart holding only 3D prints can still check out.
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
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'block_add_to_cart' ], 1, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 1, 2 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', [ $this, 'block_store_api_add' ], 1 );

		// Checkout: block (Store API) and classic.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'block_store_api_checkout' ], 1 );
		add_action( 'woocommerce_store_api_cart_errors', [ $this, 'cart_errors' ], 1 );
		add_action( 'woocommerce_checkout_process', [ $this, 'block_classic_checkout' ], 1 );

		add_action( 'wp_head', [ $this, 'hide_buy_buttons' ] );
		add_filter( 'body_class', [ $this, 'body_class' ] );
	}

	public static function is_paused(): bool {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	public static function default_message(): string {
		return __( 'We’re not taking label or sticker orders right now while our printer is being repaired. 3D prints are still available. Please check back soon — thanks for your patience!', 'yeffoprint-core' );
	}

	/** Saved through sanitize_text_field() (no tags), so it's safe in notices and errors as is. */
	public static function message(): string {
		$message = trim( (string) get_option( self::MESSAGE_OPTION, '' ) );
		return '' !== $message ? $message : self::default_message();
	}

	/** 3D print products are exempt (different printer). */
	private static function is_print_product( $product_id ): bool {
		return class_exists( 'YeffoPrint_Print_Product' ) && YeffoPrint_Print_Product::get_print_id( (int) $product_id ) > 0;
	}

	/** True when the cart holds anything other than 3D prints, i.e. checkout must be refused. */
	private static function cart_has_paused_items(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item[ YeffoPrint_Cart_Item_Keys::PRINT_ID ] ) && ! self::is_print_product( $item['product_id'] ?? 0 ) ) {
				return true;
			}
		}
		return false;
	}

	private static function checkout_message(): string {
		return self::message() . ' ' . __( 'Remove those items from your cart to check out your 3D prints.', 'yeffoprint-core' );
	}

	/** @throws Exception When sales are paused and this isn't a 3D print. */
	public function block_add_to_cart( $cart_item_data, $product_id = 0 ) {
		if ( self::is_paused() && empty( $cart_item_data[ YeffoPrint_Cart_Item_Keys::PRINT_ID ] ) && ! self::is_print_product( $product_id ) ) {
			throw new Exception( self::message() );
		}
		return $cart_item_data;
	}

	public function validate_add_to_cart( $passed, $product_id = 0 ) {
		if ( $passed && self::is_paused() && ! self::is_print_product( $product_id ) ) {
			wc_add_notice( self::message(), 'error' );
			return false;
		}
		return $passed;
	}

	private static function route_exception( string $message ): void {
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'yeffoprint_sales_paused', $message, 403 );
		}
	}

	public function block_store_api_add( $product = null ): void {
		$product_id = $product instanceof \WC_Product ? ( $product->get_parent_id() ?: $product->get_id() ) : 0;
		if ( self::is_paused() && ! self::is_print_product( $product_id ) ) {
			self::route_exception( self::message() );
		}
	}

	public function block_store_api_checkout(): void {
		if ( self::is_paused() && self::cart_has_paused_items() ) {
			self::route_exception( self::checkout_message() );
		}
	}

	public function cart_errors( $errors ): void {
		if ( self::is_paused() && $errors instanceof \WP_Error && self::cart_has_paused_items() ) {
			$errors->add( 'yeffoprint_sales_paused', self::checkout_message() );
		}
	}

	public function block_classic_checkout(): void {
		if ( self::is_paused() && self::cart_has_paused_items() ) {
			wc_add_notice( self::checkout_message(), 'error' );
		}
	}

	public function body_class( array $classes ): array {
		if ( self::is_paused() ) {
			$classes[] = 'yp-sales-paused';
		}
		return $classes;
	}

	/**
	 * Hides the standard WooCommerce buy buttons (3D print pages use their
	 * own button, so they're unaffected), and the checkout buttons only
	 * while the cart holds something other than 3D prints.
	 */
	public function hide_buy_buttons(): void {
		if ( ! self::is_paused() ) {
			return;
		}
		$checkout = self::cart_has_paused_items()
			? ',.yp-sales-paused .wc-block-cart__submit-container,.yp-sales-paused .wc-block-components-checkout-place-order-button,.yp-sales-paused #place_order'
			: '';
		echo '<style id="yp-sales-paused-css">'
			. '.yp-sales-paused .single_add_to_cart_button,'
			. '.yp-sales-paused .add_to_cart_button,'
			. '.yp-sales-paused .wc-block-components-product-button'
			. $checkout
			. '{display:none!important}'
			. '.yp-paused-bar{background:#1f1f24;color:#fff;padding:12px 16px;text-align:center;font-weight:600;line-height:1.4}'
			. '.yp-paused-bar p{margin:0}'
			. '</style>';
	}
}
