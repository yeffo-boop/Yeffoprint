<?php
/**
 * Data model for Saved Designs (PROJECT_SPEC §16/§19, Architecture §8):
 * a customer can persist a Batch (template + size + material +
 * variants — the exact same shape a cart item or order line item
 * already uses) to their account without purchasing, then come back
 * later, restore it into the configurator, and finish/edit it before
 * ordering. Ownership is native WordPress post_author, not a custom
 * "_yp_customer_id" meta field, since this record is only ever created
 * by the logged-in customer who owns it — unlike a Custom Order, which
 * is created before payment identifies who it belongs to.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Saved_Design_Meta {

	public const TEMPLATE_ID = '_yp_template_id';
	public const SIZE_ID     = '_yp_size_id';
	public const MATERIAL_ID = '_yp_material_id';
	public const VARIANTS    = '_yp_variants';

	/** @return int[] Saved Design post IDs owned by this customer, newest first. */
	public static function get_for_customer( int $user_id ): array {
		return get_posts( [
			'post_type'      => 'yp_saved_design',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'author'         => $user_id,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		] );
	}
}
