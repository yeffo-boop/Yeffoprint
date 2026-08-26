<?php
/**
 * Admin REST endpoint for the Pricing Rules screen (docs/ARCHITECTURE.md,
 * Phase 4) — the first genuinely new admin REST surface this dashboard
 * has needed. `yp_pricing_rule` has no REST-registered meta at all
 * (base price, design fee, and every tier/adjustment map are plain
 * `get_post_meta()`/JSON-encoded arrays — see YeffoPrint_Pricing_Rule
 * and YeffoPrint_Sticker_Pricing's own docblocks for why), so there
 * was nothing for WP core's own `/wp/v2/yp_pricing_rule` route to
 * expose even if it were reachable.
 *
 * This controller is a thin wrapper, not a reimplementation: `save()`
 * here calls the exact same `YeffoPrint_Pricing_Rule::save()`/
 * `YeffoPrint_Sticker_Pricing::save()` the classic editor's own
 * `save_post_yp_pricing_rule` hook calls (class-pricing-rule-editor.php)
 * — same validation, same "highest matching tier wins" resolution,
 * same single-active-record model — just reached over REST instead of
 * a form POST.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Pricing_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/pricing-rule', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_rule' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'save_rule' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );
	}

	public function get_rule(): \WP_REST_Response {
		return rest_ensure_response( $this->rule_payload() );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function save_rule( \WP_REST_Request $request ) {
		$rule_id = YeffoPrint_Pricing_Rule::get_active_rule_id();

		if ( ! $rule_id ) {
			return new \WP_Error(
				'yeffoprint_pricing_rule_unavailable',
				__( 'Could not load the active pricing rule.', 'yeffoprint-core' ),
				[ 'status' => 500 ]
			);
		}

		$params = $request->get_json_params() ?: [];

		YeffoPrint_Pricing_Rule::save(
			$rule_id,
			(float) ( $params['base_unit_price'] ?? 0 ),
			(float) ( $params['custom_design_fee'] ?? 0 ),
			is_array( $params['tiers'] ?? null ) ? $params['tiers'] : []
		);

		$sticker = is_array( $params['sticker'] ?? null ) ? $params['sticker'] : [];

		YeffoPrint_Sticker_Pricing::save(
			$rule_id,
			(float) ( $sticker['custom_rate_per_sq_in'] ?? 0 ),
			is_array( $sticker['type_adjustments'] ?? null ) ? $sticker['type_adjustments'] : [],
			is_array( $sticker['shape_adjustments'] ?? null ) ? $sticker['shape_adjustments'] : [],
			is_array( $sticker['tiers'] ?? null ) ? $sticker['tiers'] : []
		);

		return rest_ensure_response( $this->rule_payload() );
	}

	/**
	 * `tier_types`/`sticker.types`/`sticker.shapes` ride along on every
	 * response so the client never hardcodes its own copy of those
	 * label maps — one source of truth (YeffoPrint_Pricing_Rule::TIER_TYPES,
	 * YeffoPrint_Sticker_Pricing::TYPES/SHAPES) either way.
	 */
	private function rule_payload(): array {
		return [
			'base_unit_price'   => YeffoPrint_Pricing_Rule::get_base_unit_price(),
			'custom_design_fee' => YeffoPrint_Pricing_Rule::get_custom_design_fee(),
			'tiers'             => YeffoPrint_Pricing_Rule::get_tiers(),
			'tier_types'        => YeffoPrint_Pricing_Rule::TIER_TYPES,
			'rule_version'      => YeffoPrint_Pricing_Rule::get_version(),
			'sticker'           => [
				'custom_rate_per_sq_in' => YeffoPrint_Sticker_Pricing::get_custom_rate_per_sq_in(),
				'type_adjustments'      => YeffoPrint_Sticker_Pricing::get_type_adjustments(),
				'shape_adjustments'     => YeffoPrint_Sticker_Pricing::get_shape_adjustments(),
				'tiers'                 => YeffoPrint_Sticker_Pricing::get_tiers(),
				'types'                 => YeffoPrint_Sticker_Pricing::TYPES,
				'shapes'                => YeffoPrint_Sticker_Pricing::SHAPES,
			],
		];
	}
}
