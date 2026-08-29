<?php
/**
 * Shared cart-item / order-item data keys.
 *
 * Batches: one batch = one WooCommerce line item, quantity = the
 * combined quantity across all its variants (PROJECT_SPEC §10, §11) —
 * a batch never splits across multiple line items. Custom design fee
 * items use only CUSTOM_ORDER_ID, linking that one line item back to
 * its yp_custom_order record (PROJECT_SPEC §13). Used by the cart/
 * custom-order REST controllers (write these into $cart_item_data),
 * the pricing/display hooks, and the order-item snapshot on checkout.
 *
 * One deliberate exception to "a batch never splits across multiple
 * line items": Custom Design's own batching (direct request — more
 * than one compound/strength/size under one custom design order) adds
 * one "labels" line item per batch row instead, since each row can have
 * its own size/material and this store's line-item model has no way to
 * represent that within a single item. All of a custom order's rows
 * (plus its fee item, when one exists) share the same CUSTOM_ORDER_ID.
 * See CUSTOM_ORDER_ROW_INDEX below.
 *
 * The Template flow (class-cart-controller.php) still never splits a
 * single batch across line items, but it needs the *same* per-add
 * uniqueness CUSTOM_ORDER_ROW_INDEX exists for below, for a different
 * reason: WC_Cart::generate_cart_id() hashes product + this data to
 * decide whether an add_to_cart() call is "the same line item" as one
 * already in the cart, and two *separate* Add to Cart submissions for
 * the same template/size/material hash identically. Found live —
 * direct report: "When I add several 'batches' of a label, it only
 * sends the first batch to the cart" — a second batch's add_to_cart()
 * call matched an already-in-cart item's hash, so WC only bumped its
 * internal quantity counter and never touched that item's VARIANTS/
 * TOTAL_QTY, silently discarding the new batch's actual data. Fixed by
 * writing a fresh `uniqid()` into $cart_item_data on every Template add
 * (inline in class-cart-controller.php, not worth its own named
 * constant here the way CUSTOM_ORDER_ROW_INDEX's value is actually
 * read back elsewhere).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Cart_Item_Keys {

	public const TEMPLATE_ID     = 'yp_template_id';
	public const SIZE_ID         = 'yp_size_id';
	public const MATERIAL_ID     = 'yp_material_id';
	public const VARIANTS        = 'yp_variants';
	public const TOTAL_QTY       = 'yp_total_quantity';
	public const CUSTOM_ORDER_ID = 'yp_custom_order_id';

	/**
	 * Custom Stickers only. SIZE_ID above is reused as-is (it points at
	 * a yp_sticker_size post here instead of yp_size — same "every
	 * reader already branches on order type anyway" reasoning as
	 * YeffoPrint_Custom_Order_Meta::SIZE_ID); STICKER_TYPE's mere
	 * presence on a cart item is what class-cart-pricing.php uses to
	 * tell a sticker line item apart from a Template batch or a Custom
	 * Design labels item, both of which also carry TOTAL_QTY.
	 */
	public const STICKER_TYPE     = 'yp_sticker_type';
	public const SHAPE            = 'yp_shape';
	public const CUSTOM_WIDTH_IN  = 'yp_custom_width_in';
	public const CUSTOM_HEIGHT_IN = 'yp_custom_height_in';

	/**
	 * Custom Design batching only — this row's 0-based index within its
	 * order's batch. Required on every one of a batch's add_to_cart()
	 * calls, not just informational: WooCommerce's own cart-item-key
	 * hashing (WC_Cart::generate_cart_id()) treats two add_to_cart() calls
	 * with byte-identical $cart_item_data as the same line item and
	 * silently merges them (bumping only its internal quantity, never
	 * touching our own TOTAL_QTY already baked into that item's data) —
	 * a real case here, since two rows can easily share the same size/
	 * material/quantity/compound. Including the row index guarantees
	 * every row's $cart_item_data is unique.
	 */
	public const CUSTOM_ORDER_ROW_INDEX = 'yp_custom_order_row_index';

	/** Custom Design batching only — this row's compound/strength text. Display-only, carried through to the order-item snapshot; never affects pricing. */
	public const COMPOUND_STRENGTH = 'yp_compound_strength';
}
