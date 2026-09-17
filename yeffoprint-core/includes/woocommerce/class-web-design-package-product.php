<?php
/**
 * Keeps one hidden WooCommerce simple product in sync per Web Design
 * Package (yp_web_design_pkg) — direct request: "I need to create the
 * web design package items on the site to be able to charge people.
 * Keep them in sync so if I change the package options on my dashboard,
 * it updates pricing/names/etc." Same shape as class-linked-product.php's
 * one-hidden-product-per-Template sync, adapted for a service rather
 * than a made-to-order physical good.
 *
 * Packages are still sold via a quote conversation, not self-serve
 * checkout (patterns/web-design-packages.php's own docblock: "scope
 * varies too much per client for a fixed price") — this product is
 * never linked to from the storefront and is catalog-hidden, so it
 * can't be bought by browsing. What it's actually for: once staff and
 * a customer land on a price, the package now exists as a real,
 * correctly-priced WooCommerce product they can search for and add to
 * a normal order from wp-admin (Orders → Add New), then send that
 * order's own "Pay for order" link — the same card/Venmo/Zelle/Coinbase
 * checkout every other manually-created order in this store already
 * uses, so no new payment plumbing was needed for this at all.
 *
 * Only published once the package itself is published AND has a real
 * checkout price set (> 0) — a package the owner hasn't priced yet
 * stays a draft product, so it can't accidentally turn up in that
 * order-screen product search as a free line item. Never hard-deleted,
 * same reasoning as Linked Product: a past order can still reference it
 * by ID.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Package_Product {

	public const META_LINKED_PRODUCT = '_yp_linked_product_id';
	public const META_PACKAGE_ID     = '_yp_web_design_pkg_id';

	public function __construct() {
		add_action( 'save_post_yp_web_design_pkg', [ $this, 'sync' ], 30 );
		add_action( 'trashed_post', [ $this, 'draft_linked_product' ] );
	}

	public function sync( int $package_id ): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$package = get_post( $package_id );
		if ( ! $package || wp_is_post_revision( $package_id ) || wp_is_post_autosave( $package_id ) ) {
			return;
		}

		$price = (float) get_post_meta( $package_id, YeffoPrint_Web_Design_Package_Meta::CHECKOUT_PRICE, true );

		$product_id = (int) get_post_meta( $package_id, self::META_LINKED_PRODUCT, true );
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product ) {
			$product = new \WC_Product_Simple();
		}

		$is_chargeable = 'publish' === $package->post_status && $price > 0;

		$product->set_name( $package->post_title );
		$product->set_status( $is_chargeable ? 'publish' : 'draft' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->set_sold_individually( true );
		$product->set_virtual( true );
		$product->set_regular_price( $price > 0 ? (string) $price : '' );
		$product->set_price( $price > 0 ? (string) $price : '' );

		$product->update_meta_data( self::META_PACKAGE_ID, $package_id );
		$new_product_id = $product->save();

		if ( $new_product_id && $new_product_id !== $product_id ) {
			update_post_meta( $package_id, self::META_LINKED_PRODUCT, $new_product_id );
		}
	}

	/** A trashed package's linked product is drafted, not deleted — mirrors class-linked-product.php's own "never hard-deleted, a past order still references it by ID" rule. */
	public function draft_linked_product( int $package_id ): void {
		if ( 'yp_web_design_pkg' !== get_post_type( $package_id ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product_id = self::get_linked_product_id( $package_id );
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( $product ) {
			$product->set_status( 'draft' );
			$product->save();
		}
	}

	public static function get_linked_product_id( int $package_id ): int {
		return (int) get_post_meta( $package_id, self::META_LINKED_PRODUCT, true );
	}
}
