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
}
