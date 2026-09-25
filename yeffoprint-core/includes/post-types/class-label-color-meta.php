<?php
/**
 * Color choices on label Templates, and the shared Label Colors list
 * (yp_label_color) they pick from.
 *
 * Direct request: the 3D prints' numbered color dots, but on label
 * templates, so a customer can pick e.g. a background color and a text
 * color. Replaces the old shared "Color" field (a single swatch that
 * never changed the preview) — see retire_color_field().
 *
 * A Template's color choices live in one COLOR_CHOICES array, same
 * shape and reasoning as a 3D print's color slots
 * (YeffoPrint_Print_Meta): a name, a hint, a dot position on the
 * artwork (x/y %), the Label Colors offered, a starting color, whether
 * "Any color" is allowed, and what the choice recolors:
 *
 *  - background: painted behind the artwork, so it shows through the
 *    artwork's see-through areas.
 *  - text: every text field on the label.
 *  - layer: an uploaded shape layer (same canvas as the artwork),
 *    tinted with the picked color over the artwork.
 *
 * On the customer side each choice becomes one extra field of type
 * `color_choice` (virtual_fields()), appended to the shared field set
 * by YeffoPrint_Field_Schema::get_with_colors(). The pick is then just
 * another value in each batch label's `values`, so cart, checkout,
 * order snapshot, reorder and saved designs carry it without their own
 * plumbing, and past orders keep showing it from their frozen schema.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Label_Color_Meta {

	/* yp_template */
	public const COLOR_CHOICES = '_yp_color_choices';

	/* yp_label_color */
	public const HEX = '_yp_label_color_hex';

	public const TARGETS = [
		'background' => 'Background',
		'text'       => 'Text',
		'layer'      => 'Artwork part',
	];

	/** Customer-side field ids start with this, so they can never collide with a Label Fields id. */
	public const FIELD_PREFIX = 'color-choice-';

	public const MAX_CHOICES = 4;

	/** Seeded once so the list isn't empty on day one — the same 8 dots the old Color field offered. */
	private const STARTER_COLORS = [
		'Black'    => '#141414',
		'White'    => '#FFFFFF',
		'Navy'     => '#0D1B4C',
		'Cyan'     => '#00AEEF',
		'Magenta'  => '#EC008C',
		'Green'    => '#1F7A4D',
		'Gold'     => '#B8862B',
		'Burgundy' => '#7A1F2B',
	];

	private const SETUP_OPTION = 'yeffoprint_label_colors_setup';

	private const DEFAULTS_OPTION = 'yeffoprint_label_color_defaults';

	public function __construct() {
		add_action( 'init', [ $this, 'register_meta' ] );
		add_action( 'init', [ $this, 'maybe_setup' ], 20 );
		add_action( 'init', [ $this, 'maybe_add_default_choices' ], 21 );
	}

	/**
	 * Direct request: "automatically add a background color and a text
	 * color to all label templates by default", shown to customers
	 * right away, dots placed later in the admin. Runs once; a Template
	 * that already has color choices is left alone.
	 */
	public function maybe_add_default_choices(): void {
		if ( get_option( self::DEFAULTS_OPTION ) ) {
			return;
		}

		$choices = self::default_choices();
		if ( ! $choices ) {
			return; // No Label Colors yet — try again on a later request.
		}
		update_option( self::DEFAULTS_OPTION, 1, true );

		$template_ids = get_posts( [
			'post_type'      => 'yp_template',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		foreach ( $template_ids as $template_id ) {
			if ( ! self::get_choices( (int) $template_id ) ) {
				self::update_choices( (int) $template_id, $choices );
			}
		}
	}

	/**
	 * Background and Text, every active Label Color
	 * offered, no dots yet. Text starts on the shared fields' own text
	 * color when it's one of the Label Colors, so labels read the same
	 * as before until a customer changes it; otherwise Black.
	 */
	public static function default_choices(): array {
		$colors = self::get_colors();
		if ( ! $colors ) {
			return [];
		}

		$ids = array_keys( $colors );
		$by_hex = [];
		foreach ( $colors as $id => $color ) {
			$by_hex[ strtoupper( $color['hex'] ) ] = $id;
		}

		$text_hex = '#141414';
		$preset_id = YeffoPrint_Field_Schema::get_default_preset_id();
		if ( $preset_id ) {
			foreach ( YeffoPrint_Field_Schema::get( $preset_id ) as $field ) {
				if ( in_array( $field['type'] ?? '', [ 'text', 'textarea' ], true ) && ! empty( $field['text_color'] ) ) {
					$text_hex = strtoupper( (string) $field['text_color'] );
					break;
				}
			}
		}

		$text_default = $by_hex[ $text_hex ] ?? ( $by_hex['#141414'] ?? $ids[0] );

		return [
			[
				'name'       => __( 'Background', 'yeffoprint-core' ),
				'hint'       => __( 'Fills the label behind the text', 'yeffoprint-core' ),
				'target'     => 'background',
				'colors'     => $ids,
				// Whichever of White/Black reads against the starting text.
				'default_id' => self::is_light( $colors[ $text_default ]['hex'] )
					? ( $by_hex['#141414'] ?? $ids[0] )
					: ( $by_hex['#FFFFFF'] ?? $ids[0] ),
			],
			[
				'name'       => __( 'Text', 'yeffoprint-core' ),
				'hint'       => __( 'Compound name, strength and details', 'yeffoprint-core' ),
				'target'     => 'text',
				'colors'     => $ids,
				'default_id' => $text_default,
			],
		];
	}

	public function register_meta(): void {
		register_post_meta( 'yp_label_color', self::HEX, [
			'type'              => 'string',
			'single'            => true,
			'default'           => '#888888',
			'sanitize_callback' => [ __CLASS__, 'sanitize_hex' ],
			'show_in_rest'      => true,
			'auth_callback'     => static function () {
				return current_user_can( 'edit_posts' );
			},
		] );
	}

	/**
	 * One-time switch-over, run on the first request after deploy:
	 * seeds the Label Colors list and retires the old Color field.
	 */
	public function maybe_setup(): void {
		if ( get_option( self::SETUP_OPTION ) ) {
			return;
		}
		update_option( self::SETUP_OPTION, 1, true );

		$has_colors = get_posts( [
			'post_type'      => 'yp_label_color',
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );
		if ( ! $has_colors ) {
			$order = 0;
			foreach ( self::STARTER_COLORS as $name => $hex ) {
				$id = wp_insert_post( [
					'post_type'   => 'yp_label_color',
					'post_status' => 'publish',
					'post_title'  => $name,
					'menu_order'  => $order++,
				] );
				if ( $id && ! is_wp_error( $id ) ) {
					update_post_meta( $id, self::HEX, $hex );
				}
			}
		}

		self::retire_color_field();
	}

	/**
	 * Direct request ("Replace it"): the per-template color choices
	 * replace the old shared `color`-type field. Removes it from the
	 * shared Label Fields set only; past orders keep showing it from
	 * their own frozen field schema.
	 */
	public static function retire_color_field(): void {
		$preset_id = YeffoPrint_Field_Schema::get_default_preset_id();
		if ( ! $preset_id || ! get_post( $preset_id ) ) {
			return;
		}

		$fields = YeffoPrint_Field_Schema::get( $preset_id );
		$kept   = array_values( array_filter( $fields, static function ( $field ) {
			return 'color' !== ( $field['type'] ?? '' );
		} ) );

		if ( count( $kept ) !== count( $fields ) ) {
			YeffoPrint_Field_Schema::update( $preset_id, $kept );
		}
	}

	private static function is_light( string $hex ): bool {
		$n = hexdec( ltrim( $hex, '#' ) );
		return ( 0.299 * ( ( $n >> 16 ) & 255 ) + 0.587 * ( ( $n >> 8 ) & 255 ) + 0.114 * ( $n & 255 ) ) > 150;
	}

	public static function sanitize_hex( $value ): string {
		$hex = sanitize_hex_color( is_string( $value ) ? $value : '' );
		return $hex ? strtoupper( $hex ) : '#888888';
	}

	/** Keeps only well-formed choices; dot positions clamp to the artwork (0–100%). */
	public static function sanitize_choices( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$choices = [];
		foreach ( $value as $choice ) {
			if ( ! is_array( $choice ) || count( $choices ) >= self::MAX_CHOICES ) {
				continue;
			}

			$colors  = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $choice['colors'] ?? [] ) ) ) ) );
			$default = absint( $choice['default_id'] ?? 0 );
			$target  = (string) ( $choice['target'] ?? '' );

			$choices[] = [
				'name'       => sanitize_text_field( (string) ( $choice['name'] ?? '' ) ),
				'hint'       => sanitize_text_field( (string) ( $choice['hint'] ?? '' ) ),
				'target'     => isset( self::TARGETS[ $target ] ) ? $target : 'background',
				'layer_id'   => absint( $choice['layer_id'] ?? 0 ),
				'x'          => isset( $choice['x'] ) && is_numeric( $choice['x'] ) ? max( 0.0, min( 100.0, (float) $choice['x'] ) ) : null,
				'y'          => isset( $choice['y'] ) && is_numeric( $choice['y'] ) ? max( 0.0, min( 100.0, (float) $choice['y'] ) ) : null,
				'colors'     => $colors,
				'default_id' => in_array( $default, $colors, true ) ? $default : ( $colors[0] ?? 0 ),
				'any_color'  => ! empty( $choice['any_color'] ),
			];
		}

		return $choices;
	}

	public static function get_choices( int $template_id ): array {
		return self::sanitize_choices( get_post_meta( $template_id, self::COLOR_CHOICES, true ) );
	}

	public static function update_choices( int $template_id, $choices ): void {
		update_post_meta( $template_id, self::COLOR_CHOICES, self::sanitize_choices( $choices ) );
	}

	/** Every active label color, keyed by ID, in admin sort order. */
	public static function get_colors(): array {
		$posts = get_posts( [
			'post_type'      => 'yp_label_color',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		] );

		$colors = [];
		foreach ( $posts as $post ) {
			$colors[ $post->ID ] = [
				'id'   => $post->ID,
				// Raw post_title — see class-custom-sticker-controller.php's options().
				'name' => $post->post_title,
				'hex'  => self::sanitize_hex( get_post_meta( $post->ID, self::HEX, true ) ),
			];
		}

		return $colors;
	}

	/**
	 * A Template's color choices as customer-facing `color_choice`
	 * fields. A choice with no usable colors is skipped (nothing to
	 * pick), as is an "Artwork part" with no layer uploaded (nothing
	 * to tint).
	 */
	public static function virtual_fields( int $template_id ): array {
		if ( 'yp_template' !== get_post_type( $template_id ) ) {
			return [];
		}

		$choices = self::get_choices( $template_id );
		if ( ! $choices ) {
			return [];
		}

		$all_colors = self::get_colors();
		$fields     = [];
		$used_ids   = [];

		foreach ( $choices as $index => $choice ) {
			$options = [];
			foreach ( $choice['colors'] as $color_id ) {
				if ( isset( $all_colors[ $color_id ] ) ) {
					$options[] = [ 'name' => $all_colors[ $color_id ]['name'], 'hex' => $all_colors[ $color_id ]['hex'] ];
				}
			}

			if ( ! $options && ! $choice['any_color'] ) {
				continue;
			}

			$layer_url = '';
			if ( 'layer' === $choice['target'] ) {
				$layer_url = $choice['layer_id'] ? (string) wp_get_attachment_image_url( $choice['layer_id'], 'large' ) : '';
				if ( '' === $layer_url ) {
					continue;
				}
			}

			$default_hex = isset( $all_colors[ $choice['default_id'] ] ) && in_array( $choice['default_id'], $choice['colors'], true )
				? $all_colors[ $choice['default_id'] ]['hex']
				: ( $options[0]['hex'] ?? '#141414' );

			$name = '' !== $choice['name'] ? $choice['name'] : self::TARGETS[ $choice['target'] ];
			$id   = self::FIELD_PREFIX . ( sanitize_title( $name ) ?: ( $index + 1 ) );
			while ( isset( $used_ids[ $id ] ) ) {
				$id .= '-' . ( $index + 1 );
			}
			$used_ids[ $id ] = true;

			$fields[] = array_merge( YeffoPrint_Field_Schema::default_field(), [
				'id'              => $id,
				'label'           => $name,
				'type'            => 'color_choice',
				'default'         => $default_hex,
				'required'        => true,
				'max_chars'       => 7,
				// Drawn by configurator.js's own color layer, not as a
				// regular stage field.
				'show_in_preview' => false,
				'position'        => [ 'x' => $choice['x'], 'y' => $choice['y'] ],
				'hint'            => $choice['hint'],
				'target'          => $choice['target'],
				'layer_url'       => $layer_url,
				'any_color'       => $choice['any_color'],
				'options'         => $options,
			] );
		}

		return $fields;
	}

	/**
	 * A submitted pick checked against its choice: one of the offered
	 * colors (in that color's own spelling), any valid hex when "Any
	 * color" is on, otherwise the starting color. Never an error, so a
	 * color the admin has since removed never blocks a reorder.
	 */
	public static function sanitize_pick( array $field, string $raw ): string {
		$hex = sanitize_hex_color( trim( $raw ) );

		if ( $hex ) {
			foreach ( (array) ( $field['options'] ?? [] ) as $option ) {
				if ( 0 === strcasecmp( (string) $option['hex'], $hex ) ) {
					return (string) $option['hex'];
				}
			}
			if ( ! empty( $field['any_color'] ) ) {
				return strtoupper( $hex );
			}
		}

		return (string) ( $field['default'] ?? '' );
	}

	/** "Gold", or "Custom #A1B2C3" for an Any color pick. */
	public static function display_pick( array $field, string $value ): string {
		foreach ( (array) ( $field['options'] ?? [] ) as $option ) {
			if ( 0 === strcasecmp( (string) $option['hex'], $value ) ) {
				return (string) $option['name'];
			}
		}

		/* translators: %s: hex color code */
		return sprintf( __( 'Custom %s', 'yeffoprint-core' ), strtoupper( $value ) );
	}
}
