<?php
/**
 * The generic field-schema engine.
 *
 * PROJECT_SPEC §10: templates define a "generic field schema... not
 * hard-coded to compound/strength" — internal ID, label, field type,
 * default, required flag, max chars, position, alignment, font sizing
 * (with minimum), formatting rules, preview behavior, admin
 * description. This class is the single place that knows the shape of
 * a field definition and how to sanitize one; the admin editor
 * (includes/admin/class-template-editor.php) and, from Phase 5
 * onward, the configurator's schema-fetch endpoint both go through it
 * rather than re-implementing validation.
 *
 * V1 shipped with just two field types (text, textarea) — the type
 * list is a single constant specifically so more could be added later
 * without touching the sanitize logic. `color` (V2: a customer-facing
 * color picker, e.g. "pick your cap color") is the first of those —
 * its value is a hex string rather than free text, sanitized/validated
 * as one everywhere a field value is read or written (below, and
 * class-cart-controller.php's shared sanitize_variants()), and
 * rendered on the configurator stage as a swatch instead of text
 * (configurator.js) since a hex string as literal text would look
 * wrong there.
 *
 * `qr_code` (direct request: let a customer put a URL on their label
 * as a scannable QR code) is the second — its value is a URL,
 * sanitized with esc_url_raw() everywhere a value is read or written,
 * same pattern as `color`'s sanitize_hex_color(). Unlike every other
 * type it doesn't render as text or a swatch at all: configurator.js
 * points an <img> at YeffoPrint_Qr_Renderer's REST endpoint
 * (class-qr-controller.php), sized/positioned via the new `qr_size`
 * field property below rather than font_size_min/max (which this type
 * ignores — still present with their defaults for schema-shape
 * uniformity with every other field, same reasoning as `color` leaving
 * them unused rather than branching the stored shape per type).
 *
 * `corner_style` (direct request: "a radio selector set to specify
 * which type of corners they want on their labels, squared or
 * rounded") is the third — a fixed two-choice radio, not free text, so
 * its value is constrained to CORNER_STYLE_OPTIONS' own keys
 * everywhere a value is read or written, same "closed set" pattern as
 * `color`/`qr_code`'s own format-specific sanitizing. Unlike those two
 * it has nothing sensible to draw on the configurator's print-stage
 * preview at all (it's a fulfillment choice about the label's physical
 * die-cut, not printed artwork) — configurator.js excludes it from
 * stage rendering outright rather than leaving that to the per-Template
 * show_in_preview toggle. Rendered customer-side as two option pills
 * (matching the Size/Material picker's own convention) rather than a
 * native radio input, with a built-in "?" tooltip showing a small
 * squared-vs-rounded diagram — no admin_description needed to unlock
 * it, since the graphic is intrinsic to the type rather than authored
 * per-Template content.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Field_Schema {

	public const META_KEY = '_yp_field_schema';

	public const TYPES = [
		'text'         => 'Text (single line)',
		'textarea'     => 'Text (multi-line)',
		'color'        => 'Color picker',
		'qr_code'      => 'QR code (URL)',
		'corner_style' => 'Corner style (Squared / Rounded)',
	];

	/** corner_style's own fixed, non-admin-editable choice set — see the class docblock's own paragraph on this type for why it's a closed set rather than free text. */
	public const CORNER_STYLE_OPTIONS = [
		'squared' => 'Squared',
		'rounded' => 'Rounded',
	];

	public const ALIGNMENTS = [
		'left'   => 'Left',
		'center' => 'Center',
		'right'  => 'Right',
	];

	public const FORMATTING_RULES = [
		'none'       => 'None',
		'uppercase'  => 'UPPERCASE',
		'lowercase'  => 'lowercase',
		'capitalize' => 'Capitalize Each Word',
	];

	public const PREVIEW_BEHAVIORS = [
		'scale-to-fit' => 'Scale to fit, then warn if still too long',
		'fixed'        => 'Fixed size (no auto-scaling)',
	];

	/**
	 * DEFAULT_FIELD's generic 40-char max_chars is sized for a short
	 * label-printed value (a compound name, a strength) — a qr_code
	 * field's value is a URL instead, which routinely runs well past
	 * that. QR_MIN_MAX_CHARS is the floor sanitize() raises a qr_code
	 * field's max_chars to whenever it's at or below the generic
	 * default (covers both a freshly-added field, still holding
	 * field-schema.js's createDefaultField() default, and a Template
	 * saved before this fix existed) — never a value an admin could have
	 * deliberately chosen for a URL field. QR_MAX_CHARS is the ceiling,
	 * matching YeffoPrint_Qr_Controller::MAX_TEXT_LENGTH exactly, so
	 * this class can never accept a value the renderer's own limit
	 * would then turn around and 400 on.
	 */
	public const QR_MIN_MAX_CHARS = 300;
	public const QR_MAX_CHARS     = 500;

	private const DEFAULT_FIELD = [
		'id'                => '',
		'label'             => '',
		'type'              => 'text',
		'default'           => '',
		'required'          => false,
		/** Whether this field's value is drawn on the configurator's visual stage at all — direct request: some fields (e.g. a color picker standing in for a cap color, not anything printed on the label artwork) have nowhere sensible to render, so the field stays fully usable (input, pricing, order data) but simply isn't shown on the preview. */
		'show_in_preview'   => true,
		'max_chars'         => 40,
		'position'          => [ 'x' => 50.0, 'y' => 50.0 ],
		'alignment'         => 'center',
		'font_size_min'     => 10,
		'font_size_max'     => 24,
		'text_color'        => '#000000',
		'formatting_rule'   => 'none',
		'preview_behavior'  => 'scale-to-fit',
		'admin_description' => '',
		/** qr_code only — the rendered QR square's width, as a percentage of the stage width. `position` is its center, same convention as every other field type. */
		'qr_size'           => 20.0,
	];

	/**
	 * @param array $raw Decoded JSON from the admin editor (or any
	 *                   other future caller) — untrusted input.
	 * @return array<int, array> Clean, fully-shaped field definitions.
	 */
	public static function sanitize( array $raw ): array {
		$clean     = [];
		$used_ids  = [];

		foreach ( $raw as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$label = isset( $field['label'] ) ? trim( sanitize_text_field( $field['label'] ) ) : '';
			if ( '' === $label ) {
				continue; // A field with no customer-facing label can't be shown or edited.
			}

			$id = isset( $field['id'] ) ? sanitize_title( $field['id'] ) : '';
			if ( '' === $id ) {
				$id = sanitize_title( $label );
			}
			if ( '' === $id ) {
				$id = 'field-' . ( $index + 1 );
			}

			$suffix   = 2;
			$unique_id = $id;
			while ( isset( $used_ids[ $unique_id ] ) ) {
				$unique_id = $id . '-' . $suffix;
				$suffix++;
			}
			$used_ids[ $unique_id ] = true;

			$type = isset( $field['type'] ) && array_key_exists( $field['type'], self::TYPES )
				? $field['type']
				: self::DEFAULT_FIELD['type'];

			$alignment = isset( $field['alignment'] ) && array_key_exists( $field['alignment'], self::ALIGNMENTS )
				? $field['alignment']
				: self::DEFAULT_FIELD['alignment'];

			$formatting_rule = isset( $field['formatting_rule'] ) && array_key_exists( $field['formatting_rule'], self::FORMATTING_RULES )
				? $field['formatting_rule']
				: self::DEFAULT_FIELD['formatting_rule'];

			$preview_behavior = isset( $field['preview_behavior'] ) && array_key_exists( $field['preview_behavior'], self::PREVIEW_BEHAVIORS )
				? $field['preview_behavior']
				: self::DEFAULT_FIELD['preview_behavior'];

			$max_chars = isset( $field['max_chars'] ) ? absint( $field['max_chars'] ) : self::DEFAULT_FIELD['max_chars'];
			$max_chars = max( 1, $max_chars );
			$max_chars = self::normalize_qr_max_chars( $type, $max_chars );

			$font_size_min = isset( $field['font_size_min'] ) ? (float) $field['font_size_min'] : self::DEFAULT_FIELD['font_size_min'];
			$font_size_max = isset( $field['font_size_max'] ) ? (float) $field['font_size_max'] : self::DEFAULT_FIELD['font_size_max'];
			$font_size_min = max( 1, $font_size_min );
			$font_size_max = max( $font_size_min, $font_size_max );

			$position_x = isset( $field['position']['x'] ) ? (float) $field['position']['x'] : self::DEFAULT_FIELD['position']['x'];
			$position_y = isset( $field['position']['y'] ) ? (float) $field['position']['y'] : self::DEFAULT_FIELD['position']['y'];
			$position_x = min( 100, max( 0, $position_x ) );
			$position_y = min( 100, max( 0, $position_y ) );

			$text_color = isset( $field['text_color'] ) ? sanitize_hex_color( $field['text_color'] ) : null;
			if ( ! $text_color ) {
				$text_color = self::DEFAULT_FIELD['text_color'];
			}

			$default = isset( $field['default'] ) ? (string) $field['default'] : '';
			if ( 'color' === $type ) {
				$default = (string) ( sanitize_hex_color( $default ) ?: '' );
			} elseif ( 'qr_code' === $type ) {
				$default = '' !== $default ? esc_url_raw( $default ) : '';
			} elseif ( 'corner_style' === $type ) {
				$default = array_key_exists( $default, self::CORNER_STYLE_OPTIONS ) ? $default : 'squared';
			} else {
				$default = sanitize_text_field( $default );
			}

			$qr_size = isset( $field['qr_size'] ) ? (float) $field['qr_size'] : self::DEFAULT_FIELD['qr_size'];
			$qr_size = min( 60, max( 5, $qr_size ) ); // Sane bounds: unreadably small below 5%, dominates the whole label above 60%.

			// Defaults to true (not the "!empty() -> false" shorthand used
			// for `required` below) so a field saved before this option
			// existed — the key simply absent, not explicitly false —
			// keeps showing on the preview exactly as it always did.
			// assets/admin/field-schema.js always sends an explicit
			// true/false for this key once a field's gone through the
			// repeater UI at all, same as it already does for `required`.
			$show_in_preview = isset( $field['show_in_preview'] ) ? (bool) $field['show_in_preview'] : self::DEFAULT_FIELD['show_in_preview'];

			$clean[] = [
				'id'                => $unique_id,
				'label'             => $label,
				'type'              => $type,
				'default'           => $default,
				'required'          => ! empty( $field['required'] ),
				'max_chars'         => $max_chars,
				'position'          => [ 'x' => $position_x, 'y' => $position_y ],
				'alignment'         => $alignment,
				'font_size_min'     => $font_size_min,
				'font_size_max'     => $font_size_max,
				'text_color'        => $text_color,
				'formatting_rule'   => $formatting_rule,
				'preview_behavior'  => $preview_behavior,
				'show_in_preview'   => $show_in_preview,
				'admin_description' => isset( $field['admin_description'] ) ? sanitize_text_field( $field['admin_description'] ) : '',
				'qr_size'           => $qr_size,
			];
		}

		return $clean;
	}

	/**
	 * A qr_code field's max_chars is never allowed below QR_MIN_MAX_CHARS
	 * or above QR_MAX_CHARS — see those constants' own doc comment.
	 * Applied both here (sanitize(), the write path) and in get() below
	 * (the read path), so a Template saved before this floor/ceiling
	 * existed gets fixed the moment it's next read, not just the next
	 * time someone happens to re-save it.
	 */
	private static function normalize_qr_max_chars( string $type, int $max_chars ): int {
		if ( 'qr_code' !== $type ) {
			return $max_chars;
		}

		return min( self::QR_MAX_CHARS, max( $max_chars, self::QR_MIN_MAX_CHARS ) );
	}

	public static function get( int $template_id ): array {
		$stored = get_post_meta( $template_id, self::META_KEY, true );
		$decoded = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : [];

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		foreach ( $decoded as &$field ) {
			if ( is_array( $field ) && isset( $field['type'], $field['max_chars'] ) ) {
				$field['max_chars'] = self::normalize_qr_max_chars( (string) $field['type'], (int) $field['max_chars'] );
			}
		}
		unset( $field );

		return $decoded;
	}

	/**
	 * Every published yp_field_preset, each with its own saved
	 * field_schema (same meta key/shape as a Template's) — for the
	 * Template editor's "Insert from preset" control (assets/admin/
	 * field-schema.js) to copy fields from without a separate REST
	 * round-trip; localized wholesale since there's no reason to expect
	 * more than a handful of presets on a real site.
	 *
	 * @return array<int, array{id:int, name:string, fields:array}>
	 */
	public static function get_presets(): array {
		$posts = get_posts( [
			'post_type'      => 'yp_field_preset',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		return array_map( static function ( \WP_Post $post ) {
			return [
				'id'     => $post->ID,
				// Raw post_title, not get_the_title() — field-schema.js
				// renders this through its own escapeHtml()
				// (textContent/innerHTML) when building the preset
				// <option>, so a texturized title (e.g. one containing
				// an apostrophe, which 'the_title' converts to a curly-
				// quote HTML entity) would have its "&" escaped a
				// second time and show up on the page as literal entity
				// text — same bug as class-custom-sticker-controller.php's
				// options().
				'name'   => $post->post_title,
				'fields' => self::get( $post->ID ),
			];
		}, $posts );
	}

	public static function update( int $template_id, array $fields ): void {
		$clean = self::sanitize( $fields );
		update_post_meta( $template_id, self::META_KEY, wp_json_encode( $clean ) );
	}

	public static function default_field(): array {
		return self::DEFAULT_FIELD;
	}

	/**
	 * Validates and cleans a whole batch's variants array against a
	 * Template's field_schema — shared by every entry point that
	 * accepts customer-submitted batch data (cart add, saved designs),
	 * so the same rules (quantity, max_chars, which fields exist) can
	 * never drift between them.
	 *
	 * $enforce_required is the one behavioral difference between
	 * callers: cart/checkout must reject a missing required field
	 * outright (it's about to be printed), but a *saved* design is
	 * explicitly allowed to be an unfinished draft the customer intends
	 * to come back and complete later — rejecting an incomplete save
	 * would defeat the point of saving it.
	 *
	 * @param mixed $raw
	 * @return array|\WP_Error
	 */
	public static function sanitize_variants( $raw, array $field_schema, bool $enforce_required = true ) {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return new \WP_Error( 'yeffoprint_no_variants', __( 'Add at least one label to this batch.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$clean = [];

		foreach ( $raw as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}

			$quantity = isset( $variant['quantity'] ) ? absint( $variant['quantity'] ) : 0;
			if ( $quantity < 1 ) {
				return new \WP_Error( 'yeffoprint_invalid_quantity', __( 'Each label needs a quantity of at least 1.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}

			$submitted_values = is_array( $variant['values'] ?? null ) ? $variant['values'] : [];
			$values = [];

			foreach ( $field_schema as $field ) {
				$raw_value = isset( $submitted_values[ $field['id'] ] ) ? (string) $submitted_values[ $field['id'] ] : '';
				$type      = $field['type'] ?? '';

				if ( 'color' === $type ) {
					$value = (string) ( sanitize_hex_color( $raw_value ) ?: '' );
				} elseif ( 'qr_code' === $type ) {
					$value = '' !== trim( $raw_value ) ? esc_url_raw( $raw_value ) : '';
					if ( '' !== $value && ! wp_http_validate_url( $value ) ) {
						return new \WP_Error(
							'yeffoprint_invalid_qr_url',
							/* translators: %s: field label */
							sprintf( __( '"%s" needs a valid web address (starting with https://).', 'yeffoprint-core' ), $field['label'] ),
							[ 'status' => 400 ]
						);
					}
				} elseif ( 'corner_style' === $type ) {
					// A closed set, not free text — an invalid/blank submission
					// is simply "nothing chosen" rather than a value to reject
					// outright, same as any other unfilled field; the required-
					// field check right below still catches that if this field
					// is marked required.
					$value = array_key_exists( $raw_value, self::CORNER_STYLE_OPTIONS ) ? $raw_value : '';
				} else {
					$value = sanitize_text_field( $raw_value );
				}

				if ( $enforce_required && $field['required'] && '' === $value ) {
					return new \WP_Error(
						'yeffoprint_missing_field',
						/* translators: %s: field label */
						sprintf( __( '"%s" is required.', 'yeffoprint-core' ), $field['label'] ),
						[ 'status' => 400 ]
					);
				}

				if ( mb_strlen( $value ) > $field['max_chars'] ) {
					return new \WP_Error(
						'yeffoprint_field_too_long',
						/* translators: 1: field label, 2: max character count */
						sprintf( __( '"%1$s" must be %2$d characters or fewer.', 'yeffoprint-core' ), $field['label'], $field['max_chars'] ),
						[ 'status' => 400 ]
					);
				}

				$values[ $field['id'] ] = $value;
			}

			$clean[] = [ 'quantity' => $quantity, 'values' => $values ];
		}

		return $clean;
	}

	/**
	 * Renders one batch variant's customization as a single
	 * human-readable line (e.g. "Compound: NAD+ — Strength: 500mg") for
	 * cart/checkout item data and order line item meta — using each
	 * field's own label, not its internal id, and skipping anything the
	 * customer left blank.
	 *
	 * @param array $variant      One entry from a batch's variants
	 *                            array: ['quantity' => int, 'values' => [field_id => string]].
	 * @param array $field_schema The owning Template's field_schema (self::get()).
	 */
	public static function format_variant_summary( array $variant, array $field_schema ): string {
		$values = (array) ( $variant['values'] ?? [] );
		$parts  = [];

		foreach ( $field_schema as $field ) {
			$value = trim( (string) ( $values[ $field['id'] ] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			$parts[] = $field['label'] . ': ' . self::display_value( $field, $value );
		}

		return implode( ' — ', $parts );
	}

	/**
	 * A corner_style value is stored as its canonical key ("squared"/
	 * "rounded"), not its display label — this is the one place both
	 * format_variant_summary() above and class-order-item-meta.php's own
	 * variant_field_pairs() (admin order screen + emails) go through so
	 * "Squared"/"Rounded" only has to be spelled out once. Every other
	 * type's stored value already is its own display value.
	 */
	public static function display_value( array $field, string $value ): string {
		if ( 'corner_style' === ( $field['type'] ?? '' ) && isset( self::CORNER_STYLE_OPTIONS[ $value ] ) ) {
			return self::CORNER_STYLE_OPTIONS[ $value ];
		}

		return $value;
	}
}
