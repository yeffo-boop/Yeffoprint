<?php
/**
 * Post meta for Material and Size records.
 *
 * Both are otherwise plain admin-managed records that lean on native
 * WordPress fields rather than reinventing them: active/inactive is
 * post_status (publish/draft), sort_order is menu_order (via the
 * 'page-attributes' support, which also gives a native drag-orderable
 * admin UI for free), and Material's description is post_content. The
 * only genuinely new data is price_adjustment (both) and Size's print
 * dimensions — see docs/ARCHITECTURE.md §9.
 *
 * "compatible_products" from the Material data model (ARCHITECTURE §2)
 * isn't duplicated here: Template.compatible_materials (see
 * class-template-editor.php) is the single source of truth for that
 * relationship, read from the Template side.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Commerce_Record_Meta {

	public const PRICE_ADJUSTMENT = '_yp_price_adjustment';
	public const PRINT_WIDTH_MM   = '_yp_print_width_mm';
	public const PRINT_HEIGHT_MM  = '_yp_print_height_mm';
	/**
	 * Material only — a second photo showing the finish actually applied
	 * to a vial, swapped in on hover wherever the material's featured-
	 * image swatch is shown (direct request: "upload what the material
	 * actually looks like... on hover, switch to a second picture that
	 * shows what the material looks like on the vial"). Same hover-swap
	 * pairing pattern as a Template's featured image + Vial mockup
	 * image, just scoped to Material instead.
	 */
	public const HOVER_IMAGE = '_yp_hover_image_id';

	/**
	 * Material only — thickness in mil, shown alongside the material's
	 * finish on the How It Works page's material guide (site owner
	 * request: "add that field to the material form... you can pull
	 * that data and display it"). 0 doubles as "not set" — no real
	 * material is 0mil thick, so the guide falls back to its own
	 * hardcoded spec text for any material without a value here yet.
	 */
	public const THICKNESS_MIL = '_yp_thickness_mil';

	/**
	 * Material only — which product line(s) this material is offered
	 * for (Custom Stickers reuses the Material CPT rather than a
	 * parallel record type, per docs/ARCHITECTURE.md §8's "generic
	 * infrastructure" intent: vinyl/holographic genuinely apply to
	 * both labels and stickers, so duplicating them as separate
	 * records would drift out of sync). Defaults to 'label' so every
	 * existing Material predates this field without needing a
	 * migration or suddenly appearing in the sticker form unreviewed.
	 */
	public const SCOPE = '_yp_material_scope';

	public const SCOPES = [
		'label'   => 'Labels',
		'sticker' => 'Stickers',
		'both'    => 'Labels & Stickers',
	];

	/**
	 * Material only — direct request: mark a material out of stock
	 * without removing it from the site entirely (still shows in the
	 * configurator/forms so a returning customer can see it exists and
	 * check back, it just can't be selected — see get()/format callers
	 * in class-template-schema-controller.php, class-custom-order-
	 * controller.php, and class-custom-sticker-controller.php, and the
	 * matching server-side reject in each submission endpoint).
	 * Deliberately separate from post_status (publish/draft, "Active"
	 * in the admin app) — a material can be temporarily unavailable
	 * without being unpublished, and unpublishing would hide it
	 * entirely rather than showing the "back soon" state that was
	 * actually asked for. Defaults to true so every existing material
	 * predates this field without needing a migration.
	 */
	public const IN_STOCK = '_yp_in_stock';

	public function __construct() {
		add_action( 'init', [ $this, 'register_meta' ] );
	}

	public function register_meta(): void {
		foreach ( [ 'yp_material', 'yp_size' ] as $post_type ) {
			register_post_meta( $post_type, self::PRICE_ADJUSTMENT, [
				'type'          => 'number',
				'single'        => true,
				'default'       => 0,
				'show_in_rest'  => true,
				'auth_callback' => [ $this, 'can_edit' ],
			] );
		}

		register_post_meta( 'yp_material', self::HOVER_IMAGE, [
			'type'          => 'integer',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_material', self::THICKNESS_MIL, [
			'type'          => 'number',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_material', self::SCOPE, [
			'type'          => 'string',
			'single'        => true,
			'default'       => 'label',
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_material', self::IN_STOCK, [
			'type'          => 'boolean',
			'single'        => true,
			'default'       => true,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_size', self::PRINT_WIDTH_MM, [
			'type'          => 'number',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_size', self::PRINT_HEIGHT_MM, [
			'type'          => 'number',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );
	}

	public function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/** Published Materials whose scope includes $for ('label' or 'sticker'). */
	public static function get_materials_for( string $for ): array {
		$clauses = [
			'relation' => 'OR',
			[ 'key' => self::SCOPE, 'value' => $for ],
			[ 'key' => self::SCOPE, 'value' => 'both' ],
		];

		// A Material saved before this field existed has no SCOPE meta
		// row at all yet, not an empty one — for the 'label' flow only,
		// NOT EXISTS treats that the same as the field's own 'label'
		// default, so it keeps appearing where it already worked
		// without a migration. Never true for 'sticker' — a pre-
		// existing Material shouldn't silently start appearing in a
		// flow it was never reviewed for.
		if ( 'label' === $for ) {
			$clauses[] = [ 'key' => self::SCOPE, 'compare' => 'NOT EXISTS' ];
		}

		return get_posts( [
			'post_type'      => 'yp_material',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'meta_query'     => [ $clauses ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, admin-managed table.
		] );
	}
}
