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
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Field_Schema {

	public const META_KEY = '_yp_field_schema';

	public const TYPES = [
		'text'     => 'Text (single line)',
		'textarea' => 'Text (multi-line)',
		'color'    => 'Color picker',
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

	private const DEFAULT_FIELD = [
		'id'                => '',
		'label'             => '',
		'type'              => 'text',
		'default'           => '',
		'required'          => false,
		'max_chars'         => 40,
		'position'          => [ 'x' => 50.0, 'y' => 50.0 ],
		'alignment'         => 'center',
		'font_size_min'     => 10,
		'font_size_max'     => 24,
		'text_color'        => '#000000',
		'formatting_rule'   => 'none',
		'preview_behavior'  => 'scale-to-fit',
		'admin_description' => '',
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
			$default = 'color' === $type
				? (string) ( sanitize_hex_color( $default ) ?: '' )
				: sanitize_text_field( $default );

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
				'admin_description' => isset( $field['admin_description'] ) ? sanitize_text_field( $field['admin_description'] ) : '',
			];
		}

		return $clean;
	}

	public static function get( int $template_id ): array {
		$stored = get_post_meta( $template_id, self::META_KEY, true );
		$decoded = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : [];

		return is_array( $decoded ) ? $decoded : [];
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
				'name'   => get_the_title( $post ),
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
				$value     = 'color' === ( $field['type'] ?? '' )
					? (string) ( sanitize_hex_color( $raw_value ) ?: '' )
					: sanitize_text_field( $raw_value );

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
			$parts[] = $field['label'] . ': ' . $value;
		}

		return implode( ' — ', $parts );
	}
}
