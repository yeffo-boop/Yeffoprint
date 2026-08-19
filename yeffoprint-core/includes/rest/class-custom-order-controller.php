<?php
/**
 * The Fully Custom Design flow's two endpoints (PROJECT_SPEC §13):
 * uploading inspiration files, and submitting the request itself
 * (which creates the CustomOrder record and adds the $25 design fee
 * to the cart — the customer then pays it through the normal
 * WooCommerce checkout, same as any other cart item).
 *
 * No customer name/email field here — PROJECT_SPEC §13 doesn't list
 * one, and checkout already collects billing details; the CustomOrder
 * record picks those up from its linked order once payment completes
 * (class-custom-order-payment.php), rather than asking for them twice.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Custom_Order_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/custom-orders/uploads', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'upload' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/custom-orders', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'submit' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/custom-orders/options', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'options' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function upload( \WP_REST_Request $request ) {
		$files = $request->get_file_params();

		if ( empty( $files ) ) {
			return new \WP_Error( 'yeffoprint_no_files', __( 'No files were received.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		// Normalize a single multi-file <input name="files[]"> field
		// (PHP's $_FILES shape for that is arrays-of-arrays) into a flat
		// list of single-file arrays.
		$flat = [];
		foreach ( $files as $field ) {
			if ( is_array( $field['name'] ?? null ) ) {
				foreach ( $field['name'] as $i => $name ) {
					$flat[] = [
						'name'     => $name,
						'type'     => $field['type'][ $i ],
						'tmp_name' => $field['tmp_name'][ $i ],
						'error'    => $field['error'][ $i ],
						'size'     => $field['size'][ $i ],
					];
				}
			} else {
				$flat[] = $field;
			}
		}

		if ( count( $flat ) > YeffoPrint_Secure_Upload::MAX_FILES ) {
			return new \WP_Error(
				'yeffoprint_too_many_files',
				sprintf(
					/* translators: %d: max file count */
					__( 'Please attach %d files or fewer at a time.', 'yeffoprint-core' ),
					YeffoPrint_Secure_Upload::MAX_FILES
				),
				[ 'status' => 400 ]
			);
		}

		$results = [];
		foreach ( $flat as $file ) {
			$result = YeffoPrint_Secure_Upload::handle( $file );

			if ( is_wp_error( $result ) ) {
				$results[] = [ 'name' => $file['name'], 'success' => false, 'message' => $result->get_error_message() ];
			} else {
				$results[] = [
					'name'    => $file['name'],
					'success' => true,
					'id'      => $result,
					'url'     => wp_get_attachment_url( $result ),
				];
			}
		}

		return rest_ensure_response( [ 'files' => $results ] );
	}

	public function options() {
		$format = static function ( \WP_Post $post ) {
			return [
				'id'   => $post->ID,
				'name' => get_the_title( $post ),
			];
		};

		return rest_ensure_response( [
			'sizes'            => array_map( $format, $this->published( 'yp_size' ) ),
			'materials'        => array_map( $format, $this->published( 'yp_material' ) ),
			'design_fee'       => function_exists( 'yeffoprint_core_custom_design_fee_label' ) ? yeffoprint_core_custom_design_fee_label() : '',
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
		if ( ! $size_id || ! $this->is_published( 'yp_size', $size_id ) ) {
			return new \WP_Error( 'yeffoprint_invalid_size', __( 'Please choose a valid size.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$material_id = absint( $request->get_param( 'material_id' ) );
		if ( ! $material_id || ! $this->is_published( 'yp_material', $material_id ) ) {
			return new \WP_Error( 'yeffoprint_invalid_material', __( 'Please choose a valid material.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$quantity = absint( $request->get_param( 'quantity' ) );
		if ( $quantity < 1 ) {
			return new \WP_Error( 'yeffoprint_invalid_quantity', __( 'Quantity must be at least 1.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$brand_name = sanitize_text_field( (string) $request->get_param( 'brand_name' ) );
		if ( '' === $brand_name ) {
			return new \WP_Error( 'yeffoprint_missing_brand_name', __( 'Brand name is required.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$compound_strength = sanitize_text_field( (string) $request->get_param( 'compound_strength' ) );
		$style_notes        = sanitize_textarea_field( (string) $request->get_param( 'style_notes' ) );
		$instructions        = sanitize_textarea_field( (string) $request->get_param( 'instructions' ) );

		$uploads = $this->sanitize_upload_ids( $request->get_param( 'uploads' ) );

		$custom_order_id = wp_insert_post( [
			'post_type'   => 'yp_custom_order',
			'post_status' => 'draft', // Publishes once the $25 fee is paid — see class-custom-order-payment.php.
			'post_title'  => sprintf( '%s — %s', $brand_name, current_time( 'Y-m-d H:i' ) ),
		], true );

		if ( is_wp_error( $custom_order_id ) ) {
			return new \WP_Error( 'yeffoprint_custom_order_failed', __( "Couldn't submit your request. Please try again.", 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::SIZE_ID, $size_id );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::MATERIAL_ID, $material_id );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::QUANTITY, $quantity );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::COMPOUND_STRENGTH, $compound_strength );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BRAND_NAME, $brand_name );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STYLE_NOTES, $style_notes );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::INSTRUCTIONS, $instructions );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::INSPIRATION_UPLOADS, $uploads );

		$fee_product_id = YeffoPrint_Custom_Design_Fee_Product::get_product_id();
		if ( ! $fee_product_id ) {
			return new \WP_Error( 'yeffoprint_no_fee_product', __( 'Custom design orders are not available right now.', 'yeffoprint-core' ), [ 'status' => 503 ] );
		}

		YeffoPrint_Cart_Pricing::allow_next_add( true );
		$cart_item_key = WC()->cart->add_to_cart( $fee_product_id, 1, 0, [], [
			YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID => $custom_order_id,
		] );
		YeffoPrint_Cart_Pricing::allow_next_add( false );

		if ( ! $cart_item_key ) {
			wp_delete_post( $custom_order_id, true );
			return new \WP_Error( 'yeffoprint_add_to_cart_failed', __( "Couldn't add the design fee to your cart.", 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		return rest_ensure_response( [
			'success'       => true,
			'checkout_url'  => wc_get_checkout_url(),
			'cart_count'    => WC()->cart->get_cart_contents_count(),
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

	/** @return \WP_Post[] */
	private function published( string $post_type ): array {
		return get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		] );
	}
}
