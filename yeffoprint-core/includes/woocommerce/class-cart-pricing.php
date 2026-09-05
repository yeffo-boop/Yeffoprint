<?php
/**
 * Cart-side WooCommerce integration: live pricing, item display, and a
 * defensive validation net.
 *
 * PROJECT_SPEC §12: "Server always recalculates/validates authoritative
 * price." Every cart calculation re-derives the price from
 * YeffoPrint_Pricing_Rule with the item's *current* size/material
 * adjustments and the batch's combined quantity — never a price cached
 * at add-to-cart time — so it stays correct even if pricing changes
 * while something sits in a cart.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Cart_Pricing {

	private static bool $bypass_validation = false;

	/**
	 * Set immediately before, and cleared immediately after, the one
	 * internal WC()->cart->add_to_cart() call our own REST controller
	 * makes (class-cart-controller.php) — that's the only legitimate
	 * caller for a linked product, so it's the only one allowed past
	 * require_batch_data() below.
	 */
	public static function allow_next_add( bool $allow = true ): void {
		self::$bypass_validation = $allow;
	}

	public function __construct() {
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_price' ], 20, 1 );
		add_filter( 'woocommerce_get_item_data', [ $this, 'display_item_data' ], 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'require_batch_data' ], 10, 3 );
	}

	public function apply_price( \WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// Computed once per recalculation, not per item — every label
		// line item shares the same combined total (see
		// combined_label_quantity() below), so there's no reason to
		// re-sum the whole cart on every iteration of the loop. Stickers
		// get their own separate pool (docs/ARCHITECTURE.md: "a sticker
		// order never counts toward the label discount threshold or vice
		// versa").
		$label_tier_quantity   = self::combined_label_quantity( $cart );
		$sticker_tier_quantity = self::combined_sticker_quantity( $cart );

		foreach ( $cart->get_cart() as $cart_item ) {
			// Checked first: a Custom Stickers line item also carries
			// CUSTOM_ORDER_ID and TOTAL_QTY, same as a Custom Design
			// labels item, but needs YeffoPrint_Sticker_Pricing's own
			// formula, not the label one below — STICKER_TYPE only ever
			// gets set on a sticker item (class-custom-sticker-
			// controller.php), so its presence is what tells the two
			// apart.
			if ( ! empty( $cart_item[ YeffoPrint_Cart_Item_Keys::STICKER_TYPE ] ) ) {
				$breakdown = self::calculate_sticker_for_cart_item( $cart_item, $sticker_tier_quantity );
				if ( null !== $breakdown ) {
					$cart_item['data']->set_price( $breakdown['unit_price_after_discount'] );
				}
				continue;
			}

			if ( ! empty( $cart_item[ YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID ] ) && empty( $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ) ) {
				// The flat $25 design fee line item — no batch/quantity
				// data, always priced as the fee. A Custom Order's *labels*
				// line item also carries CUSTOM_ORDER_ID (to link it back
				// to the same record) but has TOTAL_QTY too, so it falls
				// through to the normal per-unit calculation below instead.
				$cart_item['data']->set_price( YeffoPrint_Pricing_Rule::get_custom_design_fee() );
				continue;
			}

			// Both a normal Template batch and a Custom Order's own labels
			// line item reach here and price identically — the formula
			// only needs size/material/quantity, never a template_id.
			$breakdown = self::calculate_for_cart_item( $cart_item, $label_tier_quantity );
			if ( null !== $breakdown ) {
				$cart_item['data']->set_price( $breakdown['unit_price_after_discount'] );
			}
		}
	}

	/**
	 * The bulk-discount threshold a customer's whole order is measured
	 * against — direct request: "they can mix and match to meet that
	 * minimum to get a discount," so every label-bearing line item
	 * (a Template batch or a Custom Order's own labels item — both
	 * share TOTAL_QTY, regardless of which design/size/material each
	 * one is) counts toward one shared total, not just whichever single
	 * line happens to be large enough alone. The flat design-fee line
	 * item has no TOTAL_QTY and is correctly excluded by the same check
	 * calculate_for_cart_item() already uses.
	 *
	 * Takes an explicit `$cart` when the live `woocommerce_before_
	 * calculate_totals` hook already has one in hand (apply_price());
	 * falls back to `WC()->cart` for every other caller (the order-item
	 * snapshot at checkout, the pricing-preview REST endpoint before
	 * anything's even been added yet) — both are the *same* cart object
	 * within one request, this just avoids requiring every caller to
	 * thread it through by hand.
	 */
	public static function combined_label_quantity( ?\WC_Cart $cart = null, ?string $exclude_cart_item_key = null ): int {
		$cart = $cart ?? ( function_exists( 'WC' ) ? WC()->cart : null );
		if ( ! $cart ) {
			return 0;
		}

		$total = 0;
		foreach ( $cart->get_cart() as $key => $cart_item ) {
			if ( $exclude_cart_item_key && $key === $exclude_cart_item_key ) {
				continue; // The item currently being edited — its own (possibly stale) quantity would double-count against the new one a caller is about to preview.
			}

			$total += (int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ?? 0 );
		}

		return $total;
	}

	/** Sticker-only counterpart to combined_label_quantity() above — same reasoning, kept as a separate pool per docs/ARCHITECTURE.md. */
	public static function combined_sticker_quantity( ?\WC_Cart $cart = null, ?string $exclude_cart_item_key = null ): int {
		$cart = $cart ?? ( function_exists( 'WC' ) ? WC()->cart : null );
		if ( ! $cart ) {
			return 0;
		}

		$total = 0;
		foreach ( $cart->get_cart() as $key => $cart_item ) {
			if ( $exclude_cart_item_key && $key === $exclude_cart_item_key ) {
				continue;
			}

			if ( empty( $cart_item[ YeffoPrint_Cart_Item_Keys::STICKER_TYPE ] ) ) {
				continue;
			}

			$total += (int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ?? 0 );
		}

		return $total;
	}

	/**
	 * Static and stateless on purpose: callers like the order-item
	 * snapshot (class-order-item-meta.php) need this exact calculation
	 * without instantiating a second YeffoPrint_Cart_Pricing, which
	 * would re-register this class's hooks a second time (duplicating,
	 * e.g., the cart's displayed Size/Material rows).
	 *
	 * @param int|null $tier_quantity Combined cart-wide quantity to resolve
	 *   the bulk discount against; defaults to a fresh combined_label_quantity()
	 *   read (correct for a one-off caller like the checkout snapshot — see
	 *   combined_label_quantity()'s own doc for why that's safe there).
	 * @return array|null The pricing breakdown, or null if this isn't a YeffoPrint batch item.
	 */
	public static function calculate_for_cart_item( array $cart_item, ?int $tier_quantity = null ): ?array {
		if ( empty( $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ) ) {
			return null;
		}

		$material_adjustment = self::adjustment( 'yp_material', (int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::MATERIAL_ID ] ?? 0 ) );
		$quantity             = (int) $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ];

		// Label Designer item: customer-entered dimensions instead of a
		// preset Size post — no size_adjustment to look up (the size cost
		// is already fully embodied in the dynamic base price below), and
		// the base itself comes from dynamic_base_price() instead of the
		// site-wide constant. Everything else (material adjustment,
		// discount tiers, quantity) is the exact same shared logic every
		// other label-bearing line item already goes through.
		$width_mm  = (float) ( $cart_item[ YeffoPrint_Cart_Item_Keys::CANVAS_WIDTH_MM ] ?? 0 );
		$height_mm = (float) ( $cart_item[ YeffoPrint_Cart_Item_Keys::CANVAS_HEIGHT_MM ] ?? 0 );

		if ( $width_mm > 0 && $height_mm > 0 ) {
			$base_price_override = YeffoPrint_Pricing_Rule::dynamic_base_price( $width_mm, $height_mm );
			return YeffoPrint_Pricing_Rule::calculate( $material_adjustment, 0.0, $quantity, $tier_quantity ?? self::combined_label_quantity(), $base_price_override );
		}

		$size_adjustment = self::adjustment( 'yp_size', (int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::SIZE_ID ] ?? 0 ) );

		return YeffoPrint_Pricing_Rule::calculate( $material_adjustment, $size_adjustment, $quantity, $tier_quantity ?? self::combined_label_quantity() );
	}

	/**
	 * Sticker counterpart to calculate_for_cart_item() above — same
	 * "static, stateless, reusable from the order-item snapshot" reason.
	 * Returns null rather than propagating YeffoPrint_Sticker_Pricing's
	 * WP_Error on an invalid size/dimensions: apply_price() runs on
	 * every cart recalculation, including ones a customer can't see
	 * (a stale item left mid-edit), so silently leaving WC's existing
	 * price in place here is safer than surfacing an error from a hook
	 * that has no request to attach one to — the REST submission
	 * endpoint (class-custom-sticker-controller.php) is what actually
	 * validates these same inputs and reports a real error to the
	 * customer, before anything ever reaches the cart.
	 */
	public static function calculate_sticker_for_cart_item( array $cart_item, ?int $tier_quantity = null ): ?array {
		if ( empty( $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ) ) {
			return null;
		}

		$breakdown = YeffoPrint_Sticker_Pricing::calculate(
			(int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::SIZE_ID ] ?? 0 ),
			(float) ( $cart_item[ YeffoPrint_Cart_Item_Keys::CUSTOM_WIDTH_IN ] ?? 0 ),
			(float) ( $cart_item[ YeffoPrint_Cart_Item_Keys::CUSTOM_HEIGHT_IN ] ?? 0 ),
			(int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::MATERIAL_ID ] ?? 0 ),
			(string) ( $cart_item[ YeffoPrint_Cart_Item_Keys::STICKER_TYPE ] ?? '' ),
			(string) ( $cart_item[ YeffoPrint_Cart_Item_Keys::SHAPE ] ?? '' ),
			(int) $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ],
			$tier_quantity ?? self::combined_sticker_quantity()
		);

		return is_wp_error( $breakdown ) ? null : $breakdown;
	}

	private static function adjustment( string $post_type, int $post_id ): float {
		if ( ! $post_id ) {
			return 0.0;
		}

		$post = get_post( $post_id );
		if ( ! $post || $post_type !== $post->post_type ) {
			return 0.0;
		}

		return (float) get_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, true );
	}

	/**
	 * The extra rows WooCommerce shows under a cart/checkout/order line
	 * item (classic cart & checkout templates; PROJECT_SPEC §14 "key
	 * customization details"). See docs/ARCHITECTURE.md §9 for the
	 * Blocks-based Cart/Checkout caveat — this filter doesn't reach
	 * those without an additional Store API schema extension.
	 */
	public function display_item_data( array $item_data, array $cart_item ): array {
		if ( ! empty( $cart_item[ YeffoPrint_Cart_Item_Keys::STICKER_TYPE ] ) ) {
			return $this->sticker_item_data( $item_data, $cart_item );
		}

		$custom_order_id = (int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID ] ?? 0 );
		$is_labels_item  = $custom_order_id && ! empty( $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] );

		if ( $custom_order_id && ! $is_labels_item ) {
			// The flat $25 design fee line item.
			$custom_order = get_post( $custom_order_id );
			if ( $custom_order ) {
				$brand = get_post_meta( $custom_order->ID, YeffoPrint_Custom_Order_Meta::BRAND_NAME, true );
				$item_data[] = [ 'key' => __( 'Brand', 'yeffoprint-core' ), 'value' => $brand ?: '—' ];
			}
			return $item_data;
		}

		if ( empty( $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ) ) {
			return $item_data;
		}

		$width_mm  = (float) ( $cart_item[ YeffoPrint_Cart_Item_Keys::CANVAS_WIDTH_MM ] ?? 0 );
		$height_mm = (float) ( $cart_item[ YeffoPrint_Cart_Item_Keys::CANVAS_HEIGHT_MM ] ?? 0 );

		if ( $width_mm > 0 && $height_mm > 0 ) {
			// Label Designer item — no preset Size post to read a title
			// from, so show the customer's own entered dimensions instead.
			$item_data[] = [
				'key'   => __( 'Size', 'yeffoprint-core' ),
				/* translators: 1: width in millimeters, 2: height in millimeters */
				'value' => sprintf( __( '%1$smm × %2$smm', 'yeffoprint-core' ), rtrim( rtrim( number_format( $width_mm, 1 ), '0' ), '.' ), rtrim( rtrim( number_format( $height_mm, 1 ), '0' ), '.' ) ),
			];
		} else {
			$size = get_post( $cart_item[ YeffoPrint_Cart_Item_Keys::SIZE_ID ] ?? 0 );
			if ( $size ) {
				$item_data[] = [ 'key' => __( 'Size', 'yeffoprint-core' ), 'value' => $size->post_title ];
			}
		}

		$material = get_post( $cart_item[ YeffoPrint_Cart_Item_Keys::MATERIAL_ID ] ?? 0 );
		if ( $material ) {
			$item_data[] = [ 'key' => __( 'Material', 'yeffoprint-core' ), 'value' => $material->post_title ];
		}

		if ( $is_labels_item ) {
			// A Custom Order's own labels: a single print run, not a
			// batch of per-variant customizations — there's no
			// field_schema/variants to render here (Architecture §2).
			$item_data[] = [
				'key'   => __( 'Quantity', 'yeffoprint-core' ),
				'value' => number_format_i18n( (int) $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ),
			];
			return $item_data;
		}

		$variants = $cart_item[ YeffoPrint_Cart_Item_Keys::VARIANTS ] ?? [];
		$item_data[] = [
			'key'   => __( 'Batch', 'yeffoprint-core' ),
			'value' => sprintf(
				/* translators: 1: number of variants, 2: total quantity */
				_n( '%1$d label variant, %2$d total', '%1$d label variants, %2$d total', count( $variants ), 'yeffoprint-core' ),
				count( $variants ),
				(int) $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ]
			),
		];

		// The actual customization (compound, strength, brand name —
		// whatever the Template's field_schema defines) so the customer
		// can verify it on the cart/checkout review before paying, not
		// just after — matches the rows added to the order line item
		// once it's placed (class-order-item-meta.php).
		$template_id  = (int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::TEMPLATE_ID ] ?? 0 );
		$field_schema = $template_id ? YeffoPrint_Field_Schema::get( $template_id ) : [];
		$multiple     = count( $variants ) > 1;

		foreach ( $variants as $index => $variant ) {
			$summary = YeffoPrint_Field_Schema::format_variant_summary( $variant, $field_schema );
			if ( '' === $summary ) {
				continue;
			}

			$item_data[] = [
				'key'   => $multiple
					? sprintf( /* translators: %d: label number within the batch */ __( 'Label %d', 'yeffoprint-core' ), $index + 1 )
					: __( 'Customization', 'yeffoprint-core' ),
				'value' => $summary,
			];
		}

		return $item_data;
	}

	/** Cart/checkout display rows for a Custom Stickers line item — same role as the labels-item branch above, just this flow's own fields. */
	private function sticker_item_data( array $item_data, array $cart_item ): array {
		$size_id        = (int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::SIZE_ID ] ?? 0 );
		$is_custom_size = $size_id && (bool) get_post_meta( $size_id, YeffoPrint_Sticker_Size_Meta::IS_CUSTOM, true );

		$item_data[] = [
			'key'   => __( 'Type', 'yeffoprint-core' ),
			'value' => YeffoPrint_Sticker_Pricing::TYPES[ $cart_item[ YeffoPrint_Cart_Item_Keys::STICKER_TYPE ] ?? '' ] ?? '—',
		];

		$item_data[] = [
			'key'   => __( 'Shape', 'yeffoprint-core' ),
			'value' => YeffoPrint_Sticker_Pricing::SHAPES[ $cart_item[ YeffoPrint_Cart_Item_Keys::SHAPE ] ?? '' ] ?? '—',
		];

		if ( $is_custom_size ) {
			$item_data[] = [
				'key'   => __( 'Size', 'yeffoprint-core' ),
				'value' => sprintf(
					/* translators: 1: width in inches, 2: height in inches */
					__( 'Custom: %1$s" × %2$s"', 'yeffoprint-core' ),
					(string) ( $cart_item[ YeffoPrint_Cart_Item_Keys::CUSTOM_WIDTH_IN ] ?? '' ),
					(string) ( $cart_item[ YeffoPrint_Cart_Item_Keys::CUSTOM_HEIGHT_IN ] ?? '' )
				),
			];
		} else {
			$size = get_post( $size_id );
			if ( $size ) {
				$item_data[] = [ 'key' => __( 'Size', 'yeffoprint-core' ), 'value' => $size->post_title ];
			}
		}

		$material = get_post( $cart_item[ YeffoPrint_Cart_Item_Keys::MATERIAL_ID ] ?? 0 );
		if ( $material ) {
			$item_data[] = [ 'key' => __( 'Material', 'yeffoprint-core' ), 'value' => $material->post_title ];
		}

		$item_data[] = [
			'key'   => __( 'Quantity', 'yeffoprint-core' ),
			'value' => number_format_i18n( (int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ?? 0 ) ),
		];

		return $item_data;
	}

	/**
	 * Defensive net: our own REST controllers (class-cart-controller.php,
	 * class-custom-order-controller.php) are the only intended entry
	 * points for adding a linked Template product or the custom design
	 * fee product to the cart. This rejects anyone who reaches
	 * WooCommerce's native add-to-cart directly for one of these hidden
	 * products without the required batch/custom-order data —
	 * including, for the fee product, a bare add that would otherwise
	 * check out at whatever price is on the product record itself
	 * rather than the live PricingRule fee.
	 */
	public function require_batch_data( bool $passed, int $product_id, int $quantity ): bool {
		$is_linked_product = YeffoPrint_Linked_Product::get_template_id( $product_id )
			|| $product_id === YeffoPrint_Custom_Design_Fee_Product::get_existing_product_id()
			|| $product_id === YeffoPrint_Custom_Order_Labels_Product::get_existing_product_id()
			|| $product_id === YeffoPrint_Custom_Sticker_Product::get_existing_product_id();

		if ( ! $is_linked_product || self::$bypass_validation ) {
			return $passed;
		}

		wc_add_notice( __( 'This item can only be added from its design page.', 'yeffoprint-core' ), 'error' );
		return false;
	}
}
