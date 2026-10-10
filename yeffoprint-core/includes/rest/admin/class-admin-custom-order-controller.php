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
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_unpaid' ],
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

	/**
	 * Direct request: unpaid requests were "stuck on awaiting payment"
	 * with no way to remove one when the customer changes their mind.
	 * Moves it to the trash (restorable from wp-admin) — only while it's
	 * still unpaid. A request that's part of an unpaid WooCommerce order
	 * is refused with that order's number instead: cancelling the order
	 * removes the request too (class-custom-order-payment.php), and
	 * deleting just the request would leave a pay link for labels with
	 * no proof behind them.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_unpaid( \WP_REST_Request $request ) {
		$post = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( 'draft' !== $post->post_status ) {
			return new \WP_Error( 'yeffoprint_custom_order_paid', __( 'Only unpaid requests can be deleted.', 'yeffoprint-core' ), [ 'status' => 409 ] );
		}

		$open_order = $this->open_order_for( $post->ID );
		if ( $open_order ) {
			return new \WP_Error(
				'yeffoprint_custom_order_on_open_order',
				/* translators: %s: order number */
				sprintf( __( 'This request is part of unpaid order #%s. Cancel that order instead and this request is removed with it.', 'yeffoprint-core' ), $open_order->get_order_number() ),
				[ 'status' => 409 ]
			);
		}

		wp_trash_post( $post->ID );

		return rest_ensure_response( [ 'id' => $post->ID, 'deleted' => true ] );
	}

	/** The still-payable WooCommerce order (if any) with a line item pointing at this request. */
	private function open_order_for( int $custom_order_id ): ?\WC_Order {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WooCommerce has no API for "which orders carry this item meta value".
		$order_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT items.order_id FROM {$wpdb->prefix}woocommerce_order_items items
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta meta ON meta.order_item_id = items.order_item_id
			WHERE meta.meta_key = '_yp_custom_order_id' AND meta.meta_value = %d",
			$custom_order_id
		) );

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( $order instanceof \WC_Order && $order->has_status( [ 'pending', 'failed', 'on-hold', 'checkout-draft' ] ) ) {
				return $order;
			}
		}

		return null;
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
			'title'                 => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
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

		$order_type  = YeffoPrint_Custom_Order_Meta::get_order_type( $post->ID );
		$is_sticker  = 'sticker' === $order_type;
		$is_template = 'template' === $order_type;
		$status      = (string) $m( YeffoPrint_Custom_Order_Meta::STATUS );
		$wc_order_id = (int) $m( YeffoPrint_Custom_Order_Meta::WC_ORDER_ID );

		// Reorder/fee-skip are Custom Design ('label') concepts only —
		// a Template order was never eligible for either (it's priced
		// exactly like a normal checkout batch, no fee to skip in the
		// first place), same as Custom Stickers already excludes itself.
		$customer_provided_design = 'label' === $order_type && (bool) $m( YeffoPrint_Custom_Order_Meta::CUSTOMER_PROVIDED_DESIGN );
		$source_custom_order_id   = 'label' === $order_type ? (int) $m( YeffoPrint_Custom_Order_Meta::SOURCE_CUSTOM_ORDER_ID ) : 0;

		$payload = [
			'id'                  => $post->ID,
			'title'               => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
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
			// Unpaid only: the pay-link/checkout order this request is
			// waiting on, if any — cancelling that order is how it's removed.
			'unpaid_order_id'     => 'draft' === $post->post_status && ( $open_order = $this->open_order_for( $post->ID ) ) ? $open_order->get_id() : 0,
			'change_request_notes' => (string) $m( YeffoPrint_Custom_Order_Meta::CHANGE_REQUEST_NOTES ),
			'customer_provided_design' => $customer_provided_design,
			'source_custom_order_id'  => $source_custom_order_id,
			'design_fee'          => (float) $m( YeffoPrint_Custom_Order_Meta::DESIGN_FEE ),
			'fee_skipped'         => YeffoPrint_Custom_Order_Meta::is_fee_skipped( $post->ID ),
			'date'                => get_post_datetime( $post ) ? get_post_datetime( $post )->format( 'c' ) : null,
			'proofs'              => $this->proofs_payload( $post->ID ),
			'approval_url'        => 'publish' === $post->post_status ? yeffoprint_core_proof_approval_url( $post->ID ) : '',
			// Staff-visible state for the automated proof-approval
			// reminder (class-proof-reminder-scheduler.php) — 0/absent
			// means no reminder sent yet (or status isn't
			// awaiting_approval at all, in which case this is simply
			// stale/irrelevant and the UI has no reason to show it).
			'proof_reminder_stage' => (int) $m( YeffoPrint_Custom_Order_Meta::PROOF_REMINDER_STAGE ),
			'awaiting_approval_since' => ( $awaiting_since = (int) $m( YeffoPrint_Custom_Order_Meta::AWAITING_APPROVAL_AT ) ) ? gmdate( 'c', $awaiting_since ) : null,
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
				'size_label'         => $is_custom_size ? '' : ( $size_id ? html_entity_decode( get_the_title( $size_id ), ENT_QUOTES, 'UTF-8' ) : '' ),
				'custom_width_in'    => (string) $m( YeffoPrint_Custom_Order_Meta::CUSTOM_WIDTH_IN ),
				'custom_height_in'   => (string) $m( YeffoPrint_Custom_Order_Meta::CUSTOM_HEIGHT_IN ),
				'material_id'        => $material_id,
				'material_label'     => $material_id ? html_entity_decode( get_the_title( $material_id ), ENT_QUOTES, 'UTF-8' ) : '',
				'quantity'           => (int) $m( YeffoPrint_Custom_Order_Meta::QUANTITY ),
				'instructions'       => (string) $m( YeffoPrint_Custom_Order_Meta::INSTRUCTIONS ),
				'artwork_uploads'    => $this->upload_payload( (array) $m( YeffoPrint_Custom_Order_Meta::ARTWORK_UPLOADS ) ),
			];
		} elseif ( $is_template ) {
			$template_id = (int) $m( YeffoPrint_Custom_Order_Meta::TEMPLATE_ID );
			$size_id     = (int) $m( YeffoPrint_Custom_Order_Meta::SIZE_ID );
			$material_id = (int) $m( YeffoPrint_Custom_Order_Meta::MATERIAL_ID );

			$raw_variants = (string) $m( YeffoPrint_Custom_Order_Meta::TEMPLATE_VARIANTS );
			$variants     = $raw_variants ? json_decode( $raw_variants, true ) : null;
			$variants     = is_array( $variants ) ? $variants : [];

			// Direct report: "I can't see all of the customizations I
			// entered in." Reads the field schema frozen at this shell's
			// own creation time (TEMPLATE_FIELD_SCHEMA's own docblock)
			// rather than the template's current live schema — a field
			// later renamed/removed/re-ID'd on the template was silently
			// dropping its stored value here, since the live schema no
			// longer has a matching field to attach it to. Falls back to
			// the live schema only for a shell created before this
			// snapshot existed at all (no snapshot meta to read).
			$raw_field_schema = (string) $m( YeffoPrint_Custom_Order_Meta::TEMPLATE_FIELD_SCHEMA );
			$field_schema     = $raw_field_schema ? json_decode( $raw_field_schema, true ) : null;
			$field_schema     = is_array( $field_schema ) ? $field_schema : ( $template_id ? YeffoPrint_Field_Schema::get_with_colors( $template_id ) : [] );

			$payload['template'] = [
				'template_id'    => $template_id,
				'template_title' => $template_id ? html_entity_decode( get_the_title( $template_id ), ENT_QUOTES, 'UTF-8' ) : '',
				'size_id'        => $size_id,
				'size_label'     => $size_id ? html_entity_decode( get_the_title( $size_id ), ENT_QUOTES, 'UTF-8' ) : '',
				'material_id'    => $material_id,
				'material_label' => $material_id ? html_entity_decode( get_the_title( $material_id ), ENT_QUOTES, 'UTF-8' ) : '',
				'variants'       => array_map( static function ( array $variant ) use ( $field_schema ) {
					return [
						'quantity' => (int) ( $variant['quantity'] ?? 0 ),
						'summary'  => YeffoPrint_Field_Schema::format_variant_summary( $variant, $field_schema ),
					];
				}, $variants ),
				'instructions'   => (string) $m( YeffoPrint_Custom_Order_Meta::INSTRUCTIONS ),
			];
		} else {
			$batch_rows = array_map( function ( array $row ) {
				return [
					'size_id'           => (int) ( $row['size_id'] ?? 0 ),
					'size_label'        => ! empty( $row['size_id'] ) ? html_entity_decode( get_the_title( (int) $row['size_id'] ), ENT_QUOTES, 'UTF-8' ) : '',
					'material_id'       => (int) ( $row['material_id'] ?? 0 ),
					'material_label'    => ! empty( $row['material_id'] ) ? html_entity_decode( get_the_title( (int) $row['material_id'] ), ENT_QUOTES, 'UTF-8' ) : '',
					'quantity'          => (int) ( $row['quantity'] ?? 0 ),
					'compound_strength' => (string) ( $row['compound_strength'] ?? '' ),
					'qr_url'            => (string) ( $row['qr_url'] ?? '' ),
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
				'title' => html_entity_decode( get_the_title( $proof_id ), ENT_QUOTES, 'UTF-8' ) ?: __( 'Proof', 'yeffoprint-core' ),
				'date'  => get_the_date( 'c', $proof_id ),
				'file_url' => $file_id ? wp_get_attachment_url( $file_id ) : '',
			];
		}, YeffoPrint_Proof_Meta::get_for_custom_order( $custom_order_id ) );
	}
}
