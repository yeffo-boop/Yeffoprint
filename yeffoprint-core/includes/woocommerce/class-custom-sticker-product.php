<?php
/**
 * The one WooCommerce product representing "the stickers themselves"
 * on a Custom Stickers order — same role as
 * YeffoPrint_Custom_Order_Labels_Product plays for Custom Design's own
 * labels, and the same reason there's no separate flat design-fee
 * product here the way Custom Design has one: Custom Stickers' direct
 * pricing decision was preset size tiers only (docs/ARCHITECTURE.md,
 * "Custom Stickers" section) — no separate proofing/design charge, the
 * sticker price itself is the whole charge. Exactly one of these ever
 * exists, not one per CustomOrder — the product is only an anchor
 * WooCommerce's cart/order APIs need; the real price is always computed
 * live from the cart item's own size/material/type/shape/quantity data
 * (class-cart-pricing.php), never read from the product itself.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Custom_Sticker_Product {

	private const OPTION_KEY = 'yeffoprint_custom_sticker_product_id';

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
		$product->set_name( __( 'Custom Stickers', 'yeffoprint-core' ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' ); // Made-to-order, same as every linked Template product.
		$product->set_sold_individually( false );
		$product->set_virtual( false ); // These get printed and shipped.
		$product->set_regular_price( '0' );
		$product->set_price( '0' );

		$product_id = $product->save();

		if ( $product_id ) {
			update_option( self::OPTION_KEY, $product_id );
		}

		return (int) $product_id;
	}
}
