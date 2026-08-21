<?php
/**
 * Custom Stickers' own submission endpoint (docs/ARCHITECTURE.md,
 * "Custom Stickers" section) — same shape as class-custom-order-
 * controller.php's Fully Custom Design flow, kept as its own dedicated
 * controller rather than folded in (the two flows validate genuinely
 * different fields: size tiers vs. a fixed size + material, a sticker
 * type + shape neither label flow has, an optional custom-dimensions
 * pair). File uploads reuse that controller's existing
 * `/custom-orders/uploads` endpoint as-is — YeffoPrint_Secure_Upload
 * doesn't care what a file is *for*, so there's nothing sticker-
 * specific to duplicate there.
 *
 * Unlike Custom Design (a flat $25 design fee, separate from the
 * customer's own print run), Custom Stickers has no separate fee item
 * at all — a direct pricing decision (preset size tiers only, no
 * separate proofing charge) — so this adds exactly one cart line item,
 * not two.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Custom_Sticker_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/custom-stickers', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'submit' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'guest_or_nonced_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/custom-stickers/options', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'options' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function options() {
		$sizes = get_posts( [
			'post_type'      => 'yp_sticker_size',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		] );

		$size_data = array_map( static function ( \WP_Post $post ) {
			return [
				'id'        => $post->ID,
				// Raw post_title, not get_the_title() — this feeds
				// custom-sticker-form.js's <option> text via a
				// textContent/innerHTML escape round-trip, a plain-data
				// context. get_the_title() runs the 'the_title' filter
				// (wptexturize), which turns a title like "2x2" into
				// "2&#215;2" — appropriate for direct HTML output, but
				// that HTML-entity substring then gets escaped a second
				// time by the JS and shows up on the page literally as
				// "2&#215;2" instead of "2×2".
				'name'      => $post->post_title,
				'price'     => (float) get_post_meta( $post->ID, YeffoPrint_Sticker_Size_Meta::PRICE, true ),
				'width_in'  => (float) get_post_meta( $post->ID, YeffoPrint_Sticker_Size_Meta::WIDTH_IN, true ),
				'height_in' => (float) get_post_meta( $post->ID, YeffoPrint_Sticker_Size_Meta::HEIGHT_IN, true ),
				'is_custom' => (bool) get_post_meta( $post->ID, YeffoPrint_Sticker_Size_Meta::IS_CUSTOM, true ),
			];
		}, $sizes );

		$material_format = static function ( \WP_Post $post ) {
			return [ 'id' => $post->ID, 'name' => $post->post_title ];
		};

		return rest_ensure_response( [
			'sizes'            => $size_data,
			'materials'        => array_map( $material_format, YeffoPrint_Commerce_Record_Meta::get_materials_for( 'sticker' ) ),
			'sticker_types'    => YeffoPrint_Sticker_Pricing::TYPES,
			'shapes'           => YeffoPrint_Sticker_Pricing::SHAPES,
			'quantity_presets' => function_exists( 'yeffoprint_core_quantity_presets' ) ? yeffoprint_core_quantity_presets() : [],
		] );
	}

	public function submit( \WP_REST_Request $request ) {
		if ( ! function_exists( 'WC' ) ) {
			return new \WP_Error( 'yeffoprint_cart_unavailable', __( 'The cart is not available right now.', 'yeffoprint-core' ), [ 'status' => 503 ] );
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		$size_id = absint( $request->get_param( 'size_id' ) );
		if ( ! $size_id || ! $this->is_published( 'yp_sticker_size', $size_id ) ) {
			return new \WP_Error( 'yeffoprint_invalid_size', __( 'Please choose a valid size.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$material_id = absint( $request->get_param( 'material_id' ) );
		if ( ! $material_id || ! $this->is_published( 'yp_material', $material_id ) ) {
			return new \WP_Error( 'yeffoprint_invalid_material', __( 'Please choose a valid material.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$sticker_type = sanitize_key( (string) $request->get_param( 'sticker_type' ) );
		if ( ! array_key_exists( $sticker_type, YeffoPrint_Sticker_Pricing::TYPES ) ) {
			return new \WP_Error( 'yeffoprint_invalid_sticker_type', __( 'Please choose a valid sticker type.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$shape = sanitize_key( (string) $request->get_param( 'shape' ) );
		if ( ! array_key_exists( $shape, YeffoPrint_Sticker_Pricing::SHAPES ) ) {
			return new \WP_Error( 'yeffoprint_invalid_shape', __( 'Please choose a valid shape.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$quantity = absint( $request->get_param( 'quantity' ) );
		if ( $quantity < 1 ) {
			return new \WP_Error( 'yeffoprint_invalid_quantity', __( 'Quantity must be at least 1.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$custom_width_in  = (float) $request->get_param( 'custom_width_in' );
		$custom_height_in = (float) $request->get_param( 'custom_height_in' );

		// Validated here, up front, rather than left to apply_price() to
		// silently skip on the cart's next recalculation — that hook has
		// no request to report an error back on, so an invalid size/
		// dimensions there would otherwise just leave the item priced at
		// whatever WooCommerce's own product-record default is (effectively
		// $0, since the anchor product's own price is a placeholder — see
		// class-custom-sticker-product.php).
		$pricing_check = YeffoPrint_Sticker_Pricing::calculate( $size_id, $custom_width_in, $custom_height_in, $material_id, $sticker_type, $shape, $quantity );
		if ( is_wp_error( $pricing_check ) ) {
			return $pricing_check;
		}

		$instructions = sanitize_textarea_field( (string) $request->get_param( 'instructions' ) );
		$uploads      = $this->sanitize_upload_ids( $request->get_param( 'uploads' ) );

		if ( ! $uploads ) {
			return new \WP_Error( 'yeffoprint_missing_artwork', __( 'Please upload your print-ready artwork.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$custom_order_id = wp_insert_post( [
			'post_type'   => 'yp_custom_order',
			'post_status' => 'draft', // Publishes once payment completes — see class-custom-order-payment.php.
			'post_title'  => sprintf( /* translators: %s: submission date/time */ __( 'Custom Stickers — %s', 'yeffoprint-core' ), current_time( 'Y-m-d H:i' ) ),
		], true );

		if ( is_wp_error( $custom_order_id ) ) {
			return new \WP_Error( 'yeffoprint_custom_order_failed', __( "Couldn't submit your request. Please try again.", 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::ORDER_TYPE, 'sticker' );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::SIZE_ID, $size_id );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::MATERIAL_ID, $material_id );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::QUANTITY, $quantity );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STICKER_TYPE, $sticker_type );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::SHAPE, $shape );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOM_WIDTH_IN, $custom_width_in );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOM_HEIGHT_IN, $custom_height_in );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::INSTRUCTIONS, $instructions );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::ARTWORK_UPLOADS, $uploads );

		// Same reasoning as the label flow's own access token — generated
		// up front so the one link (grabbed from the admin screen and
		// sent to the customer) keeps working for this request's entire
		// lifetime, guest or not.
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::ACCESS_TOKEN, wp_generate_password( 40, false ) );

		$product_id = YeffoPrint_Custom_Sticker_Product::get_product_id();
		if ( ! $product_id ) {
			wp_delete_post( $custom_order_id, true );
			return new \WP_Error( 'yeffoprint_no_sticker_product', __( 'Custom Stickers orders are not available right now.', 'yeffoprint-core' ), [ 'status' => 503 ] );
		}

		YeffoPrint_Cart_Pricing::allow_next_add( true );
		$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, 0, [], [
			YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID    => $custom_order_id,
			YeffoPrint_Cart_Item_Keys::SIZE_ID            => $size_id,
			YeffoPrint_Cart_Item_Keys::MATERIAL_ID        => $material_id,
			YeffoPrint_Cart_Item_Keys::STICKER_TYPE       => $sticker_type,
			YeffoPrint_Cart_Item_Keys::SHAPE              => $shape,
			YeffoPrint_Cart_Item_Keys::CUSTOM_WIDTH_IN    => $custom_width_in,
			YeffoPrint_Cart_Item_Keys::CUSTOM_HEIGHT_IN   => $custom_height_in,
			YeffoPrint_Cart_Item_Keys::TOTAL_QTY          => $quantity,
		] );
		YeffoPrint_Cart_Pricing::allow_next_add( false );

		if ( ! $cart_item_key ) {
			wp_delete_post( $custom_order_id, true );
			return new \WP_Error( 'yeffoprint_add_to_cart_failed', __( "Couldn't add your stickers to your cart.", 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		return rest_ensure_response( [
			'success'      => true,
			'checkout_url' => wc_get_checkout_url(),
			'cart_count'   => WC()->cart->get_cart_contents_count(),
		] );
	}

	/** @return int[] */
	private function sanitize_upload_ids( $raw ): array {
		$ids = array_map( 'absint', is_array( $raw ) ? $raw : [] );
		$ids = array_filter( $ids, static function ( $id ) {
			return $id && 'attachment' === get_post_type( $id );
		} );

		return array_values( $ids );
	}

	private function is_published( string $post_type, int $post_id ): bool {
		$post = get_post( $post_id );
		return $post && $post_type === $post->post_type && 'publish' === $post->post_status;
	}
}
