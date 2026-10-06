<?php
/**
 * Fills the gaps the Meta for WooCommerce plugin's pixel/Conversions API
 * leaves on this store's custom pages.
 *
 * The plugin only knows WooCommerce's own templates:
 *
 * - ViewContent fires on `woocommerce_after_single_product`, which never
 *   runs here: a label's page is a Template (yp_template) and a 3D print's
 *   is a yp_print, not a WooCommerce product page. So Meta never saw a
 *   product view. On those pages we run the plugin's own ViewContent
 *   handler against the hidden linked product (class-linked-product.php,
 *   class-print-product.php), so the event, its event_id dedup and its
 *   Conversions API copy all stay the plugin's.
 *
 * - AddToCart values the item at `$product->get_price() × quantity`, read
 *   from a fresh product. Linked label products are priced $0 (the real
 *   price is computed live in class-cart-pricing.php), so every label
 *   AddToCart reached Meta as $0, and a 3D print's ignored its size and
 *   add-ons. Just around the plugin's handler we price that product at
 *   the line's real unit price.
 *
 * Everything here no-ops when the plugin isn't active.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Meta_Pixel {

	/** The Meta plugin hooks its AddToCart handler at this priority. */
	private const PLUGIN_ADD_TO_CART_PRIORITY = 40;

	private int $priced_product_id = 0;
	private float $priced_unit     = 0.0;

	public function __construct() {
		// Before the plugin's footer scripts print (wp_footer 20).
		add_action( 'wp_footer', [ $this, 'view_content' ], 1 );

		add_action( 'woocommerce_add_to_cart', [ $this, 'price_add_to_cart' ], self::PLUGIN_ADD_TO_CART_PRIORITY - 1, 6 );
		add_action( 'woocommerce_add_to_cart', [ $this, 'unprice_add_to_cart' ], self::PLUGIN_ADD_TO_CART_PRIORITY + 1 );
	}

	public function view_content(): void {
		if ( ! function_exists( 'facebook_for_woocommerce' ) || ! is_singular( [ 'yp_template', 'yp_print' ] ) ) {
			return;
		}

		$handler = self::plugin_handler( 'woocommerce_after_single_product', 'inject_view_content_event' );
		if ( ! $handler ) {
			return;
		}

		$page_id    = get_queried_object_id();
		$product_id = 'yp_print' === get_post_type( $page_id )
			? YeffoPrint_Print_Product::get_linked_product_id( $page_id )
			: YeffoPrint_Linked_Product::get_linked_product_id( $page_id );
		$product    = $product_id ? get_post( $product_id ) : null;
		if ( ! $product || 'publish' !== $product->post_status ) {
			return;
		}

		// The handler reads the product from global $post.
		global $post;
		$page_post = $post;
		$post      = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		try {
			call_user_func( $handler );
		} finally {
			$post = $page_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public function price_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ): void {
		$this->priced_product_id = 0;

		if ( ! function_exists( 'facebook_for_woocommerce' ) || ! WC()->cart ) {
			return;
		}

		$cart_item = WC()->cart->cart_contents[ $cart_item_key ] ?? null;
		if ( ! $cart_item ) {
			return;
		}

		$unit_price = YeffoPrint_Cart_Pricing::unit_price_for_cart_item(
			$cart_item,
			YeffoPrint_Cart_Pricing::combined_label_quantity( WC()->cart ),
			YeffoPrint_Cart_Pricing::combined_sticker_quantity( WC()->cart )
		);
		if ( null === $unit_price ) {
			return;
		}

		$this->priced_product_id = (int) ( $variation_id ?: $product_id );
		$this->priced_unit       = $unit_price;
		add_filter( 'woocommerce_product_get_price', [ $this, 'filter_price' ], 99, 2 );
	}

	public function unprice_add_to_cart(): void {
		remove_filter( 'woocommerce_product_get_price', [ $this, 'filter_price' ], 99 );
		$this->priced_product_id = 0;
	}

	public function filter_price( $price, $product ) {
		return $product instanceof \WC_Product && $product->get_id() === $this->priced_product_id
			? $this->priced_unit
			: $price;
	}

	/** The plugin's own callback on a hook, found by method name so no private property is touched. */
	private static function plugin_handler( string $hook, string $method ): ?callable {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) ) {
			return null;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && is_object( $function[0] ) && $method === $function[1]
					&& $function[0] instanceof \WC_Facebookcommerce_EventsTracker ) {
					return $function;
				}
			}
		}

		return null;
	}
}
