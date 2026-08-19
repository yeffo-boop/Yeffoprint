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
