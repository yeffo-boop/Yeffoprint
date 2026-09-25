<?php
/**
 * Post meta for 3D Prints (yp_print) and the shared Filament Colors
 * list (yp_filament) they pick from.
 *
 * Direct request: a 3D Prints section where the customer picks a color
 * for each part of a print, with an admin way to set how many color
 * choices an item has and where on the print each color goes. A "color
 * choice" is one slot on the item: a part name ("Base"), a short hint,
 * a dot position on the item's main photo (x/y as percentages, so the
 * dot stays put at any image size), the filament colors offered for
 * that part, and a default. Slots live in one COLOR_SLOTS array on the
 * item rather than their own post type — they only ever exist as part
 * of one item and are always read/written together, same reasoning as
 * a Template's field_schema.
 *
 * Filament colors are their own records because one color's stock and
 * extra charge are shared by every item offering it: running out of
 * Silk Gold is one switch, not an edit per item. Active/inactive
 * reuses post_status (publish/draft) and sort order reuses menu_order,
 * same as Materials/Sizes.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Print_Meta {

	/* yp_print */
	public const PRICE       = '_yp_print_price';
	public const SHIPS_IN    = '_yp_print_ships_in';
	public const COLOR_SLOTS = '_yp_print_color_slots';

	/* yp_filament */
	public const HEX          = '_yp_filament_hex';
	public const FINISH       = '_yp_filament_finish';
	public const EXTRA_CHARGE = '_yp_filament_extra_charge';
	public const IN_STOCK     = '_yp_filament_in_stock';

	/** Silk draws with a sheen on the swatch; everything else is a flat dot. */
	public const FINISHES = [
		'matte' => 'Matte',
		'silk'  => 'Silk',
	];

	public function __construct() {
		add_action( 'init', [ $this, 'register_meta' ] );
		add_action( 'pre_get_posts', [ $this, 'order_archive' ] );
	}

	/** /3d-prints/ lists items in the admin's own order, not newest first. */
	public function order_archive( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'yp_print' ) ) {
			return;
		}

		$query->set( 'orderby', [ 'menu_order' => 'ASC', 'title' => 'ASC' ] );
	}

	public function register_meta(): void {
		register_post_meta( 'yp_print', self::PRICE, [
			'type'          => 'number',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_print', self::SHIPS_IN, [
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_print', self::COLOR_SLOTS, [
			'type'              => 'array',
			'single'            => true,
			'default'           => [],
			'sanitize_callback' => [ __CLASS__, 'sanitize_slots' ],
			'auth_callback'     => [ $this, 'can_edit' ],
			'show_in_rest'      => [
				'schema' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'name'       => [ 'type' => 'string' ],
							'hint'       => [ 'type' => 'string' ],
							'x'          => [ 'type' => [ 'number', 'null' ] ],
							'y'          => [ 'type' => [ 'number', 'null' ] ],
							'colors'     => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
							'default_id' => [ 'type' => 'integer' ],
						],
					],
				],
			],
		] );

		register_post_meta( 'yp_filament', self::HEX, [
			'type'              => 'string',
			'single'            => true,
			'default'           => '#888888',
			'sanitize_callback' => [ __CLASS__, 'sanitize_hex' ],
			'show_in_rest'      => true,
			'auth_callback'     => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_filament', self::FINISH, [
			'type'              => 'string',
			'single'            => true,
			'default'           => 'matte',
			'sanitize_callback' => static function ( $value ) {
				return isset( self::FINISHES[ $value ] ) ? $value : 'matte';
			},
			'show_in_rest'      => true,
			'auth_callback'     => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_filament', self::EXTRA_CHARGE, [
			'type'          => 'number',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_filament', self::IN_STOCK, [
			'type'          => 'boolean',
			'single'        => true,
			'default'       => true,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );
	}

	public function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	public static function sanitize_hex( $value ): string {
		$hex = sanitize_hex_color( is_string( $value ) ? $value : '' );
		return $hex ? $hex : '#888888';
	}

	/** Keeps only well-formed slots; dot positions clamp to the photo (0–100%). */
	public static function sanitize_slots( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$slots = [];
		foreach ( $value as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$colors = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $slot['colors'] ?? [] ) ) ) ) );
			$default = absint( $slot['default_id'] ?? 0 );

			$slots[] = [
				'name'       => sanitize_text_field( (string) ( $slot['name'] ?? '' ) ),
				'hint'       => sanitize_text_field( (string) ( $slot['hint'] ?? '' ) ),
				'x'          => isset( $slot['x'] ) && is_numeric( $slot['x'] ) ? max( 0.0, min( 100.0, (float) $slot['x'] ) ) : null,
				'y'          => isset( $slot['y'] ) && is_numeric( $slot['y'] ) ? max( 0.0, min( 100.0, (float) $slot['y'] ) ) : null,
				'colors'     => $colors,
				'default_id' => in_array( $default, $colors, true ) ? $default : ( $colors[0] ?? 0 ),
			];
		}

		return $slots;
	}

	public static function get_slots( int $print_id ): array {
		return self::sanitize_slots( get_post_meta( $print_id, self::COLOR_SLOTS, true ) );
	}

	/** Every active filament color, keyed by ID, in admin sort order. */
	public static function get_filaments(): array {
		$posts = get_posts( [
			'post_type'      => 'yp_filament',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		] );

		$filaments = [];
		foreach ( $posts as $post ) {
			$filaments[ $post->ID ] = self::filament_data( $post );
		}

		return $filaments;
	}

	public static function filament_data( \WP_Post $post ): array {
		$finish = (string) get_post_meta( $post->ID, self::FINISH, true );

		return [
			'id'           => $post->ID,
			// Raw post_title, not get_the_title() — see class-custom-sticker-
			// controller.php's options() for the double-escape this avoids.
			'name'         => $post->post_title,
			'hex'          => self::sanitize_hex( get_post_meta( $post->ID, self::HEX, true ) ),
			'finish'       => isset( self::FINISHES[ $finish ] ) ? $finish : 'matte',
			'extra_charge' => (float) get_post_meta( $post->ID, self::EXTRA_CHARGE, true ),
			'in_stock'     => (bool) get_post_meta( $post->ID, self::IN_STOCK, true ),
		];
	}

	/**
	 * Everything the product page needs in one shape: the item, its main
	 * photo, and each color choice with its offered colors resolved to
	 * full filament data (drafted/deleted colors drop out).
	 */
	public static function get_item_payload( int $print_id ): ?array {
		$post = get_post( $print_id );
		if ( ! $post || 'yp_print' !== $post->post_type ) {
			return null;
		}

		$filaments = self::get_filaments();
		$slots     = [];

		foreach ( self::get_slots( $print_id ) as $slot ) {
			$colors = [];
			foreach ( $slot['colors'] as $filament_id ) {
				if ( isset( $filaments[ $filament_id ] ) ) {
					$colors[] = $filaments[ $filament_id ];
				}
			}

			if ( ! $colors ) {
				continue; // A choice with nothing to pick from would block Add to Cart.
			}

			$slots[] = array_merge( $slot, [ 'colors' => $colors ] );
		}

		return [
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'price'     => (float) get_post_meta( $print_id, self::PRICE, true ),
			'ships_in'  => (string) get_post_meta( $print_id, self::SHIPS_IN, true ),
			'image_url' => (string) get_the_post_thumbnail_url( $print_id, 'large' ),
			'slots'     => $slots,
		];
	}

	/**
	 * Validates a customer's picks against the item's live color choices
	 * and returns [ [ 'slot' => name, 'filament_id' => id, 'name' => color
	 * name, 'extra' => charge ], … ] in slot order, or a WP_Error.
	 *
	 * @param array $picks slot index => filament ID.
	 */
	public static function resolve_picks( int $print_id, array $picks ) {
		$payload = self::get_item_payload( $print_id );
		if ( ! $payload ) {
			return new \WP_Error( 'yeffoprint_print_not_found', __( 'That item was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$resolved = [];
		foreach ( $payload['slots'] as $index => $slot ) {
			$filament_id = absint( $picks[ $index ] ?? 0 );
			$match       = null;
			foreach ( $slot['colors'] as $color ) {
				if ( $color['id'] === $filament_id ) {
					$match = $color;
					break;
				}
			}

			if ( ! $match ) {
				/* translators: %s: part name, e.g. "Base" */
				return new \WP_Error( 'yeffoprint_print_color_missing', sprintf( __( 'Pick a color for %s.', 'yeffoprint-core' ), $slot['name'] ), [ 'status' => 400 ] );
			}

			if ( ! $match['in_stock'] ) {
				/* translators: 1: color name, 2: part name */
				return new \WP_Error( 'yeffoprint_print_color_out', sprintf( __( '%1$s is out of stock. Pick another color for %2$s.', 'yeffoprint-core' ), $match['name'], $slot['name'] ), [ 'status' => 400 ] );
			}

			$resolved[] = [
				'slot'        => $slot['name'],
				'filament_id' => $match['id'],
				'name'        => $match['name'],
				'extra'       => $match['extra_charge'],
			];
		}

		return $resolved;
	}

	/** Base price plus every picked color's extra charge, per item — read live, never cached in the cart. */
	public static function unit_price( int $print_id, array $picks ): float {
		$price = (float) get_post_meta( $print_id, self::PRICE, true );

		foreach ( $picks as $pick ) {
			$filament_id = absint( $pick['filament_id'] ?? 0 );
			if ( $filament_id && 'yp_filament' === get_post_type( $filament_id ) ) {
				$price += (float) get_post_meta( $filament_id, self::EXTRA_CHARGE, true );
			}
		}

		return round( $price, 2 );
	}
}
