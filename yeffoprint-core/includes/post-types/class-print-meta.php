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
	public const SIZES       = '_yp_print_sizes';
	public const SIZE_PRICES = '_yp_print_size_prices';
	public const ADDONS      = '_yp_print_addons';

	/** Longest lid text a customer can type, when the item doesn't set its own. */
	public const DEFAULT_TEXT_MAX = 30;

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

		// The sizes a customer picks one of ("12oz Can", "8.4oz Slim Can").
		// An item with none skips the picker. Each size's own price lives
		// in SIZE_PRICES below; a size without one costs the base price.
		register_post_meta( 'yp_print', self::SIZES, [
			'type'              => 'array',
			'single'            => true,
			'default'           => [],
			'sanitize_callback' => [ __CLASS__, 'sanitize_sizes' ],
			'auth_callback'     => [ $this, 'can_edit' ],
			'show_in_rest'      => [
				'schema' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
		] );

		// Size name => that size's price (e.g. "10 vials" => 32). Keyed by
		// name rather than position so reordering the sizes can't hand one
		// size another's price.
		register_post_meta( 'yp_print', self::SIZE_PRICES, [
			'type'              => 'object',
			'single'            => true,
			'default'           => [],
			'sanitize_callback' => [ __CLASS__, 'sanitize_size_prices' ],
			'auth_callback'     => [ $this, 'can_edit' ],
			'show_in_rest'      => [
				'schema' => [
					'type'                 => 'object',
					'additionalProperties' => [ 'type' => 'number' ],
				],
			],
		] );

		// Paid personalization (direct request: "Customers can also add
		// text to the lid of the box for a small up charge, and an image
		// for a slightly higher up charge"). `area` names the spot on the
		// print ("lid") so the same switches work on any item.
		register_post_meta( 'yp_print', self::ADDONS, [
			'type'              => 'object',
			'single'            => true,
			'default'           => self::sanitize_addons( [] ),
			'sanitize_callback' => [ __CLASS__, 'sanitize_addons' ],
			'auth_callback'     => [ $this, 'can_edit' ],
			'show_in_rest'      => [
				'schema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'area'        => [ 'type' => 'string' ],
						'text'        => [ 'type' => 'boolean' ],
						'text_price'  => [ 'type' => 'number' ],
						'text_max'    => [ 'type' => 'integer' ],
						'image'       => [ 'type' => 'boolean' ],
						'image_price' => [ 'type' => 'number' ],
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

	/** Trimmed, non-empty, de-duplicated size names in admin order. */
	public static function sanitize_sizes( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$sizes = array_filter( array_map( static function ( $size ) {
			return is_scalar( $size ) ? sanitize_text_field( (string) $size ) : '';
		}, $value ), 'strlen' );

		return array_values( array_unique( $sizes ) );
	}

	public static function get_sizes( int $print_id ): array {
		return self::sanitize_sizes( get_post_meta( $print_id, self::SIZES, true ) );
	}

	/** Size name => price, positive prices only. */
	public static function sanitize_size_prices( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$prices = [];
		foreach ( $value as $size => $price ) {
			$size = sanitize_text_field( (string) $size );
			if ( '' !== $size && is_numeric( $price ) && (float) $price > 0 ) {
				$prices[ $size ] = round( (float) $price, 2 );
			}
		}

		return $prices;
	}

	public static function sanitize_addons( $value ): array {
		$value = is_array( $value ) ? $value : [];
		$area  = sanitize_text_field( (string) ( $value['area'] ?? '' ) );
		$max   = absint( $value['text_max'] ?? 0 );

		return [
			'area'        => '' !== $area ? $area : 'lid',
			'text'        => ! empty( $value['text'] ),
			'text_price'  => max( 0.0, round( (float) ( $value['text_price'] ?? 0 ), 2 ) ),
			'text_max'    => $max ? min( 200, $max ) : self::DEFAULT_TEXT_MAX,
			'image'       => ! empty( $value['image'] ),
			'image_price' => max( 0.0, round( (float) ( $value['image_price'] ?? 0 ), 2 ) ),
		];
	}

	public static function get_addons( int $print_id ): array {
		return self::sanitize_addons( get_post_meta( $print_id, self::ADDONS, true ) );
	}

	/** One entry per size, in admin order, each with the price it sells at. */
	public static function get_size_options( int $print_id ): array {
		$base    = (float) get_post_meta( $print_id, self::PRICE, true );
		$prices  = self::sanitize_size_prices( get_post_meta( $print_id, self::SIZE_PRICES, true ) );
		$options = [];

		foreach ( self::get_sizes( $print_id ) as $size ) {
			$options[] = [
				'name'  => $size,
				'price' => $prices[ $size ] ?? $base,
			];
		}

		return $options;
	}

	/** What the item sells at before colors and add-ons: the size's own price, else the base price. */
	public static function price_for_size( int $print_id, string $size ): float {
		foreach ( self::get_size_options( $print_id ) as $option ) {
			if ( $option['name'] === $size ) {
				return (float) $option['price'];
			}
		}

		return (float) get_post_meta( $print_id, self::PRICE, true );
	}

	/** The lowest price a customer can pay: the cheapest size, or the base price for an item without sizes. */
	public static function from_price( int $print_id ): float {
		$options = self::get_size_options( $print_id );
		if ( ! $options ) {
			return (float) get_post_meta( $print_id, self::PRICE, true );
		}

		$prices = array_filter( array_column( $options, 'price' ), static function ( $price ) {
			return $price > 0;
		} );

		return $prices ? (float) min( $prices ) : 0.0;
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

		$size_options = self::get_size_options( $print_id );

		return [
			'id'           => $post->ID,
			'title'        => $post->post_title,
			'price'        => (float) get_post_meta( $print_id, self::PRICE, true ),
			'from_price'   => self::from_price( $print_id ),
			'ships_in'     => (string) get_post_meta( $print_id, self::SHIPS_IN, true ),
			'image_url'    => (string) get_the_post_thumbnail_url( $print_id, 'large' ),
			'sizes'        => array_column( $size_options, 'name' ),
			'size_options' => $size_options,
			'addons'       => self::get_addons( $print_id ),
			// Lid text / image print in any active filament color.
			'addon_colors' => array_values( $filaments ),
			'slots'        => $slots,
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

	/**
	 * The customer's size pick checked against the item's live sizes:
	 * the size name, '' when the item has no sizes, or a WP_Error.
	 */
	public static function resolve_size( int $print_id, $size ) {
		$sizes = self::get_sizes( $print_id );
		if ( ! $sizes ) {
			return '';
		}

		$size = is_scalar( $size ) ? sanitize_text_field( (string) $size ) : '';
		if ( ! in_array( $size, $sizes, true ) ) {
			return new \WP_Error( 'yeffoprint_print_size_missing', __( 'Pick a size.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		// A size with no price of its own on an item with no base price
		// would go in the cart for free.
		if ( self::price_for_size( $print_id, $size ) <= 0 ) {
			return new \WP_Error( 'yeffoprint_print_size_unpriced', __( "That size isn't available to order yet.", 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		return $size;
	}

	/**
	 * The customer's lid text / lid image checked against the item's
	 * live add-on switches, each with the filament color it prints in
	 * (the image is printed as a single-color design): [ 'text' =>
	 * string, 'text_color' => pick|null, 'image_id' => int,
	 * 'image_color' => pick|null ] ('' / 0 / null when not added), or a
	 * WP_Error. A pick is [ 'filament_id' => id, 'name' => color name ].
	 */
	public static function resolve_addons( int $print_id, $text, $image_id, $text_color = 0, $image_color = 0 ) {
		$addons   = self::get_addons( $print_id );
		$text     = is_scalar( $text ) ? trim( sanitize_text_field( (string) $text ) ) : '';
		$image_id = absint( $image_id );
		$area     = $addons['area'];

		if ( '' !== $text ) {
			if ( ! $addons['text'] ) {
				return new \WP_Error( 'yeffoprint_print_addon_off', __( "This item doesn't offer custom text.", 'yeffoprint-core' ), [ 'status' => 400 ] );
			}
			if ( mb_strlen( $text ) > $addons['text_max'] ) {
				/* translators: %d: max characters */
				return new \WP_Error( 'yeffoprint_print_text_long', sprintf( __( 'Keep your text to %d characters or fewer.', 'yeffoprint-core' ), $addons['text_max'] ), [ 'status' => 400 ] );
			}
		}

		if ( $image_id ) {
			if ( ! $addons['image'] ) {
				return new \WP_Error( 'yeffoprint_print_addon_off', __( "This item doesn't offer a custom image.", 'yeffoprint-core' ), [ 'status' => 400 ] );
			}
			if ( 'attachment' !== get_post_type( $image_id ) ) {
				return new \WP_Error( 'yeffoprint_print_image_missing', __( 'Your image didn’t upload. Please try again.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}
		}

		$text_pick = null;
		if ( '' !== $text ) {
			/* translators: %s: where on the print, e.g. "lid" */
			$text_pick = self::resolve_addon_color( $text_color, sprintf( __( 'your %s text', 'yeffoprint-core' ), $area ) );
			if ( is_wp_error( $text_pick ) ) {
				return $text_pick;
			}
		}

		$image_pick = null;
		if ( $image_id ) {
			/* translators: %s: where on the print, e.g. "lid" */
			$image_pick = self::resolve_addon_color( $image_color, sprintf( __( 'your %s image', 'yeffoprint-core' ), $area ) );
			if ( is_wp_error( $image_pick ) ) {
				return $image_pick;
			}
		}

		return [
			'text'        => $text,
			'text_color'  => $text_pick,
			'image_id'    => $image_id,
			'image_color' => $image_pick,
		];
	}

	/** An add-on's color: any active, in-stock filament color. */
	private static function resolve_addon_color( $filament_id, string $what ) {
		$filaments   = self::get_filaments();
		$filament_id = absint( $filament_id );

		if ( ! isset( $filaments[ $filament_id ] ) ) {
			/* translators: %s: e.g. "your lid text" */
			return new \WP_Error( 'yeffoprint_print_color_missing', sprintf( __( 'Pick a color for %s.', 'yeffoprint-core' ), $what ), [ 'status' => 400 ] );
		}

		if ( ! $filaments[ $filament_id ]['in_stock'] ) {
			/* translators: 1: color name, 2: e.g. "your lid text" */
			return new \WP_Error( 'yeffoprint_print_color_out', sprintf( __( '%1$s is out of stock. Pick another color for %2$s.', 'yeffoprint-core' ), $filaments[ $filament_id ]['name'], $what ), [ 'status' => 400 ] );
		}

		return [
			'filament_id' => $filament_id,
			'name'        => $filaments[ $filament_id ]['name'],
		];
	}

	/**
	 * "Lid text" / "Lid image" rows for a cart line's add-ons, for the
	 * cart, drawer and order screens: [ [ 'label', 'value', 'url' ], … ].
	 * The image row's value is the file name and its url the upload.
	 */
	public static function addon_rows( int $print_id, array $cart_item ): array {
		$area     = ucfirst( self::get_addons( $print_id )['area'] );
		$text     = (string) ( $cart_item[ YeffoPrint_Cart_Item_Keys::PRINT_TEXT ] ?? '' );
		$image_id = (int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::PRINT_IMAGE ] ?? 0 );
		$rows     = [];

		$text_color  = (array) ( $cart_item[ YeffoPrint_Cart_Item_Keys::PRINT_TEXT_COLOR ] ?? [] );
		$image_color = (array) ( $cart_item[ YeffoPrint_Cart_Item_Keys::PRINT_IMAGE_COLOR ] ?? [] );

		if ( '' !== $text ) {
			/* translators: %s: where on the print, e.g. "Lid" */
			$rows[] = [ 'label' => sprintf( __( '%s text', 'yeffoprint-core' ), $area ), 'value' => $text, 'url' => '' ];
			if ( ! empty( $text_color['name'] ) ) {
				/* translators: %s: where on the print, e.g. "Lid" */
				$rows[] = [ 'label' => sprintf( __( '%s text color', 'yeffoprint-core' ), $area ), 'value' => (string) $text_color['name'], 'url' => '' ];
			}
		}

		if ( $image_id ) {
			$url    = (string) wp_get_attachment_url( $image_id );
			/* translators: %s: where on the print, e.g. "Lid" */
			$rows[] = [ 'label' => sprintf( __( '%s image', 'yeffoprint-core' ), $area ), 'value' => $url ? wp_basename( $url ) : __( 'Uploaded image', 'yeffoprint-core' ), 'url' => $url ];
			if ( ! empty( $image_color['name'] ) ) {
				/* translators: %s: where on the print, e.g. "Lid" */
				$rows[] = [ 'label' => sprintf( __( '%s image color', 'yeffoprint-core' ), $area ), 'value' => (string) $image_color['name'], 'url' => '' ];
			}
		}

		return $rows;
	}

	/** Add-on charges for what the customer added, at today's prices. */
	public static function addons_price( int $print_id, string $text, int $image_id ): float {
		$addons = self::get_addons( $print_id );
		$price  = 0.0;

		if ( '' !== $text && $addons['text'] ) {
			$price += $addons['text_price'];
		}
		if ( $image_id && $addons['image'] ) {
			$price += $addons['image_price'];
		}

		return $price;
	}

	/**
	 * The size's price (or base price) plus every picked color's extra
	 * charge plus any lid text / image charge, per item — read live,
	 * never cached in the cart.
	 */
	public static function unit_price( int $print_id, array $picks, string $size = '', string $text = '', int $image_id = 0 ): float {
		$price = self::price_for_size( $print_id, $size ) + self::addons_price( $print_id, $text, $image_id );

		foreach ( $picks as $pick ) {
			$filament_id = absint( $pick['filament_id'] ?? 0 );
			if ( $filament_id && 'yp_filament' === get_post_type( $filament_id ) ) {
				$price += (float) get_post_meta( $filament_id, self::EXTRA_CHARGE, true );
			}
		}

		return round( $price, 2 );
	}
}
