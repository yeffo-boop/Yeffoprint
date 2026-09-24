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

	/**
	 * Material only — an optional short caution/logistics note shown
	 * under the description on the How It Works page's Material Guide
	 * (direct request: "make that dynamic so I can add/remove materials
	 * from the dashboard" — this is the field for the kind of note that
	 * used to be hand-authored per material, e.g. "holographic orders
	 * ship about 24 hours later than usual"). Optional; a material with
	 * nothing entered here simply shows no note line.
	 */
	public const GUIDE_NOTE = '_yp_guide_note';

	/**
	 * Material only — which drawn texture the label designer's material
	 * swatch shows (direct request: "I don't want to use vial images,
	 * just show the material itself", with an animated shimmer on the
	 * holographic ones). 'auto' (the default, so every existing material
	 * works with no migration) picks one from the material's own name —
	 * see swatch_finish() below.
	 */
	public const SWATCH_FINISH = '_yp_swatch_finish';

	public const SWATCH_FINISHES = [
		'auto'        => 'Automatic (from the name)',
		'glossy'      => 'White glossy',
		'matte'       => 'White matte',
		'clear'       => 'Clear',
		'metallic'    => 'Metallic silver',
		'holographic' => 'Holographic (shimmer)',
		'prism'       => 'Prism (shimmer)',
	];

	/**
	 * Size only — a short "what does this fit" line under each size card
	 * in the label designer (e.g. "Fits 10 mL vials"). Optional; blank
	 * shows nothing.
	 */
	public const FIT_NOTE = '_yp_fit_note';

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

		register_post_meta( 'yp_material', self::GUIDE_NOTE, [
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_material', self::SWATCH_FINISH, [
			'type'          => 'string',
			'single'        => true,
			'default'       => 'auto',
			'show_in_rest'  => [
				'schema' => [
					'type' => 'string',
					'enum' => array_keys( self::SWATCH_FINISHES ),
				],
			],
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_size', self::FIT_NOTE, [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => [ $this, 'can_edit' ],
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

	/**
	 * Whether a Size has its print width and height set. A Size left at
	 * 0 × 0 (the live "Custom" size) is one where the customer enters
	 * their own dimensions instead (configurator.js, cart/add).
	 */
	public static function size_has_dimensions( int $size_id ): bool {
		return (float) get_post_meta( $size_id, self::PRINT_WIDTH_MM, true ) > 0
			&& (float) get_post_meta( $size_id, self::PRINT_HEIGHT_MM, true ) > 0;
	}

	/**
	 * The swatch texture a Material actually renders with: its own
	 * SWATCH_FINISH when an admin picked one, otherwise a guess from its
	 * name so the live catalog (White Glossy, White Matte, Clear,
	 * Holographic, Prism, Metallic) looks right with nothing configured.
	 */
	public static function swatch_finish( \WP_Post $material ): string {
		$stored = (string) get_post_meta( $material->ID, self::SWATCH_FINISH, true );
		if ( '' !== $stored && 'auto' !== $stored && isset( self::SWATCH_FINISHES[ $stored ] ) ) {
			return $stored;
		}

		$name = strtolower( $material->post_title . ' ' . $material->post_name );
		foreach ( [
			'holo'   => 'holographic',
			'prism'  => 'prism',
			'clear'  => 'clear',
			'metal'  => 'metallic',
			'silver' => 'metallic',
			'chrome' => 'metallic',
			'matte'  => 'matte',
		] as $needle => $finish ) {
			if ( false !== strpos( $name, $needle ) ) {
				return $finish;
			}
		}

		return 'glossy';
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
