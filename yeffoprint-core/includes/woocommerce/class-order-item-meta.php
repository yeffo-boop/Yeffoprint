<?php
/**
 * Freezes a batch's structured data onto the order line item at
 * checkout — Architecture §5: order metadata must be able to
 * re-render the exact configurator state (future "Edit"/"Reorder")
 * and preserve which PricingRule version priced the order, even after
 * Templates/Sizes/Materials/pricing change later. Also handles the
 * custom design fee line item (PROJECT_SPEC §13), which just needs a
 * back-reference to its yp_custom_order record — the actual linking/
 * status-advancing happens on payment, see class-custom-order-payment.php.
 *
 * Deliberately separate, named meta keys per fact (template snapshot,
 * size snapshot, material snapshot, pricing snapshot, variants) rather
 * than one blob — PROJECT_SPEC §14: "never one opaque serialized
 * blob." `_yp_variants` is itself one JSON array because a batch's
 * variant list is inherently one repeating structured unit — same
 * reasoning already applied to field_schema in Phase 4.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Item_Meta {

	public function __construct() {
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'snapshot' ], 10, 4 );
		add_filter( 'woocommerce_hidden_order_itemmeta', [ $this, 'hide_internal_keys' ] );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', [ $this, 'add_qr_download_links' ], 10, 2 );
	}

	public function snapshot( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ): void {
		$custom_order_id = (int) ( $values[ YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID ] ?? 0 );

		if ( $custom_order_id && empty( $values[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ) ) {
			$this->snapshot_custom_order_fee( $item, $custom_order_id );
			return;
		}

		if ( empty( $values[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ) ) {
			return;
		}

		$size_id     = (int) ( $values[ YeffoPrint_Cart_Item_Keys::SIZE_ID ] ?? 0 );
		$material_id = (int) ( $values[ YeffoPrint_Cart_Item_Keys::MATERIAL_ID ] ?? 0 );
		$quantity    = (int) $values[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ];
		$pricing     = YeffoPrint_Cart_Pricing::calculate_for_cart_item( $values );

		if ( $custom_order_id ) {
			// A Custom Order's own labels: same Size/Material/pricing
			// snapshot shape as a Template batch, but there's no
			// template/field_schema/variants behind it (Architecture §2).
			$this->snapshot_custom_order_labels( $item, $custom_order_id, $size_id, $material_id, $quantity, $pricing );
			return;
		}

		$template_id  = (int) ( $values[ YeffoPrint_Cart_Item_Keys::TEMPLATE_ID ] ?? 0 );
		$variants     = (array) ( $values[ YeffoPrint_Cart_Item_Keys::VARIANTS ] ?? [] );
		$field_schema = $template_id ? YeffoPrint_Field_Schema::get( $template_id ) : [];

		$item->add_meta_data( '_yp_template_snapshot', wp_json_encode( [
			'id'           => $template_id,
			'title'        => $template_id ? get_the_title( $template_id ) : '',
			'field_schema' => $field_schema,
		] ), true );

		$item->add_meta_data( '_yp_size_snapshot', wp_json_encode( $this->record_snapshot( $size_id ) ), true );
		$item->add_meta_data( '_yp_material_snapshot', wp_json_encode( $this->record_snapshot( $material_id ) ), true );
		$item->add_meta_data( '_yp_variants', wp_json_encode( $variants ), true );
		$item->add_meta_data( '_yp_batch_quantity', $quantity, true );
		$item->add_meta_data( '_yp_pricing_snapshot', wp_json_encode( $pricing ), true );

		// Human-readable rows for the admin order screen / customer
		// emails, alongside the machine-readable snapshots above.
		$item->add_meta_data( __( 'Size', 'yeffoprint-core' ), $size_id ? get_the_title( $size_id ) : '—', true );
		$item->add_meta_data( __( 'Material', 'yeffoprint-core' ), $material_id ? get_the_title( $material_id ) : '—', true );
		$item->add_meta_data( __( 'Labels in this batch', 'yeffoprint-core' ), count( $variants ), true );

		$this->add_variant_rows( $item, $variants, $field_schema );
	}

	private function snapshot_custom_order_labels( \WC_Order_Item_Product $item, int $custom_order_id, int $size_id, int $material_id, int $quantity, ?array $pricing ): void {
		$item->add_meta_data( '_yp_custom_order_id', $custom_order_id, true );
		$item->add_meta_data( '_yp_size_snapshot', wp_json_encode( $this->record_snapshot( $size_id ) ), true );
		$item->add_meta_data( '_yp_material_snapshot', wp_json_encode( $this->record_snapshot( $material_id ) ), true );
		$item->add_meta_data( '_yp_batch_quantity', $quantity, true );
		$item->add_meta_data( '_yp_pricing_snapshot', wp_json_encode( $pricing ), true );

		$item->add_meta_data( __( 'Size', 'yeffoprint-core' ), $size_id ? get_the_title( $size_id ) : '—', true );
		$item->add_meta_data( __( 'Material', 'yeffoprint-core' ), $material_id ? get_the_title( $material_id ) : '—', true );
		$item->add_meta_data( __( 'Quantity', 'yeffoprint-core' ), $quantity, true );
	}

	/**
	 * The actual per-label customization (compound, strength, brand
	 * name — whatever the Template's field_schema defines) was
	 * previously only stored in the hidden _yp_variants JSON, invisible
	 * on the order screen and in customer emails even though it's the
	 * one thing staff actually need to know what to print.
	 */
	private function add_variant_rows( \WC_Order_Item_Product $item, array $variants, array $field_schema ): void {
		$multiple = count( $variants ) > 1;

		foreach ( $variants as $index => $variant ) {
			$summary = YeffoPrint_Field_Schema::format_variant_summary( $variant, $field_schema );
			if ( '' === $summary ) {
				continue;
			}

			$row_label = $multiple
				? sprintf(
					/* translators: 1: label number within the batch, 2: that label's own quantity */
					__( 'Label %1$d (qty %2$d)', 'yeffoprint-core' ),
					$index + 1,
					(int) ( $variant['quantity'] ?? 0 )
				)
				: __( 'Customization', 'yeffoprint-core' );

			$item->add_meta_data( $row_label, $summary, true );
		}
	}

	private function snapshot_custom_order_fee( \WC_Order_Item_Product $item, int $custom_order_id ): void {
		$item->add_meta_data( '_yp_custom_order_id', $custom_order_id, true );
		$item->add_meta_data( __( 'Custom Design Request', 'yeffoprint-core' ), $custom_order_id ? get_the_title( $custom_order_id ) : '—', true );
	}

	private function record_snapshot( int $post_id ): array {
		if ( ! $post_id ) {
			return [];
		}

		return [
			'id'               => $post_id,
			'name'             => get_the_title( $post_id ),
			'price_adjustment' => (float) get_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, true ),
		];
	}

	/**
	 * Admin-only: appends a "Download PNG / PDF" row per qr_code field
	 * that has a value on this order item, so staff producing the order
	 * can pull a print-ready file straight from the order screen
	 * (direct request: "so I don't need to use a 3rd party site")
	 * instead of retyping the URL into some other QR generator.
	 *
	 * Reuses the same public /qr REST endpoint the configurator's live
	 * preview already calls (class-qr-controller.php) — QR rendering is
	 * a pure function of the text, so there's no order-specific
	 * generation logic to duplicate here, just a link built from data
	 * already on the item. Reads field types from the order's own
	 * frozen `_yp_template_snapshot.field_schema`, not the Template's
	 * current (possibly since-edited) schema — same "what was actually
	 * purchased" snapshot principle as pricing/size/material above.
	 */
	public function add_qr_download_links( array $formatted_meta, \WC_Order_Item $item ): array {
		if ( ! $this->is_order_edit_screen() ) {
			return $formatted_meta;
		}

		$variants_json = $item->get_meta( '_yp_variants' );
		$snapshot_json = $item->get_meta( '_yp_template_snapshot' );
		if ( ! $variants_json || ! $snapshot_json ) {
			return $formatted_meta;
		}

		$variants = json_decode( $variants_json, true );
		$snapshot = json_decode( $snapshot_json, true );
		$field_schema = is_array( $snapshot['field_schema'] ?? null ) ? $snapshot['field_schema'] : [];
		$qr_fields    = array_filter( $field_schema, static function ( $field ) {
			return 'qr_code' === ( $field['type'] ?? '' );
		} );

		if ( ! $qr_fields || ! is_array( $variants ) ) {
			return $formatted_meta;
		}

		$multiple = count( $variants ) > 1;
		$suffix   = 0;

		foreach ( $variants as $index => $variant ) {
			foreach ( $qr_fields as $field ) {
				$url = trim( (string) ( $variant['values'][ $field['id'] ] ?? '' ) );
				if ( '' === $url ) {
					continue;
				}

				$png_url = add_query_arg( [ 'text' => rawurlencode( $url ), 'format' => 'png', 'download' => 1 ], rest_url( 'yeffoprint-core/v1/qr' ) );
				$pdf_url = add_query_arg( [ 'text' => rawurlencode( $url ), 'format' => 'pdf', 'download' => 1 ], rest_url( 'yeffoprint-core/v1/qr' ) );

				$label = $multiple
					? sprintf(
						/* translators: 1: field label, 2: label number within the batch */
						__( '%1$s (Label %2$d)', 'yeffoprint-core' ),
						$field['label'],
						$index + 1
					)
					: $field['label'];

				$suffix++;
				$entry               = new \stdClass();
				$entry->key          = '_yp_qr_download_' . $suffix;
				$entry->value        = $url;
				$entry->display_key  = $label;
				$entry->display_value = sprintf(
					'%s &mdash; <a href="%s">%s</a> / <a href="%s">%s</a>',
					esc_html( $url ),
					esc_url( $png_url ),
					esc_html__( 'Download PNG', 'yeffoprint-core' ),
					esc_url( $pdf_url ),
					esc_html__( 'Download PDF', 'yeffoprint-core' )
				);

				$formatted_meta[ 'yp_qr_' . $suffix ] = $entry;
			}
		}

		return $formatted_meta;
	}

	/**
	 * `is_admin()` alone isn't "staff looking at the order screen" — it's
	 * also true for admin-post.php/admin-ajax.php requests, which is
	 * exactly how a manual order-status change (the "Update" button on
	 * this same Edit Order screen) or the "Resend order emails" bulk
	 * action actually fire WooCommerce's transactional emails. Gating on
	 * plain `is_admin()` meant those staff-only download links — meant
	 * only for the order screen's own meta box — were leaking straight
	 * into the customer's real order email whenever the email happened
	 * to be triggered from inside wp-admin, while staying correctly
	 * hidden for the same status change made from checkout or the
	 * Venmo/Zelle payment webhook. Scoping to the actual order-edit
	 * screen id (classic `shop_order`, HPOS `woocommerce_page_wc-orders`)
	 * closes that gap: the orders *list* screen (where the resend bulk
	 * action runs) has a different screen id, so it no longer matches.
	 */
	private function is_order_edit_screen(): bool {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen && in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true );
	}

	public function hide_internal_keys( array $hidden ): array {
		return array_merge( $hidden, [
			'_yp_template_snapshot',
			'_yp_size_snapshot',
			'_yp_material_snapshot',
			'_yp_variants',
			'_yp_batch_quantity',
			'_yp_pricing_snapshot',
			'_yp_custom_order_id',
		] );
	}
}
