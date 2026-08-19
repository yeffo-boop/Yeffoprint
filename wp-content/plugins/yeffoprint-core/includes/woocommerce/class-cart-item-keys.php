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
}
