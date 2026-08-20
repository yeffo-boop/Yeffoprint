<?php
/**
 * Post meta for the Sticker Size record (Custom Stickers).
 *
 * Deliberately not folded into YeffoPrint_Commerce_Record_Meta even
 * though it looks similar to yp_size at a glance: yp_size's
 * PRICE_ADJUSTMENT is a small delta added on top of a shared
 * $0.35/label base (YeffoPrint_Pricing_Rule), whereas a sticker size
 * tier's price *is* the base for that sticker — a different enough
 * meaning that reusing the same meta key/constant would blur two
 * distinct pricing models together. See docs/ARCHITECTURE.md §9.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Sticker_Size_Meta {

	public const WIDTH_IN  = '_yp_width_in';
	public const HEIGHT_IN = '_yp_height_in';
	public const PRICE     = '_yp_price';
	/**
	 * Marks the one tier where the customer types exact dimensions
	 * instead of picking a fixed size — WIDTH_IN/HEIGHT_IN are unused on
	 * this record (0), and PRICE is likewise unused; the real price
	 * comes from YeffoPrint_Sticker_Pricing's $/sq in rate at order time.
	 * A record, not a hardcoded sentinel ID, so an admin can rename/
	 * reorder it like any other tier and there's still exactly one
	 * source of truth for "which tier is the custom one."
	 */
	public const IS_CUSTOM  = '_yp_is_custom_size';

	public function __construct() {
		add_action( 'init', [ $this, 'register_meta' ] );
	}

	public function register_meta(): void {
		register_post_meta( 'yp_sticker_size', self::WIDTH_IN, [
			'type'          => 'number',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_sticker_size', self::HEIGHT_IN, [
			'type'          => 'number',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_sticker_size', self::PRICE, [
			'type'          => 'number',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_sticker_size', self::IS_CUSTOM, [
			'type'          => 'boolean',
			'single'        => true,
			'default'       => false,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );
	}

	public function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/** The single is_custom=true record, if one exists (there should be at most one — the admin editor enforces this on save). */
	public static function get_custom_tier_id(): int {
		$found = get_posts( [
			'post_type'      => 'yp_sticker_size',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, admin-managed table; no pagination-scale concern.
				[
					'key'   => self::IS_CUSTOM,
					'value' => '1',
				],
			],
		] );

		return $found ? (int) $found[0] : 0;
	}
}
