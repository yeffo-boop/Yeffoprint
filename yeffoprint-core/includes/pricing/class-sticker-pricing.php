<?php
/**
 * The authoritative pricing formula for Custom Stickers.
 *
 * A deliberately separate engine from YeffoPrint_Pricing_Rule, not a
 * reuse of its calculate() — that formula's "base" is a single global
 * $0.35/label constant with material/size as small adjustments on top;
 * a sticker's Size *tier* price (YeffoPrint_Sticker_Size_Meta::PRICE)
 * is itself the base, an order of magnitude different economics.
 * Reusing the label formula literally would silently add $0.35 onto
 * every sticker or misapply the label bulk-discount thresholds to a
 * completely different product line. Same shape/principles as the
 * label formula on purpose (base − discount, then adjustments added on
 * top, × quantity; server-authoritative; admin-configurable tiers) —
 * see docs/ARCHITECTURE.md §9 for the full reasoning.
 *
 * All the admin-configurable knobs here live on the same "one active"
 * yp_pricing_rule post the label pricing already uses (one Pricing
 * Rules screen for the whole site, not a second CPT) rather than
 * duplicating YeffoPrint_Pricing_Rule::get_active_rule_id()'s
 * single-active-record logic.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Sticker_Pricing {

	public const META_CUSTOM_RATE_PER_SQ_IN = '_yp_sticker_custom_rate_per_sq_in';
	public const META_TYPE_ADJUSTMENTS      = '_yp_sticker_type_adjustments';
	public const META_SHAPE_ADJUSTMENTS     = '_yp_sticker_shape_adjustments';
	public const META_TIERS                 = '_yp_sticker_bulk_discount_tiers';

	public const TYPES = [
		'sheet'    => 'Sticker Sheet',
		'die_cut'  => 'Die-Cut',
	];

	public const SHAPES = [
		'square'         => 'Square',
		'rounded_square' => 'Square (rounded corners)',
		'circle'         => 'Circle',
		'custom'         => 'Custom shape (contour-cut to artwork)',
	];

	private const DEFAULT_CUSTOM_RATE_PER_SQ_IN = 0.75;

	/** @return array<string,float> Keyed by TYPES, always fully populated even if never saved. */
	public static function get_type_adjustments(): array {
		return self::get_adjustment_map( self::META_TYPE_ADJUSTMENTS, self::TYPES );
	}

	/** @return array<string,float> Keyed by SHAPES, always fully populated even if never saved. */
	public static function get_shape_adjustments(): array {
		$defaults = array_fill_keys( array_keys( self::SHAPES ), 0.0 );
		// A sensible out-of-the-box default so "Custom" isn't accidentally
		// free the first time this ever prices an order — still just a
		// starting point, fully editable from the Pricing Rules screen.
		$defaults['custom'] = 0.75;

		return self::get_adjustment_map( self::META_SHAPE_ADJUSTMENTS, self::SHAPES, $defaults );
	}

	public static function get_custom_rate_per_sq_in(): float {
		$rule_id = YeffoPrint_Pricing_Rule::get_active_rule_id();
		$value   = $rule_id ? get_post_meta( $rule_id, self::META_CUSTOM_RATE_PER_SQ_IN, true ) : '';

		return '' === $value ? self::DEFAULT_CUSTOM_RATE_PER_SQ_IN : (float) $value;
	}

	/** @return array<int, array{threshold:int, type:string, value:float}> */
	public static function get_tiers(): array {
		$rule_id = YeffoPrint_Pricing_Rule::get_active_rule_id();
		$stored  = $rule_id ? get_post_meta( $rule_id, self::META_TIERS, true ) : '';
		$decoded = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : [];
		$tiers   = is_array( $decoded ) ? $decoded : [];

		usort( $tiers, static function ( $a, $b ) {
			return $a['threshold'] <=> $b['threshold'];
		} );

		return $tiers;
	}

	public static function save( int $rule_id, float $custom_rate_per_sq_in, array $type_adjustments, array $shape_adjustments, array $tiers ): void {
		update_post_meta( $rule_id, self::META_CUSTOM_RATE_PER_SQ_IN, max( 0, $custom_rate_per_sq_in ) );
		update_post_meta( $rule_id, self::META_TYPE_ADJUSTMENTS, wp_json_encode( self::sanitize_adjustment_map( $type_adjustments, self::TYPES ) ) );
		update_post_meta( $rule_id, self::META_SHAPE_ADJUSTMENTS, wp_json_encode( self::sanitize_adjustment_map( $shape_adjustments, self::SHAPES ) ) );
		update_post_meta( $rule_id, self::META_TIERS, wp_json_encode( YeffoPrint_Pricing_Rule::sanitize_tiers( $tiers ) ) );
	}

	/**
	 * @param int    $sticker_size_id 0 when pricing the custom tier (use $custom_width_in/$custom_height_in instead).
	 * @param float  $custom_width_in  Ignored unless $sticker_size_id is the custom tier.
	 * @param float  $custom_height_in Ignored unless $sticker_size_id is the custom tier.
	 * @return array{
	 *   size_base_price:float, material_adjustment:float, type_adjustment:float,
	 *   shape_adjustment:float, unit_price_before_discount:float, discount_per_unit:float,
	 *   applied_tier:?array, unit_price_after_discount:float, quantity:int,
	 *   tier_quantity:int, total:float
	 * }|\WP_Error
	 */
	public static function calculate(
		int $sticker_size_id,
		float $custom_width_in,
		float $custom_height_in,
		int $material_id,
		string $sticker_type,
		string $shape,
		int $quantity,
		?int $tier_quantity = null
	) {
		$size_base = self::resolve_size_base_price( $sticker_size_id, $custom_width_in, $custom_height_in );
		if ( is_wp_error( $size_base ) ) {
			return $size_base;
		}

		$quantity      = max( 1, $quantity );
		$tier_quantity = max( $quantity, $tier_quantity ?? $quantity );

		$material_adjustment = self::material_adjustment( $material_id );
		$type_adjustments    = self::get_type_adjustments();
		$shape_adjustments   = self::get_shape_adjustments();
		$type_adjustment     = $type_adjustments[ $sticker_type ] ?? 0.0;
		$shape_adjustment    = $shape_adjustments[ $shape ] ?? 0.0;

		$unit_price_before_discount = max( 0, $size_base + $material_adjustment + $type_adjustment + $shape_adjustment );

		$applied_tier      = null;
		$discount_per_unit = 0.0;

		foreach ( self::get_tiers() as $tier ) {
			if ( $tier_quantity >= $tier['threshold'] ) {
				$applied_tier = $tier;
			}
		}

		if ( $applied_tier ) {
			// Same rule as the label formula: the discount only ever
			// reduces the size tier's own base price — material/type/
			// shape upcharges are added back on afterward at full price,
			// never themselves discounted.
			if ( 'percent' === $applied_tier['type'] ) {
				$discount_per_unit = $size_base * ( $applied_tier['value'] / 100 );
			} else {
				$discount_per_unit = max( 0, $size_base - $applied_tier['value'] );
			}
		}

		$discounted_base           = max( 0, $size_base - $discount_per_unit );
		$unit_price_after_discount = max( 0, $discounted_base + $material_adjustment + $type_adjustment + $shape_adjustment );

		return [
			'size_base_price'            => $size_base,
			'material_adjustment'        => $material_adjustment,
			'type_adjustment'            => $type_adjustment,
			'shape_adjustment'           => $shape_adjustment,
			'unit_price_before_discount' => round( $unit_price_before_discount, 4 ),
			'discount_per_unit'          => round( $discount_per_unit, 4 ),
			'applied_tier'               => $applied_tier,
			'unit_price_after_discount'  => round( $unit_price_after_discount, 4 ),
			'quantity'                   => $quantity,
			'tier_quantity'              => $tier_quantity,
			'total'                      => round( $unit_price_after_discount * $quantity, 2 ),
		];
	}

	/** @return float|\WP_Error */
	public static function resolve_size_base_price( int $sticker_size_id, float $custom_width_in, float $custom_height_in ) {
		$post = get_post( $sticker_size_id );
		if ( ! $post || 'yp_sticker_size' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new \WP_Error( 'yeffoprint_invalid_sticker_size', __( 'Please choose a valid sticker size.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$is_custom = (bool) get_post_meta( $sticker_size_id, YeffoPrint_Sticker_Size_Meta::IS_CUSTOM, true );

		if ( ! $is_custom ) {
			return (float) get_post_meta( $sticker_size_id, YeffoPrint_Sticker_Size_Meta::PRICE, true );
		}

		if ( $custom_width_in <= 0 || $custom_height_in <= 0 ) {
			return new \WP_Error( 'yeffoprint_invalid_custom_dimensions', __( 'Please enter a width and height for a custom-size sticker.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		return self::get_custom_rate_per_sq_in() * $custom_width_in * $custom_height_in;
	}

	private static function material_adjustment( int $material_id ): float {
		if ( ! $material_id ) {
			return 0.0;
		}

		$post = get_post( $material_id );
		if ( ! $post || 'yp_material' !== $post->post_type ) {
			return 0.0;
		}

		return (float) get_post_meta( $material_id, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, true );
	}

	/** @param array<string,string> $labels */
	private static function get_adjustment_map( string $meta_key, array $labels, ?array $defaults = null ): array {
		$rule_id = YeffoPrint_Pricing_Rule::get_active_rule_id();
		$stored  = $rule_id ? get_post_meta( $rule_id, $meta_key, true ) : '';
		$decoded = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : null;

		$defaults = $defaults ?? array_fill_keys( array_keys( $labels ), 0.0 );

		if ( ! is_array( $decoded ) ) {
			return $defaults;
		}

		$result = [];
		foreach ( array_keys( $labels ) as $key ) {
			$result[ $key ] = isset( $decoded[ $key ] ) ? (float) $decoded[ $key ] : $defaults[ $key ];
		}

		return $result;
	}

	/** @param array<string,string> $labels */
	private static function sanitize_adjustment_map( array $raw, array $labels ): array {
		$result = [];
		foreach ( array_keys( $labels ) as $key ) {
			$result[ $key ] = isset( $raw[ $key ] ) ? (float) $raw[ $key ] : 0.0;
		}

		return $result;
	}
}
