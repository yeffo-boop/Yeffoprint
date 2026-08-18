<?php
/**
 * Shared cart-item / order-item data keys for a YeffoPrint batch.
 *
 * One batch = one WooCommerce line item (cart or order), quantity =
 * the combined quantity across all its variants (PROJECT_SPEC §10,
 * §11) — a batch never splits across multiple line items. Used by the
 * cart REST controller (writes these into $cart_item_data), the
 * pricing/display hooks, and the order-item snapshot on checkout.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Cart_Item_Keys {

	public const TEMPLATE_ID = 'yp_template_id';
	public const SIZE_ID     = 'yp_size_id';
	public const MATERIAL_ID = 'yp_material_id';
	public const VARIANTS    = 'yp_variants';
	public const TOTAL_QTY   = 'yp_total_quantity';
}
