<?php
/**
 * The authoritative, server-validated price calculation endpoint.
 *
 * PROJECT_SPEC §12: "Server always recalculates/validates authoritative
 * price; client-side price is never trusted." Architecture §3: the
 * configurator computes a provisional price locally for instant
 * feedback, then calls this on every price-affecting change (and
 * always before Add to Cart/checkout) to get the real number.
 *
 * Deliberately template-agnostic — size_id/material_id/quantity are
 * enough to price a batch, since size and material adjustments live
 * on their own records (Phase 4) and are shared by every variant in a
 * batch (PROJECT_SPEC §10: a batch shares one template/size/material).
 * That also means Phase 8's Custom Order flow can reuse this endpoint
 * unchanged rather than needing a parallel calculation.
 *
 * Bulk discounts factor in the customer's whole cart, not just the
 * quantity being previewed here (direct request: "mix and match to
 * meet that minimum") — this endpoint adds whatever's already in
 * WC()->cart (via YeffoPrint_Cart_Pricing::combined_label_quantity(),
 * the same helper apply_price() uses for the authoritative charge) on
 * top of the previewed quantity, so the estimate matches what actually
 * gets charged once it's added. `exclude_cart_item_key` exists for the
 * one case that would otherwise double-count: editing a batch that's
 * already in the cart (configurator.js's `?edit=` flow) previews a new
 * quantity for an item whose *old* quantity is still sitting in the
 * cart until the edit is actually saved.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Pricing_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';
	private const MAX_QUANTITY = 1000000;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/pricing/calculate', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'calculate' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'quantity'              => [ 'required' => true ],
				'size_id'               => [ 'required' => false ],
				'material_id'           => [ 'required' => false ],
				'exclude_cart_item_key' => [ 'required' => false ],
			],
		] );
	}

	public function calculate( \WP_REST_Request $request ) {
		$quantity = $request->get_param( 'quantity' );

		if ( ! is_numeric( $quantity ) || (int) $quantity < 1 || (int) $quantity > self::MAX_QUANTITY ) {
			return new \WP_Error(
				'yeffoprint_invalid_quantity',
				__( 'Quantity must be a whole number of at least 1.', 'yeffoprint-core' ),
				[ 'status' => 400 ]
			);
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart(); // So combined_label_quantity() below sees this session's actual cart, not an empty/uninitialized one.
		}

		$material_adjustment = 0.0;
		$material_id         = $request->get_param( 'material_id' );
		if ( ! empty( $material_id ) ) {
			$material_adjustment = $this->record_adjustment( 'yp_material', (int) $material_id );
			if ( null === $material_adjustment ) {
				return new \WP_Error( 'yeffoprint_invalid_material', __( 'That material is not available.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}
		}

		$size_adjustment = 0.0;
		$size_id         = $request->get_param( 'size_id' );
		if ( ! empty( $size_id ) ) {
			$size_adjustment = $this->record_adjustment( 'yp_size', (int) $size_id );
			if ( null === $size_adjustment ) {
				return new \WP_Error( 'yeffoprint_invalid_size', __( 'That size is not available.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}
		}

		$exclude_cart_item_key = (string) $request->get_param( 'exclude_cart_item_key' );
		$tier_quantity         = (int) $quantity + YeffoPrint_Cart_Pricing::combined_label_quantity( null, $exclude_cart_item_key ?: null );

		return rest_ensure_response(
			YeffoPrint_Pricing_Rule::calculate( (float) $material_adjustment, (float) $size_adjustment, (int) $quantity, $tier_quantity )
		);
	}

	/** @return float|null Adjustment, or null if the record doesn't exist/isn't published. */
	private function record_adjustment( string $post_type, int $post_id ): ?float {
		$post = get_post( $post_id );

		if ( ! $post || $post_type !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		return (float) get_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, true );
	}
}
