<?php
/**
 * 3D Prints' add-to-cart endpoint (direct request: a 3D Prints section
 * where the customer picks a color for each part of the print).
 * Validates every pick server-side against the item's live color
 * choices and stock (YeffoPrint_Print_Meta::resolve_picks()) before
 * anything reaches the cart, then answers with the same drawer payload
 * the label configurator's add-to-cart returns, so the product page can
 * open the cart drawer the same way.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Print_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/prints/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'item' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/prints/cart', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'add' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'guest_or_nonced_write' ],
		] );
	}

	public function item( \WP_REST_Request $request ) {
		$print_id = (int) $request->get_param( 'id' );
		$payload  = 'publish' === get_post_status( $print_id ) ? YeffoPrint_Print_Meta::get_item_payload( $print_id ) : null;

		if ( ! $payload ) {
			return new \WP_Error( 'yeffoprint_print_not_found', __( 'That item was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( $payload );
	}

	public function add( \WP_REST_Request $request ) {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return new \WP_Error( 'yeffoprint_cart_unavailable', __( 'The cart is unavailable right now.', 'yeffoprint-core' ), [ 'status' => 503 ] );
		}

		$print_id = (int) $request->get_param( 'print_id' );
		$quantity = max( 1, min( 100, (int) $request->get_param( 'quantity' ) ) );

		if ( 'yp_print' !== get_post_type( $print_id ) || 'publish' !== get_post_status( $print_id ) ) {
			return new \WP_Error( 'yeffoprint_print_not_found', __( 'That item was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$size = YeffoPrint_Print_Meta::resolve_size( $print_id, $request->get_param( 'size' ) );
		if ( is_wp_error( $size ) ) {
			return $size;
		}

		$picks = YeffoPrint_Print_Meta::resolve_picks( $print_id, (array) $request->get_param( 'colors' ) );
		if ( is_wp_error( $picks ) ) {
			return $picks;
		}

		$product_id = YeffoPrint_Print_Product::get_linked_product_id( $print_id );
		$product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new \WP_Error( 'yeffoprint_print_unavailable', __( "This item isn't available to order yet.", 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		YeffoPrint_Cart_Pricing::allow_next_add( true );
		$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, 0, [], [
			YeffoPrint_Cart_Item_Keys::PRINT_ID     => $print_id,
			YeffoPrint_Cart_Item_Keys::PRINT_SIZE   => $size,
			YeffoPrint_Cart_Item_Keys::PRINT_COLORS => $picks,
		] );
		YeffoPrint_Cart_Pricing::allow_next_add( false );

		if ( ! $cart_item_key ) {
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			$message = ! empty( $notices ) ? wp_strip_all_tags( $notices[0]['notice'] ) : __( "Couldn't add this to your cart.", 'yeffoprint-core' );
			return new \WP_Error( 'yeffoprint_add_to_cart_failed', $message, [ 'status' => 400 ] );
		}

		WC()->cart->calculate_totals();

		return rest_ensure_response( [
			'success'     => true,
			'cart_count'  => WC()->cart->get_cart_contents_count(),
			'drawer_html' => YeffoPrint_Cart_Controller::drawer_html(),
		] );
	}
}
