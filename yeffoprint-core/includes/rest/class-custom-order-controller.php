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

	// Unauthenticated, multi-file, 10MB-per-file uploads are exactly the
	// kind of endpoint abuse tries first — this caps how many upload
	// requests one IP can make in a window, independent of
	// YeffoPrint_Secure_Upload's per-request file count/size limits.
	private const UPLOAD_RATE_LIMIT_WINDOW  = 600; // 10 minutes
	private const UPLOAD_RATE_LIMIT_MAX     = 20;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/custom-orders/uploads', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'upload' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'guest_or_nonced_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/custom-orders', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'submit' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'guest_or_nonced_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/custom-orders/options', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'options' ],
			'permission_callback' => '__return_true',
		] );

		// Reorder (V2): pre-fills a fresh Custom Design form from a past
		// request's own details. Unlike Template Reorder (which restores
		// into the configurator via a public-ish, session-scoped cart/
		// order lookup), a CustomOrder is always private customer data —
		// there's no guest path here, only the request's own customer.
		register_rest_route( self::NAMESPACE, '/custom-orders/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_custom_order' ],
			'permission_callback' => [ $this, 'check_ownership' ],
		] );

		// A literal-string path — never collides with the /(?P<id>\d+)
		// route above, since "eligible-reorders" can never match \d+.
		// Direct request: reorder a past design without the $25 fee, once
		// it's actually finished (YeffoPrint_Custom_Order_Meta::
		// is_eligible_for_fee_free_reorder()) — this is what the Custom
		// Design form's "Reorder a past design" picker lists.
		register_rest_route( self::NAMESPACE, '/custom-orders/eligible-reorders', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'eligible_reorders' ],
			'permission_callback' => static function () {
				return is_user_logged_in();
			},
		] );

		// Batch-aware pricing preview — direct request (batching). Can't
		// reuse /pricing/calculate as-is: that endpoint prices one
		// size/material/quantity against the cart's own existing
		// quantity, but a not-yet-submitted batch's rows need to count
		// toward *each other's* shared bulk-discount tier too, or the
		// preview would understate the real total once submitted.
		register_rest_route( self::NAMESPACE, '/custom-orders/pricing-preview', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'pricing_preview' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function check_ownership( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$post = get_post( absint( $request->get_param( 'id' ) ) );
		if ( ! $post || 'yp_custom_order' !== $post->post_type ) {
			return new \WP_Error( 'yeffoprint_custom_order_not_found', __( 'That custom design request was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$customer_id = (int) get_post_meta( $post->ID, YeffoPrint_Custom_Order_Meta::CUSTOMER_ID, true );
		return $customer_id && $customer_id === get_current_user_id();
	}

	public function get_custom_order( \WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$uploads = array_map( 'absint', (array) get_post_meta( $id, YeffoPrint_Custom_Order_Meta::INSPIRATION_UPLOADS, true ) );
		$upload_data = [];
		foreach ( $uploads as $attachment_id ) {
			$url = wp_get_attachment_url( $attachment_id );
			if ( $url ) {
				$upload_data[] = [
					'id'   => $attachment_id,
					'name' => get_the_title( $attachment_id ) ?: basename( $url ),
				];
			}
		}

		return rest_ensure_response( [
			// Direct report: reordering a past design that had more than
			// one label row (different compound/strength combos) only
			// ever brought back the first row — size_id/material_id/
			// quantity/compound_strength below are that same single row,
			// kept for whatever else in this response's own shape still
			// expects them, but `batch` is the real, complete answer:
			// every row this order actually had, via the same shared
			// reader the admin editor already uses for the same purpose
			// (falls back to a single row built from the fields below for
			// any order submitted before batching existed, which never
			// wrote a BATCH row at all).
			'size_id'           => (int) get_post_meta( $id, YeffoPrint_Custom_Order_Meta::SIZE_ID, true ),
			'material_id'       => (int) get_post_meta( $id, YeffoPrint_Custom_Order_Meta::MATERIAL_ID, true ),
			'quantity'          => (int) get_post_meta( $id, YeffoPrint_Custom_Order_Meta::QUANTITY, true ),
			'compound_strength' => (string) get_post_meta( $id, YeffoPrint_Custom_Order_Meta::COMPOUND_STRENGTH, true ),
			'batch'             => YeffoPrint_Custom_Order_Meta::get_batch_rows( $id ),
			'brand_name'        => (string) get_post_meta( $id, YeffoPrint_Custom_Order_Meta::BRAND_NAME, true ),
			'style_notes'       => (string) get_post_meta( $id, YeffoPrint_Custom_Order_Meta::STYLE_NOTES, true ),
			'instructions'      => (string) get_post_meta( $id, YeffoPrint_Custom_Order_Meta::INSTRUCTIONS, true ),
			'uploads'           => $upload_data,
		] );
	}

	/** Every one of the current customer's past custom label orders eligible to reorder without the design fee — the "Reorder a past design" picker's own data source. */
	public function eligible_reorders(): \WP_REST_Response {
		$customer_id = get_current_user_id();

		$posts = get_posts( [
			'post_type'      => 'yp_custom_order',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => YeffoPrint_Custom_Order_Meta::CUSTOMER_ID,
					'value' => $customer_id,
				],
			],
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$eligible = [];
		foreach ( $posts as $post ) {
			if ( ! YeffoPrint_Custom_Order_Meta::is_eligible_for_fee_free_reorder( $post->ID, $customer_id ) ) {
				continue;
			}

			$status = (string) get_post_meta( $post->ID, YeffoPrint_Custom_Order_Meta::STATUS, true );

			$eligible[] = [
				'id'           => $post->ID,
				'brand_name'   => (string) get_post_meta( $post->ID, YeffoPrint_Custom_Order_Meta::BRAND_NAME, true ),
				'status_label' => YeffoPrint_Custom_Order_Meta::get_status_label( $status ),
				'date'         => get_the_date( 'Y-m-d', $post ),
			];
		}

		return rest_ensure_response( $eligible );
	}

	public function pricing_preview( \WP_REST_Request $request ) {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart(); // So the shared tier quantity below reflects this session's actual cart, not an empty/uninitialized one.
		}

		$mode  = $this->parse_mode( $request );
		$batch = $this->validate_batch_rows( $request->get_param( 'batch' ) );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		// Every row in a not-yet-submitted batch counts toward the same
		// shared bulk-discount tier as each other, same as they will once
		// actually added to the cart as separate labels items (class-
		// cart-pricing.php's apply_price()) — otherwise this preview
		// would understate the real total.
		$tier_quantity = array_sum( array_column( $batch, 'quantity' ) ) + YeffoPrint_Cart_Pricing::combined_label_quantity();

		$rows     = [];
		$subtotal = 0.0;

		foreach ( $batch as $row ) {
			$material_adjustment = $this->record_adjustment( 'yp_material', $row['material_id'] );
			$size_adjustment      = $this->record_adjustment( 'yp_size', $row['size_id'] );
			$breakdown            = YeffoPrint_Pricing_Rule::calculate( $material_adjustment, $size_adjustment, $row['quantity'], $tier_quantity );

			$rows[]    = $breakdown;
			$subtotal += $breakdown['unit_price_after_discount'] * $row['quantity'];
		}

		// Skipped for 'own_design' and 'reorder' — see YeffoPrint_Custom_Order_Meta::is_fee_skipped().
		$design_fee = 'new_design' === $mode ? YeffoPrint_Pricing_Rule::get_custom_design_fee() : 0.0;

		return rest_ensure_response( [
			'rows'            => $rows,
			'design_fee'      => $design_fee,
			'labels_subtotal' => round( $subtotal, 2 ),
			'total'           => round( $subtotal + $design_fee, 2 ),
		] );
	}

	public function upload( \WP_REST_Request $request ) {
		$rate_limited = $this->check_upload_rate_limit();
		if ( is_wp_error( $rate_limited ) ) {
			return $rate_limited;
		}

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

	/**
	 * @return \WP_Error|null Error if this IP has hit the window's cap;
	 *                        null (and the attempt is now counted) otherwise.
	 */
	private function check_upload_rate_limit(): ?\WP_Error {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return null; // Can't key a limit without an IP — fail open rather than block legitimate requests.
		}

		$key   = 'yp_upload_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::UPLOAD_RATE_LIMIT_MAX ) {
			return new \WP_Error(
				'yeffoprint_rate_limited',
				__( 'Too many upload attempts. Please wait a few minutes and try again.', 'yeffoprint-core' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $key, $count + 1, self::UPLOAD_RATE_LIMIT_WINDOW );
		return null;
	}

	public function options() {
		// Raw post_title, not get_the_title() — this feeds custom-
		// order-form.js's <option> text via the same escapeHtml()
		// (textContent/innerHTML) round trip as the Custom Stickers and
		// main configurator size/material pickers, so it has the same
		// double-escaping problem with any texturized title (e.g. a
		// "2x3"-style Size name becomes the literal text "2&#215;3"
		// instead of "2×3") — see class-custom-sticker-controller.php's
		// options() for the full explanation.
		$format = static function ( \WP_Post $post ) {
			return [
				'id'   => $post->ID,
				'name' => $post->post_title,
			];
		};

		$material_format = static function ( \WP_Post $post ) {
			return [
				'id'       => $post->ID,
				'name'     => $post->post_title,
				'in_stock' => (bool) get_post_meta( $post->ID, YeffoPrint_Commerce_Record_Meta::IN_STOCK, true ),
			];
		};

		return rest_ensure_response( [
			'sizes'            => array_map( $format, $this->published( 'yp_size' ) ),
			// Scoped to 'label' — a Material an admin marks 'sticker'-only
			// (Custom Stickers) shouldn't leak into this, the label form's
			// picker. See YeffoPrint_Commerce_Record_Meta::get_materials_for().
			'materials'        => array_map( $material_format, YeffoPrint_Commerce_Record_Meta::get_materials_for( 'label' ) ),
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

		$mode = $this->parse_mode( $request );

		$batch = $this->validate_batch_rows( $request->get_param( 'batch' ) );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		$brand_name = sanitize_text_field( (string) $request->get_param( 'brand_name' ) );
		if ( '' === $brand_name ) {
			return new \WP_Error( 'yeffoprint_missing_brand_name', __( 'Brand name is required.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$style_notes  = sanitize_textarea_field( (string) $request->get_param( 'style_notes' ) );
		$instructions = sanitize_textarea_field( (string) $request->get_param( 'instructions' ) );
		$uploads      = $this->sanitize_upload_ids( $request->get_param( 'uploads' ) );

		// Direct request: reorder a past, already-finished design without
		// paying the fee again.
		$source_custom_order_id = 0;
		if ( 'reorder' === $mode ) {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error( 'yeffoprint_login_required', __( 'Please log in to reorder a past custom design.', 'yeffoprint-core' ), [ 'status' => 403 ] );
			}

			$source_custom_order_id = absint( $request->get_param( 'source_custom_order_id' ) );
			if ( ! $source_custom_order_id || ! YeffoPrint_Custom_Order_Meta::is_eligible_for_fee_free_reorder( $source_custom_order_id, get_current_user_id() ) ) {
				return new \WP_Error(
					'yeffoprint_ineligible_reorder',
					__( "That design isn't eligible to reorder without the design fee — it may still be in progress, or may not belong to you.", 'yeffoprint-core' ),
					[ 'status' => 403 ]
				);
			}
		}

		// Direct request: a customer who already has their own completed,
		// print-ready design needs no design work, so no fee — but they
		// do need to actually attach it, or there's nothing to print.
		if ( 'own_design' === $mode && ! $uploads ) {
			return new \WP_Error( 'yeffoprint_own_design_upload_required', __( 'Please attach your print-ready design file(s).', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		// Row 0 also populates the legacy single-row fields, so anything
		// still reading only SIZE_ID/MATERIAL_ID/QUANTITY/COMPOUND_STRENGTH
		// (rather than BATCH) keeps working on an order created here.
		$first_row = $batch[0];

		// Publishes once the $25 fee is paid (or immediately eligible once
		// paid, for a fee-skipped order) — see class-custom-order-payment.php.
		// Customer identity stays empty here, exactly as before this was
		// extracted into create_shell() — filled in later from the WC
		// order's billing details once payment completes.
		$custom_order_id = YeffoPrint_Custom_Order_Meta::create_shell(
			'label',
			sprintf( '%s — %s', $brand_name, current_time( 'Y-m-d H:i' ) ),
			0,
			'',
			''
		);

		if ( ! $custom_order_id ) {
			return new \WP_Error( 'yeffoprint_custom_order_failed', __( "Couldn't submit your request. Please try again.", 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::SIZE_ID, $first_row['size_id'] );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::MATERIAL_ID, $first_row['material_id'] );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::QUANTITY, $first_row['quantity'] );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::COMPOUND_STRENGTH, $first_row['compound_strength'] );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BATCH, wp_json_encode( $batch ) );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BRAND_NAME, $brand_name );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STYLE_NOTES, $style_notes );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::INSTRUCTIONS, $instructions );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::INSPIRATION_UPLOADS, $uploads );

		if ( 'own_design' === $mode ) {
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_PROVIDED_DESIGN, '1' );
			// Same files, also under ARTWORK_UPLOADS — that's the field
			// staff/the admin editor treat as "the actual print file(s)",
			// distinct from INSPIRATION_UPLOADS' "reference only" meaning.
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::ARTWORK_UPLOADS, $uploads );
		}

		if ( 'reorder' === $mode ) {
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::SOURCE_CUSTOM_ORDER_ID, $source_custom_order_id );
		}

		// ACCESS_TOKEN already generated by create_shell() above — not
		// lazily when a proof first exists — a guest customer has no
		// account to log back into, so the same link (grabbed from the
		// admin screen and sent to them) has to keep working for this
		// request's entire lifetime.

		$labels_product_id = YeffoPrint_Custom_Order_Labels_Product::get_product_id();
		if ( ! $labels_product_id ) {
			wp_delete_post( $custom_order_id, true );
			return new \WP_Error( 'yeffoprint_no_labels_product', __( 'Custom design orders are not available right now.', 'yeffoprint-core' ), [ 'status' => 503 ] );
		}

		$added_cart_item_keys = [];

		// Fee item — only for a normal new-design submission. Added
		// first, same order as before this change, so a fee-item-add
		// failure never leaves any labels items behind either.
		if ( 'new_design' === $mode ) {
			$fee_product_id = YeffoPrint_Custom_Design_Fee_Product::get_product_id();
			if ( ! $fee_product_id ) {
				wp_delete_post( $custom_order_id, true );
				return new \WP_Error( 'yeffoprint_no_fee_product', __( 'Custom design orders are not available right now.', 'yeffoprint-core' ), [ 'status' => 503 ] );
			}

			YeffoPrint_Cart_Pricing::allow_next_add( true );
			$fee_cart_item_key = WC()->cart->add_to_cart( $fee_product_id, 1, 0, [], [
				YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID => $custom_order_id,
			] );
			YeffoPrint_Cart_Pricing::allow_next_add( false );

			if ( ! $fee_cart_item_key ) {
				wp_delete_post( $custom_order_id, true );
				return new \WP_Error( 'yeffoprint_add_to_cart_failed', __( "Couldn't add the design fee to your cart.", 'yeffoprint-core' ), [ 'status' => 400 ] );
			}

			$added_cart_item_keys[] = $fee_cart_item_key;
		}

		// One labels line item per batch row — priced per-unit from the
		// same size/material adjustments and bulk tiers as a Template
		// batch (class-cart-pricing.php's apply_price()). CUSTOM_ORDER_ID
		// links every row back to the same record; CUSTOM_ORDER_ROW_INDEX
		// is required, not informational — see its own doc comment in
		// class-cart-item-keys.php for why two rows with identical data
		// would otherwise silently merge into one WooCommerce line item.
		foreach ( $batch as $row_index => $row ) {
			YeffoPrint_Cart_Pricing::allow_next_add( true );
			$row_cart_item_key = WC()->cart->add_to_cart( $labels_product_id, $row['quantity'], 0, [], [
				YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID        => $custom_order_id,
				YeffoPrint_Cart_Item_Keys::SIZE_ID                => $row['size_id'],
				YeffoPrint_Cart_Item_Keys::MATERIAL_ID            => $row['material_id'],
				YeffoPrint_Cart_Item_Keys::TOTAL_QTY              => $row['quantity'],
				YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ROW_INDEX => $row_index,
				YeffoPrint_Cart_Item_Keys::COMPOUND_STRENGTH      => $row['compound_strength'],
			] );
			YeffoPrint_Cart_Pricing::allow_next_add( false );

			if ( ! $row_cart_item_key ) {
				foreach ( $added_cart_item_keys as $added_key ) {
					WC()->cart->remove_cart_item( $added_key );
				}
				wp_delete_post( $custom_order_id, true );
				return new \WP_Error( 'yeffoprint_add_to_cart_failed', __( "Couldn't add your labels to your cart.", 'yeffoprint-core' ), [ 'status' => 400 ] );
			}

			$added_cart_item_keys[] = $row_cart_item_key;
		}

		return rest_ensure_response( [
			'success'      => true,
			'checkout_url' => wc_get_checkout_url(),
			'cart_count'   => WC()->cart->get_cart_contents_count(),
		] );
	}

	/** 'new_design' (default) / 'own_design' / 'reorder' — anything else falls back to 'new_design' rather than erroring, same as an unrecognized sort value elsewhere in this plugin (class-template-query.php). */
	private function parse_mode( \WP_REST_Request $request ): string {
		$mode = sanitize_key( (string) $request->get_param( 'mode' ) );
		return in_array( $mode, [ 'own_design', 'reorder' ], true ) ? $mode : 'new_design';
	}

	/**
	 * Validates and sanitizes the batch[] payload shared by submit() and
	 * pricing_preview() — direct request (batching): more than one
	 * compound/strength/size under one custom design order, each row its
	 * own size + material + quantity + compound/strength.
	 *
	 * @return array<int, array{size_id:int, material_id:int, quantity:int, compound_strength:string}>|\WP_Error
	 */
	private function validate_batch_rows( $raw ) {
		if ( ! is_array( $raw ) || ! $raw ) {
			return new \WP_Error( 'yeffoprint_empty_batch', __( 'Please add at least one label to your order.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$rows = [];

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$size_id = absint( $row['size_id'] ?? 0 );
			if ( ! $size_id || ! $this->is_published( 'yp_size', $size_id ) ) {
				return new \WP_Error( 'yeffoprint_invalid_size', __( 'Please choose a valid size for every label.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}

			$material_id = absint( $row['material_id'] ?? 0 );
			if ( ! $material_id || ! $this->is_published( 'yp_material', $material_id ) ) {
				return new \WP_Error( 'yeffoprint_invalid_material', __( 'Please choose a valid material for every label.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}

			if ( ! (bool) get_post_meta( $material_id, YeffoPrint_Commerce_Record_Meta::IN_STOCK, true ) ) {
				return new \WP_Error( 'yeffoprint_material_out_of_stock', __( 'One of the materials you chose is currently out of stock. Please choose a different one.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}

			$quantity = absint( $row['quantity'] ?? 0 );
			if ( $quantity < 1 ) {
				return new \WP_Error( 'yeffoprint_invalid_quantity', __( 'Quantity must be at least 1 for every label.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}

			$rows[] = [
				'size_id'           => $size_id,
				'material_id'       => $material_id,
				'quantity'          => $quantity,
				'compound_strength' => sanitize_text_field( (string) ( $row['compound_strength'] ?? '' ) ),
			];
		}

		if ( ! $rows ) {
			return new \WP_Error( 'yeffoprint_empty_batch', __( 'Please add at least one label to your order.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		return $rows;
	}

	/** Same shape as class-pricing-controller.php's own private helper of the same name — kept local rather than shared, matching this plugin's existing per-controller convention (e.g. is_published()/published() below are also not shared). */
	private function record_adjustment( string $post_type, int $post_id ): float {
		$post = get_post( $post_id );
		if ( ! $post || $post_type !== $post->post_type || 'publish' !== $post->post_status ) {
			return 0.0;
		}

		return (float) get_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, true );
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
