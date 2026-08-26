<?php
/**
 * Admin REST endpoint for the Custom Orders screen (docs/ARCHITECTURE.md,
 * Phase 6). `yp_custom_order` has `show_in_rest` on at the post-type
 * level (every CPT here does — `class-post-type-registry.php`'s
 * `args()` helper), but none of its fields were ever registered with
 * `register_post_meta()` — `class-custom-order-editor.php`'s classic
 * screen reads/writes every one of them with plain `get_post_meta()`/
 * `update_post_meta()` directly, so there was nothing for WP core's
 * own `/wp/v2/yp_custom_order` route to expose even in principle. This
 * is therefore a full read/write surface, not a small gap-filler like
 * Phase 4a/5's controllers.
 *
 * Read-only by design past `status`: everything else here is what the
 * customer submitted (`class-custom-order-controller.php`) or what
 * payment completion filled in (`class-custom-order-payment.php`) —
 * the classic editor doesn't let staff edit those either, and this
 * doesn't change that; `save_status()` is the one write this
 * controller offers, same as `class-custom-order-editor.php::save()`'s
 * own single writable field.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Custom_Order_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/custom-orders', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_orders' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/custom-order/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_order' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'save_status' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );
	}

	public function list_orders( \WP_REST_Request $request ): \WP_REST_Response {
		$args = [
			'post_type'      => 'yp_custom_order',
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( $status && array_key_exists( $status, YeffoPrint_Custom_Order_Meta::STATUSES ) ) {
			$args['meta_query'] = [ [ 'key' => YeffoPrint_Custom_Order_Meta::STATUS, 'value' => $status ] ];
		}

		$posts = get_posts( $args );

		return rest_ensure_response( array_map( [ $this, 'summary_row' ], $posts ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get_order( \WP_REST_Request $request ) {
		$post = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return rest_ensure_response( $this->detail_payload( $post ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function save_status( \WP_REST_Request $request ) {
		$post = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( 'publish' !== $post->post_status ) {
			return new \WP_Error(
				'yeffoprint_custom_order_unpaid',
				__( 'This request is still awaiting the design fee payment — status is set automatically once paid.', 'yeffoprint-core' ),
				[ 'status' => 409 ]
			);
		}

		$params = $request->get_json_params() ?: [];
		$status = sanitize_key( (string) ( $params['status'] ?? '' ) );
		if ( ! array_key_exists( $status, YeffoPrint_Custom_Order_Meta::STATUSES ) ) {
			return new \WP_Error( 'yeffoprint_invalid_status', __( 'That is not a valid status.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		update_post_meta( $post->ID, YeffoPrint_Custom_Order_Meta::STATUS, $status );

		return rest_ensure_response( $this->detail_payload( $post ) );
	}

	/** @return \WP_Post|\WP_Error */
	private function validate_order( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'yp_custom_order' !== $post->post_type ) {
			return new \WP_Error(
				'yeffoprint_custom_order_not_found',
				__( 'That request could not be found.', 'yeffoprint-core' ),
				[ 'status' => 404 ]
			);
		}

		return $post;
	}

	private function summary_row( \WP_Post $post ): array {
		$m = static function ( string $key ) use ( $post ) {
			return get_post_meta( $post->ID, $key, true );
		};

		$status = (string) $m( YeffoPrint_Custom_Order_Meta::STATUS );

		return [
			'id'                    => $post->ID,
			'title'                 => get_the_title( $post ),
			'order_type'            => YeffoPrint_Custom_Order_Meta::get_order_type( $post->ID ),
			'order_type_label'      => YeffoPrint_Custom_Order_Meta::ORDER_TYPES[ YeffoPrint_Custom_Order_Meta::get_order_type( $post->ID ) ],
			'status'                => $status,
			'status_label'          => $status ? YeffoPrint_Custom_Order_Meta::get_status_label( $status ) : '',
			'paid'                  => 'publish' === $post->post_status,
			'customer_name'         => (string) $m( YeffoPrint_Custom_Order_Meta::CUSTOMER_NAME ),
			'customer_email'        => (string) $m( YeffoPrint_Custom_Order_Meta::CUSTOMER_EMAIL ),
			'has_change_request'    => 'design_in_progress' === $status && (bool) $m( YeffoPrint_Custom_Order_Meta::CHANGE_REQUEST_NOTES ),
			'date'                  => get_post_datetime( $post ) ? get_post_datetime( $post )->format( 'c' ) : null,
		];
	}

	private function detail_payload( \WP_Post $post ): array {
		$m = static function ( string $key ) use ( $post ) {
			return get_post_meta( $post->ID, $key, true );
		};

		$order_type = YeffoPrint_Custom_Order_Meta::get_order_type( $post->ID );
		$is_sticker = 'sticker' === $order_type;
		$status     = (string) $m( YeffoPrint_Custom_Order_Meta::STATUS );
		$wc_order_id = (int) $m( YeffoPrint_Custom_Order_Meta::WC_ORDER_ID );

		$customer_provided_design = ! $is_sticker && (bool) $m( YeffoPrint_Custom_Order_Meta::CUSTOMER_PROVIDED_DESIGN );
		$source_custom_order_id   = $is_sticker ? 0 : (int) $m( YeffoPrint_Custom_Order_Meta::SOURCE_CUSTOM_ORDER_ID );

		$payload = [
			'id'                  => $post->ID,
			'title'               => get_the_title( $post ),
			'order_type'          => $order_type,
			'order_type_label'    => YeffoPrint_Custom_Order_Meta::ORDER_TYPES[ $order_type ],
			'status'              => $status,
			'status_label'        => $status ? YeffoPrint_Custom_Order_Meta::get_status_label( $status ) : '',
			'statuses'            => YeffoPrint_Custom_Order_Meta::STATUSES,
			'paid'                => 'publish' === $post->post_status,
			'customer_name'       => (string) $m( YeffoPrint_Custom_Order_Meta::CUSTOMER_NAME ),
			'customer_email'      => (string) $m( YeffoPrint_Custom_Order_Meta::CUSTOMER_EMAIL ),
			'wc_order_id'         => $wc_order_id,
			'wc_order_edit_url'   => $wc_order_id ? admin_url( 'post.php?post=' . $wc_order_id . '&action=edit' ) : '',
			'change_request_notes' => (string) $m( YeffoPrint_Custom_Order_Meta::CHANGE_REQUEST_NOTES ),
			'customer_provided_design' => $customer_provided_design,
			'source_custom_order_id'  => $source_custom_order_id,
			'design_fee'          => (float) $m( YeffoPrint_Custom_Order_Meta::DESIGN_FEE ),
			'fee_skipped'         => YeffoPrint_Custom_Order_Meta::is_fee_skipped( $post->ID ),
			'date'                => get_post_datetime( $post ) ? get_post_datetime( $post )->format( 'c' ) : null,
			'proofs'              => $this->proofs_payload( $post->ID ),
			'approval_url'        => 'publish' === $post->post_status ? yeffoprint_core_proof_approval_url( $post->ID ) : '',
		];

		if ( $is_sticker ) {
			$size_id     = (int) $m( YeffoPrint_Custom_Order_Meta::SIZE_ID );
			$material_id = (int) $m( YeffoPrint_Custom_Order_Meta::MATERIAL_ID );
			$is_custom_size = $size_id && (bool) get_post_meta( $size_id, YeffoPrint_Sticker_Size_Meta::IS_CUSTOM, true );
			$sticker_type   = (string) $m( YeffoPrint_Custom_Order_Meta::STICKER_TYPE );
			$shape          = (string) $m( YeffoPrint_Custom_Order_Meta::SHAPE );

			$payload['sticker'] = [
				'sticker_type'       => $sticker_type,
				'sticker_type_label' => YeffoPrint_Sticker_Pricing::TYPES[ $sticker_type ] ?? '',
				'shape'              => $shape,
				'shape_label'        => YeffoPrint_Sticker_Pricing::SHAPES[ $shape ] ?? '',
				'is_custom_size'     => $is_custom_size,
				'size_id'            => $size_id,
				'size_label'         => $is_custom_size ? '' : ( $size_id ? get_the_title( $size_id ) : '' ),
				'custom_width_in'    => (string) $m( YeffoPrint_Custom_Order_Meta::CUSTOM_WIDTH_IN ),
				'custom_height_in'   => (string) $m( YeffoPrint_Custom_Order_Meta::CUSTOM_HEIGHT_IN ),
				'material_id'        => $material_id,
				'material_label'     => $material_id ? get_the_title( $material_id ) : '',
				'quantity'           => (int) $m( YeffoPrint_Custom_Order_Meta::QUANTITY ),
				'instructions'       => (string) $m( YeffoPrint_Custom_Order_Meta::INSTRUCTIONS ),
				'artwork_uploads'    => $this->upload_payload( (array) $m( YeffoPrint_Custom_Order_Meta::ARTWORK_UPLOADS ) ),
			];
		} else {
			$batch_rows = array_map( function ( array $row ) {
				return [
					'size_id'           => (int) ( $row['size_id'] ?? 0 ),
					'size_label'        => ! empty( $row['size_id'] ) ? get_the_title( (int) $row['size_id'] ) : '',
					'material_id'       => (int) ( $row['material_id'] ?? 0 ),
					'material_label'    => ! empty( $row['material_id'] ) ? get_the_title( (int) $row['material_id'] ) : '',
					'quantity'          => (int) ( $row['quantity'] ?? 0 ),
					'compound_strength' => (string) ( $row['compound_strength'] ?? '' ),
				];
			}, YeffoPrint_Custom_Order_Meta::get_batch_rows( $post->ID ) );

			$label_files = $customer_provided_design
				? (array) $m( YeffoPrint_Custom_Order_Meta::ARTWORK_UPLOADS )
				: (array) $m( YeffoPrint_Custom_Order_Meta::INSPIRATION_UPLOADS );

			$payload['label'] = [
				'brand_name' => (string) $m( YeffoPrint_Custom_Order_Meta::BRAND_NAME ),
				'batch'      => $batch_rows,
				'style_notes'   => (string) $m( YeffoPrint_Custom_Order_Meta::STYLE_NOTES ),
				'instructions'  => (string) $m( YeffoPrint_Custom_Order_Meta::INSTRUCTIONS ),
				'uploads'       => $this->upload_payload( $label_files ),
				'uploads_label' => $customer_provided_design
					? __( 'Print-Ready Design File(s)', 'yeffoprint-core' )
					: __( 'Inspiration Files', 'yeffoprint-core' ),
			];
		}

		return $payload;
	}

	/** @param int[] $attachment_ids @return array<int, array{id:int, url:string, name:string}> */
	private function upload_payload( array $attachment_ids ): array {
		$result = [];
		foreach ( $attachment_ids as $attachment_id ) {
			$url = wp_get_attachment_url( (int) $attachment_id );
			if ( ! $url ) {
				continue;
			}
			$result[] = [ 'id' => (int) $attachment_id, 'url' => $url, 'name' => basename( $url ) ];
		}
		return $result;
	}

	private function proofs_payload( int $custom_order_id ): array {
		return array_map( function ( int $proof_id ) {
			$file_id = (int) get_post_meta( $proof_id, YeffoPrint_Proof_Meta::FILE_ID, true );
			return [
				'id'    => $proof_id,
				'title' => get_the_title( $proof_id ) ?: __( 'Proof', 'yeffoprint-core' ),
				'date'  => get_the_date( 'c', $proof_id ),
				'file_url' => $file_id ? wp_get_attachment_url( $file_id ) : '',
			];
		}, YeffoPrint_Proof_Meta::get_for_custom_order( $custom_order_id ) );
	}
}
