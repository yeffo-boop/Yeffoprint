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

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item[ YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID ] ) ) {
				$cart_item['data']->set_price( YeffoPrint_Pricing_Rule::get_custom_design_fee() );
				continue;
			}

			$breakdown = self::calculate_for_cart_item( $cart_item );
			if ( null !== $breakdown ) {
				$cart_item['data']->set_price( $breakdown['unit_price_after_discount'] );
			}
		}
	}

	/**
	 * Static and stateless on purpose: callers like the order-item
	 * snapshot (class-order-item-meta.php) need this exact calculation
	 * without instantiating a second YeffoPrint_Cart_Pricing, which
	 * would re-register this class's hooks a second time (duplicating,
	 * e.g., the cart's displayed Size/Material rows).
	 *
	 * @return array|null The pricing breakdown, or null if this isn't a YeffoPrint batch item.
	 */
	public static function calculate_for_cart_item( array $cart_item ): ?array {
		if ( empty( $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ) ) {
			return null;
		}

		$material_adjustment = self::adjustment( 'yp_material', (int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::MATERIAL_ID ] ?? 0 ) );
		$size_adjustment     = self::adjustment( 'yp_size', (int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::SIZE_ID ] ?? 0 ) );
		$quantity             = (int) $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ];

		return YeffoPrint_Pricing_Rule::calculate( $material_adjustment, $size_adjustment, $quantity );
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
		if ( ! empty( $cart_item[ YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID ] ) ) {
			$custom_order = get_post( $cart_item[ YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID ] );
			if ( $custom_order ) {
				$brand = get_post_meta( $custom_order->ID, YeffoPrint_Custom_Order_Meta::BRAND_NAME, true );
				$item_data[] = [ 'key' => __( 'Brand', 'yeffoprint-core' ), 'value' => $brand ?: '—' ];
			}
			return $item_data;
		}

		if ( empty( $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ) ) {
			return $item_data;
		}

		$size = get_post( $cart_item[ YeffoPrint_Cart_Item_Keys::SIZE_ID ] ?? 0 );
		if ( $size ) {
			$item_data[] = [ 'key' => __( 'Size', 'yeffoprint-core' ), 'value' => $size->post_title ];
		}

		$material = get_post( $cart_item[ YeffoPrint_Cart_Item_Keys::MATERIAL_ID ] ?? 0 );
		if ( $material ) {
			$item_data[] = [ 'key' => __( 'Material', 'yeffoprint-core' ), 'value' => $material->post_title ];
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
			|| $product_id === YeffoPrint_Custom_Design_Fee_Product::get_existing_product_id();

		if ( ! $is_linked_product || self::$bypass_validation ) {
			return $passed;
		}

		wc_add_notice( __( 'This item can only be added from its design page.', 'yeffoprint-core' ), 'error' );
		return false;
	}
}
