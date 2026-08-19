<?php
/**
 * Reorder's write side (PROJECT_SPEC §16: "restore batch into
 * configurator, then edit before purchase") — the read side is
 * class-order-item-controller.php.
 *
 * WooCommerce's native "Order Again" button re-adds every line item
 * via a plain add_to_cart() call, which our linked/fee products reject
 * outright (class-cart-pricing.php's require_batch_data() — they only
 * accept adds carrying batch/custom-order data). Since every order in
 * this store contains at least one such item, the native button would
 * always silently fail; it's hidden per PROJECT_SPEC §18's
 * "non-silent error handling" in favor of a per-item link that routes
 * through the configurator instead.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Reorder {

	public function __construct() {
		add_action( 'woocommerce_order_item_meta_end', [ $this, 'render_reorder_link' ], 10, 3 );
		add_filter( 'woocommerce_my_account_my_orders_actions', [ $this, 'hide_native_order_again' ], 10, 2 );
	}

	public function render_reorder_link( int $item_id, \WC_Order_Item $item, \WC_Order $order ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$snapshot    = json_decode( (string) $item->get_meta( '_yp_template_snapshot' ), true );
		$template_id = (int) ( $snapshot['id'] ?? 0 );

		if ( ! $template_id ) {
			return;
		}

		$url = add_query_arg( 'reorder', $order->get_id() . ':' . $item_id, get_permalink( $template_id ) );

		printf(
			'<p class="yp-reorder-link"><a href="%s">%s</a></p>',
			esc_url( $url ),
			esc_html__( 'Reorder this design', 'yeffoprint-core' )
		);
	}

	public function hide_native_order_again( array $actions, \WC_Order $order ): array {
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta( '_yp_template_snapshot' ) || $item->get_meta( '_yp_custom_order_id' ) ) {
				unset( $actions['order-again'] );
				break;
			}
		}

		return $actions;
	}
}
