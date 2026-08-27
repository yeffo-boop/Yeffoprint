<?php
/**
 * Admin REST endpoints for manually creating a WooCommerce order from
 * the admin app (docs/ARCHITECTURE.md — "Manual order creation") —
 * thin wrapper, same shape as every other admin/* controller: the real
 * work is YeffoPrint_Manual_Order_Creator::create(), this just parses
 * the request and formats the response.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Manual_Order_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/manual-orders/customer-search', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'search_customers' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/manual-orders', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'create_order' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		// Custom Stickers has no public pricing-preview endpoint the way
		// Custom Design does (class-custom-order-controller.php's
		// /custom-orders/pricing-preview) — the storefront prices a
		// sticker live via the cart itself instead. This admin screen has
		// no cart to price against, so it gets its own small preview
		// wrapper around the same authoritative YeffoPrint_Sticker_Pricing::calculate().
		register_rest_route( self::NAMESPACE, '/admin/manual-orders/sticker-pricing-preview', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'sticker_pricing_preview' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		// Same reasoning as sticker-pricing-preview above: the public
		// /custom-orders/pricing-preview endpoint reads the live session
		// cart's own combined_label_quantity() for its bulk-discount tier
		// (class-custom-order-controller.php::pricing_preview()), which
		// would silently pull in whatever's sitting in the *staff member's
		// own* cart here — this order's own quantity is the whole tier
		// pool instead, same as YeffoPrint_Manual_Order_Creator::add_template_row()
		// itself prices it.
		register_rest_route( self::NAMESPACE, '/admin/manual-orders/template-pricing-preview', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'template_pricing_preview' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/** @return \WP_REST_Response */
	public function search_customers( \WP_REST_Request $request ) {
		$term = trim( (string) $request->get_param( 'q' ) );
		if ( '' === $term ) {
			return rest_ensure_response( [] );
		}

		$users = get_users( [
			'search'         => '*' . esc_attr( $term ) . '*',
			'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
			'number'         => 20,
			'fields'         => [ 'ID', 'display_name', 'user_email' ],
		] );

		return rest_ensure_response( array_map( static function ( $user ) {
			return [
				'id'           => (int) $user->ID,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
			];
		}, $users ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function create_order( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: [];

		$result = YeffoPrint_Manual_Order_Creator::create( $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$order            = $result['order'];
		$custom_order_id  = $result['custom_order_id'];

		return rest_ensure_response( [
			'success'                   => true,
			'order_id'                  => $order->get_id(),
			// $order->get_edit_order_url() rather than a hand-built
			// post.php?post=…&action=edit link — resolves correctly
			// whether this store is on classic post-based orders or HPOS.
			'order_edit_url'            => $order->get_edit_order_url(),
			'custom_order_id'           => $custom_order_id,
			'custom_order_approval_url' => $custom_order_id ? yeffoprint_core_proof_approval_url( $custom_order_id ) : '',
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function sticker_pricing_preview( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: [];

		$pricing = YeffoPrint_Sticker_Pricing::calculate(
			absint( $params['size_id'] ?? 0 ),
			(float) ( $params['custom_width_in'] ?? 0 ),
			(float) ( $params['custom_height_in'] ?? 0 ),
			absint( $params['material_id'] ?? 0 ),
			sanitize_key( (string) ( $params['sticker_type'] ?? '' ) ),
			sanitize_key( (string) ( $params['shape'] ?? '' ) ),
			max( 1, absint( $params['quantity'] ?? 1 ) )
		);

		if ( is_wp_error( $pricing ) ) {
			return $pricing;
		}

		return rest_ensure_response( $pricing );
	}

	/** @return \WP_REST_Response */
	public function template_pricing_preview( \WP_REST_Request $request ) {
		$params      = $request->get_json_params() ?: [];
		$size_id     = absint( $params['size_id'] ?? 0 );
		$material_id = absint( $params['material_id'] ?? 0 );
		$quantity    = max( 1, absint( $params['quantity'] ?? 1 ) );

		$pricing = YeffoPrint_Pricing_Rule::calculate(
			$this->record_adjustment( 'yp_material', $material_id ),
			$this->record_adjustment( 'yp_size', $size_id ),
			$quantity,
			$quantity
		);

		return rest_ensure_response( $pricing );
	}

	/** Same lookup class-cart-pricing.php's own adjustment() and class-custom-order-controller.php's own record_adjustment() already use — a size/material's PRICE_ADJUSTMENT meta, verified against the given post type first. */
	private function record_adjustment( string $post_type, int $post_id ): float {
		if ( ! $post_id ) {
			return 0.0;
		}

		$post = get_post( $post_id );
		if ( ! $post || $post_type !== $post->post_type ) {
			return 0.0;
		}

		return (float) get_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, true );
	}
}
