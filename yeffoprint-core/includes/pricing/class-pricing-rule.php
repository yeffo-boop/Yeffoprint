<?php
/**
 * The PricingRule record and the authoritative pricing formula.
 *
 * PROJECT_SPEC §12: base price, admin-managed bulk discount tiers,
 * `(base + adjustments − discounts) × quantity`, and "server always
 * recalculates/validates authoritative price; client-side price is
 * never trusted." This class is that server-side authority — the REST
 * controller (class-pricing-controller.php) and the admin editor
 * (class-pricing-rule-editor.php) both go through it rather than
 * touching post meta directly.
 *
 * Material/size surcharges are NOT duplicated here as
 * `size_surcharges[]`/`material_surcharges[]` (even though
 * docs/ARCHITECTURE.md §2 lists them on PricingRule) — Size.
 * price_adjustment and Material.price_adjustment, built in Phase 4,
 * already are that data, admin-managed on the records they describe.
 * PricingRule only owns what doesn't belong anywhere else: the base
 * unit price, bulk discount tiers, and the custom design fee.
 *
 * Exactly one PricingRule post is ever "active" — the most recently
 * modified published one. A default is auto-created on first use so
 * the site always has a working price. This is deliberately simpler
 * than versioned/scheduled pricing; see docs/ARCHITECTURE.md §9.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Pricing_Rule {

	public const META_BASE_UNIT_PRICE   = '_yp_base_unit_price';
	public const META_CUSTOM_DESIGN_FEE = '_yp_custom_design_fee';
	public const META_TIERS             = '_yp_bulk_discount_tiers';
	public const META_VERSION           = '_yp_rule_version';

	public const TIER_TYPES = [
		'percent'          => 'Percent off',
		'fixed_unit_price' => 'Fixed resulting unit price',
	];

	private const DEFAULT_BASE_UNIT_PRICE   = 0.35;
	private const DEFAULT_CUSTOM_DESIGN_FEE = 25.00;

	/**
	 * The active rule's post ID, creating a default rule on first call
	 * if none exists yet.
	 */
	public static function get_active_rule_id(): int {
		$existing = get_posts( [
			'post_type'      => 'yp_pricing_rule',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
		] );

		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		$post_id = wp_insert_post( [
			'post_type'   => 'yp_pricing_rule',
			'post_title'  => __( 'Default Pricing', 'yeffoprint-core' ),
			'post_status' => 'publish',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		update_post_meta( $post_id, self::META_BASE_UNIT_PRICE, self::DEFAULT_BASE_UNIT_PRICE );
		update_post_meta( $post_id, self::META_CUSTOM_DESIGN_FEE, self::DEFAULT_CUSTOM_DESIGN_FEE );
		update_post_meta( $post_id, self::META_TIERS, wp_json_encode( [] ) );
		update_post_meta( $post_id, self::META_VERSION, 1 );

		return (int) $post_id;
	}

	public static function get_base_unit_price(): float {
		$rule_id = self::get_active_rule_id();
		$value   = $rule_id ? get_post_meta( $rule_id, self::META_BASE_UNIT_PRICE, true ) : '';

		return '' === $value ? self::DEFAULT_BASE_UNIT_PRICE : (float) $value;
	}

	public static function get_custom_design_fee(): float {
		$rule_id = self::get_active_rule_id();
		$value   = $rule_id ? get_post_meta( $rule_id, self::META_CUSTOM_DESIGN_FEE, true ) : '';

		return '' === $value ? self::DEFAULT_CUSTOM_DESIGN_FEE : (float) $value;
	}

	/** @return array<int, array{threshold:int, type:string, value:float}> */
	public static function get_tiers(): array {
		$rule_id = self::get_active_rule_id();
		$stored  = $rule_id ? get_post_meta( $rule_id, self::META_TIERS, true ) : '';
		$decoded = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : [];
		$tiers   = is_array( $decoded ) ? $decoded : [];

		usort( $tiers, static function ( $a, $b ) {
			return $a['threshold'] <=> $b['threshold'];
		} );

		return $tiers;
	}

	public static function get_version(): int {
		$rule_id = self::get_active_rule_id();
		return $rule_id ? (int) get_post_meta( $rule_id, self::META_VERSION, true ) : 1;
	}

	/** @param array $raw Decoded JSON from the admin editor — untrusted. */
	public static function sanitize_tiers( array $raw ): array {
		$clean = [];

		foreach ( $raw as $tier ) {
			if ( ! is_array( $tier ) || empty( $tier['threshold'] ) ) {
				continue;
			}

			$type = isset( $tier['type'] ) && array_key_exists( $tier['type'], self::TIER_TYPES )
				? $tier['type']
				: 'percent';

			$clean[] = [
				'threshold' => max( 1, absint( $tier['threshold'] ) ),
				'type'      => $type,
				'value'     => max( 0, (float) ( $tier['value'] ?? 0 ) ),
			];
		}

		return $clean;
	}

	public static function save( int $rule_id, float $base_unit_price, float $custom_design_fee, array $tiers ): void {
		update_post_meta( $rule_id, self::META_BASE_UNIT_PRICE, max( 0, $base_unit_price ) );
		update_post_meta( $rule_id, self::META_CUSTOM_DESIGN_FEE, max( 0, $custom_design_fee ) );
		update_post_meta( $rule_id, self::META_TIERS, wp_json_encode( self::sanitize_tiers( $tiers ) ) );

		$version = (int) get_post_meta( $rule_id, self::META_VERSION, true );
		update_post_meta( $rule_id, self::META_VERSION, max( 1, $version + 1 ) );
	}

	/**
	 * The authoritative formula: (base + adjustments − discount) ×
	 * quantity. Discount tier is resolved against the batch's combined
	 * quantity (Architecture §3.4) — callers pass the total across all
	 * variants, not a single variant's quantity.
	 *
	 * A `fixed_unit_price` tier sets the resulting per-unit price
	 * directly (`value`); the per-unit "discount" is derived as the
	 * difference so the formula still holds structurally for either
	 * tier type. See docs/ARCHITECTURE.md §9.
	 *
	 * @return array{
	 *   base_unit_price:float, material_adjustment:float, size_adjustment:float,
	 *   unit_price_before_discount:float, discount_per_unit:float,
	 *   applied_tier:?array, unit_price_after_discount:float,
	 *   quantity:int, total:float, rule_version:int
	 * }
	 */
	public static function calculate( float $material_adjustment, float $size_adjustment, int $quantity ): array {
		$quantity = max( 1, $quantity );
		$base     = self::get_base_unit_price();

		$unit_price_before_discount = max( 0, $base + $material_adjustment + $size_adjustment );

		$applied_tier      = null;
		$discount_per_unit = 0.0;

		foreach ( self::get_tiers() as $tier ) {
			if ( $quantity >= $tier['threshold'] ) {
				$applied_tier = $tier;
			}
		}

		if ( $applied_tier ) {
			if ( 'percent' === $applied_tier['type'] ) {
				$discount_per_unit = $unit_price_before_discount * ( $applied_tier['value'] / 100 );
			} else {
				$discount_per_unit = max( 0, $unit_price_before_discount - $applied_tier['value'] );
			}
		}

		$unit_price_after_discount = max( 0, $unit_price_before_discount - $discount_per_unit );

		return [
			'base_unit_price'            => $base,
			'material_adjustment'        => $material_adjustment,
			'size_adjustment'            => $size_adjustment,
			'unit_price_before_discount' => round( $unit_price_before_discount, 4 ),
			'discount_per_unit'          => round( $discount_per_unit, 4 ),
			'applied_tier'               => $applied_tier,
			'unit_price_after_discount'  => round( $unit_price_after_discount, 4 ),
			'quantity'                   => $quantity,
			'total'                      => round( $unit_price_after_discount * $quantity, 2 ),
			'rule_version'               => self::get_version(),
		];
	}
}

if ( ! function_exists( 'yeffoprint_core_base_unit_price' ) ) {
	function yeffoprint_core_base_unit_price(): float {
		return YeffoPrint_Pricing_Rule::get_base_unit_price();
	}
}

if ( ! function_exists( 'yeffoprint_core_starting_price_label' ) ) {
	function yeffoprint_core_starting_price_label(): string {
		return sprintf(
			/* translators: %s: formatted starting unit price. */
			__( 'From %s/label', 'yeffoprint-core' ),
			'$' . number_format_i18n( yeffoprint_core_base_unit_price(), 2 )
		);
	}
}

if ( ! function_exists( 'yeffoprint_core_custom_design_fee_label' ) ) {
	function yeffoprint_core_custom_design_fee_label(): string {
		return '$' . number_format_i18n( YeffoPrint_Pricing_Rule::get_custom_design_fee(), 2 );
	}
}
