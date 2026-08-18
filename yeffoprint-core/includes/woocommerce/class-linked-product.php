<?php
/**
 * Keeps one hidden WooCommerce simple product in sync per Template.
 *
 * Architecture §7: customization is layered on top of WooCommerce via
 * yeffoprint-core, never via WooCommerce Product Variations (that's
 * the template × material × size explosion PROJECT_SPEC §20 rules
 * out). The resolution: exactly ONE simple product per Template, not
 * per template/size/material combination. WooCommerce's cart/order
 * APIs need a product ID to attach a line item to; this product is
 * that anchor. Its own price is irrelevant — the real price is
 * computed live from PricingRule + the customer's size/material/
 * quantity (see class-cart-pricing.php) and is never read from the
 * product itself.
 *
 * The product is catalog-hidden and unpurchasable through WooCommerce's
 * own shop/search — customers only ever reach it through the
 * configurator's Add to Cart, never by browsing WooCommerce directly.
 * Made-to-order per PROJECT_SPEC §15: stock management is off and
 * stock status is always "in stock," so nothing shows "only X left."
 *
 * Never hard-deleted: if a Template is unpublished, its linked product
 * is drafted (not deleted or trashed), since past orders still
 * reference it by ID.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Linked_Product {

	public const META_LINKED_PRODUCT = '_yp_linked_product_id';
	public const META_TEMPLATE_ID    = '_yp_template_id';

	public function __construct() {
		add_action( 'save_post_yp_template', [ $this, 'sync' ], 30 );
	}

	public function sync( int $template_id ): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$template = get_post( $template_id );
		if ( ! $template || wp_is_post_revision( $template_id ) || wp_is_post_autosave( $template_id ) ) {
			return;
		}

		$product_id = (int) get_post_meta( $template_id, self::META_LINKED_PRODUCT, true );
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product ) {
			$product = new \WC_Product_Simple();
		}

		$product->set_name( $template->post_title );
		$product->set_status( 'publish' === $template->post_status ? 'publish' : 'draft' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->set_sold_individually( false );
		$product->set_virtual( false );
		$product->set_regular_price( '0' );
		$product->set_price( '0' );

		$thumbnail_id = get_post_thumbnail_id( $template_id );
		if ( $thumbnail_id ) {
			$product->set_image_id( $thumbnail_id );
		}

		$product->update_meta_data( self::META_TEMPLATE_ID, $template_id );
		$new_product_id = $product->save();

		if ( $new_product_id && $new_product_id !== $product_id ) {
			update_post_meta( $template_id, self::META_LINKED_PRODUCT, $new_product_id );
		}
	}

	public static function get_linked_product_id( int $template_id ): int {
		return (int) get_post_meta( $template_id, self::META_LINKED_PRODUCT, true );
	}

	public static function get_template_id( int $product_id ): int {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$product = wc_get_product( $product_id );
		return $product ? (int) $product->get_meta( self::META_TEMPLATE_ID ) : 0;
	}
}
