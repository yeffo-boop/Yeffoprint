<?php
/**
 * The one WooCommerce product representing the $25 custom design fee
 * (PROJECT_SPEC §13). Unlike YeffoPrint_Linked_Product (one per
 * Template), there's exactly one of these ever — created lazily on
 * first use and reused, tracked by a single option rather than a
 * per-record meta lookup. Hidden from the shop the same way linked
 * Template products are; price is always read live from
 * YeffoPrint_Pricing_Rule::get_custom_design_fee() at cart-calculation
 * time (class-cart-pricing.php), never cached on the product itself,
 * so an admin changing the fee is reflected immediately.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Custom_Design_Fee_Product {

	private const OPTION_KEY = 'yeffoprint_custom_design_fee_product_id';

	/** Read-only — never creates the product. Safe to call from validation hooks that run on every add-to-cart. */
	public static function get_existing_product_id(): int {
		return (int) get_option( self::OPTION_KEY );
	}

	public static function get_product_id(): int {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$product_id = self::get_existing_product_id();
		if ( $product_id && wc_get_product( $product_id ) ) {
			return $product_id;
		}

		$product = new \WC_Product_Simple();
		$product->set_name( __( 'Custom Design Fee', 'yeffoprint-core' ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->set_sold_individually( true );
		$product->set_virtual( true ); // The fee itself has nothing to ship; the eventual print order does.
		$product->set_regular_price( '0' );
		$product->set_price( '0' );

		$product_id = $product->save();

		if ( $product_id ) {
			update_option( self::OPTION_KEY, $product_id );
		}

		return (int) $product_id;
	}
}
