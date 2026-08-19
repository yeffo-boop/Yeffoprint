<?php
/**
 * The one WooCommerce product representing "the labels themselves" on
 * a Custom Design order — separate from YeffoPrint_Custom_Design_Fee_Product
 * (the flat $25 design fee). A Custom Order has no premade Template to
 * anchor a YeffoPrint_Linked_Product to, but the customer still picks
 * a size/material/quantity and needs to pay for that print run exactly
 * like a normal batch does — same per-unit formula
 * (YeffoPrint_Pricing_Rule::calculate(), base + size/material
 * adjustments, bulk discount tiers), just without a Template behind it.
 *
 * Exactly one of these ever exists (like the fee product), not one per
 * CustomOrder — the product is only an anchor WooCommerce's cart/order
 * APIs need; the real price is always computed live from the cart
 * item's own size/material/quantity data (class-cart-pricing.php),
 * never read from the product itself.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Custom_Order_Labels_Product {

	private const OPTION_KEY = 'yeffoprint_custom_order_labels_product_id';

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
		$product->set_name( __( 'Custom Order Labels', 'yeffoprint-core' ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' ); // Made-to-order, same as every linked Template product.
		$product->set_sold_individually( false );
		$product->set_virtual( false ); // These get printed and shipped, unlike the design fee.
		$product->set_regular_price( '0' );
		$product->set_price( '0' );

		$product_id = $product->save();

		if ( $product_id ) {
			update_option( self::OPTION_KEY, $product_id );
		}

		return (int) $product_id;
	}
}
