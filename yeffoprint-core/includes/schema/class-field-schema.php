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
 * Deliberately just two field types (text, textarea) for V1 — nothing
 * in PROJECT_SPEC requires more, and the type list is a single
 * constant to extend later without touching the sanitize logic.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Field_Schema {

	public const META_KEY = '_yp_field_schema';

	public const TYPES = [
		'text'     => 'Text (single line)',
		'textarea' => 'Text (multi-line)',
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

			$clean[] = [
				'id'                => $unique_id,
				'label'             => $label,
				'type'              => $type,
				'default'           => isset( $field['default'] ) ? sanitize_text_field( $field['default'] ) : '',
				'required'          => ! empty( $field['required'] ),
				'max_chars'         => $max_chars,
				'position'          => [ 'x' => $position_x, 'y' => $position_y ],
				'alignment'         => $alignment,
				'font_size_min'     => $font_size_min,
				'font_size_max'     => $font_size_max,
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

	public static function update( int $template_id, array $fields ): void {
		$clean = self::sanitize( $fields );
		update_post_meta( $template_id, self::META_KEY, wp_json_encode( $clean ) );
	}

	public static function default_field(): array {
		return self::DEFAULT_FIELD;
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
