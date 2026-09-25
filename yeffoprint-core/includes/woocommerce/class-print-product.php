<?php
/**
 * Keeps one hidden WooCommerce simple product in sync per 3D Print
 * (yp_print) — same shape as class-web-design-package-product.php's
 * one-hidden-product-per-package sync. The product is only the anchor
 * WooCommerce's cart/order APIs need; the real price is always computed
 * live from the item's base price plus the picked colors' extra charges
 * (class-cart-pricing.php), and the only way into the cart is the
 * product page's own REST call (class-print-controller.php).
 *
 * Published only while the item itself is published and priced, so an
 * unfinished item can't turn up in wp-admin's order-screen product
 * search as a free line. Never hard-deleted: a past order still
 * references it by ID.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Print_Product {

	public const META_LINKED_PRODUCT = '_yp_linked_product_id';
	public const META_PRINT_ID       = '_yp_print_id';

	public function __construct() {
		add_action( 'save_post_yp_print', [ $this, 'sync' ], 30 );
		// Meta saved through the REST API lands after save_post fires —
		// see class-web-design-package-product.php for the full story.
		add_action( 'rest_after_insert_yp_print', [ $this, 'sync_from_rest' ] );
		add_action( 'trashed_post', [ $this, 'draft_linked_product' ] );
	}

	public function sync_from_rest( \WP_Post $post ): void {
		$this->sync( $post->ID );
	}

	public function sync( int $print_id ): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$print = get_post( $print_id );
		if ( ! $print || wp_is_post_revision( $print_id ) || wp_is_post_autosave( $print_id ) ) {
			return;
		}

		$price      = (float) get_post_meta( $print_id, YeffoPrint_Print_Meta::PRICE, true );
		$product_id = self::get_linked_product_id( $print_id );
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product ) {
			$product = new \WC_Product_Simple();
		}

		$is_sellable = 'publish' === $print->post_status && $price > 0;

		$product->set_name( $print->post_title );
		$product->set_status( $is_sellable ? 'publish' : 'draft' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' ); // Printed to order.
		$product->set_sold_individually( false );
		$product->set_virtual( false ); // Printed and shipped.
		$product->set_regular_price( $price > 0 ? (string) $price : '' );
		$product->set_price( $price > 0 ? (string) $price : '' );
		$product->set_image_id( (int) get_post_thumbnail_id( $print_id ) );

		$product->update_meta_data( self::META_PRINT_ID, $print_id );
		$new_product_id = $product->save();

		if ( $new_product_id && $new_product_id !== $product_id ) {
			update_post_meta( $print_id, self::META_LINKED_PRODUCT, $new_product_id );
		}
	}

	public function draft_linked_product( int $print_id ): void {
		if ( 'yp_print' !== get_post_type( $print_id ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product_id = self::get_linked_product_id( $print_id );
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( $product ) {
			$product->set_status( 'draft' );
			$product->save();
		}
	}

	public static function get_linked_product_id( int $print_id ): int {
		return (int) get_post_meta( $print_id, self::META_LINKED_PRODUCT, true );
	}

	/** The yp_print a linked product belongs to, or 0 if it isn't one. */
	public static function get_print_id( int $product_id ): int {
		return (int) get_post_meta( $product_id, self::META_PRINT_ID, true );
	}
}
