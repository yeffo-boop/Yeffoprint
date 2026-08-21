<?php
/**
 * Reorder's read side: "restore batch into configurator, then edit
 * before purchase" (PROJECT_SPEC §16) — deliberately not a one-click
 * re-cart (that's the "one-click reorder UI" PROJECT_SPEC §19 rules
 * out for V1). This endpoint hands the configurator the same
 * {template_id, size_id, material_id, variants} shape as
 * GET /cart/item/{key} (class-cart-controller.php), just sourced from
 * a placed order's frozen line-item snapshot instead of a live cart
 * item — the configurator doesn't need to know or care which.
 *
 * Order data isn't public like cart-session data is, so this needs a
 * real ownership check: only the order's own customer, or staff.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Item_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/orders/(?P<order_id>\d+)/items/(?P<item_id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_item' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );
	}

	public function check_permission( \WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order = wc_get_order( absint( $request->get_param( 'order_id' ) ) );
		if ( ! $order ) {
			return false;
		}

		return current_user_can( 'manage_woocommerce' ) || (int) $order->get_customer_id() === get_current_user_id();
	}

	public function get_item( \WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request->get_param( 'order_id' ) ) );
		$item  = $order ? $order->get_item( absint( $request->get_param( 'item_id' ) ) ) : null;

		if ( ! $item ) {
			return new \WP_Error( 'yeffoprint_order_item_not_found', __( 'That order item was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$template_snapshot = json_decode( (string) $item->get_meta( '_yp_template_snapshot' ), true );
		$size_snapshot      = json_decode( (string) $item->get_meta( '_yp_size_snapshot' ), true );
		$material_snapshot  = json_decode( (string) $item->get_meta( '_yp_material_snapshot' ), true );
		$variants           = json_decode( (string) $item->get_meta( '_yp_variants' ), true );

		if ( empty( $template_snapshot['id'] ) ) {
			return new \WP_Error( 'yeffoprint_not_reorderable', __( "This item can't be reordered.", 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		return rest_ensure_response( [
			'template_id' => $template_snapshot['id'],
			'size_id'     => $size_snapshot['id'] ?? null,
			'material_id' => $material_snapshot['id'] ?? null,
			'variants'    => is_array( $variants ) ? $variants : [],
		] );
	}
}
