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

	/**
	 * `$item_id` deliberately isn't type-hinted `int` — real bug, found
	 * via a live "Argument #1 ($item_id) must be of type int, string
	 * given" fatal: WooCommerce's own `emails/email-order-items.php`
	 * (used by both real order emails and the Settings → Emails "Send a
	 * test email" preview) fires `woocommerce_order_item_meta_end` with
	 * the item id as a string there, not an int. An uncaught TypeError
	 * here aborts the whole email render — for the preview, WooCommerce
	 * catches that and reports it as "couldn't send the test email"; for
	 * a real order, it would just as easily prevent the actual
	 * transactional email from being sent at all. `$item_id` is only
	 * ever used in string concatenation below, so there was never a
	 * reason to require it be an int in the first place.
	 */
	public function render_reorder_link( $item_id, \WC_Order_Item $item, \WC_Order $order ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$snapshot    = json_decode( (string) $item->get_meta( '_yp_template_snapshot' ), true );
		$template_id = (int) ( $snapshot['id'] ?? 0 );

		if ( $template_id ) {
			$url = add_query_arg( 'reorder', $order->get_id() . ':' . $item_id, get_permalink( $template_id ) );

			printf(
				'<p class="yp-reorder-link"><a href="%s">%s</a></p>',
				esc_url( $url ),
				esc_html__( 'Reorder this design', 'yeffoprint-core' )
			);
			return;
		}

		// Custom Design line items reorder differently: there's no
		// configurator to restore into (Architecture §2 — a CustomOrder
		// is a one-off request, not a premade Template), so this pre-
		// fills a fresh Custom Design form from the past request's own
		// details instead (class-custom-order-controller.php's
		// GET /custom-orders/{id}, ownership-checked there).
		$custom_order_id = (int) $item->get_meta( '_yp_custom_order_id' );

		if ( $custom_order_id && $this->should_render_link_for_item( $item, $custom_order_id, $order ) ) {
			$url = add_query_arg( 'reorder', $custom_order_id, home_url( '/custom-design/' ) );

			printf(
				'<p class="yp-reorder-link"><a href="%s">%s</a></p>',
				esc_url( $url ),
				esc_html__( 'Reorder this custom design', 'yeffoprint-core' )
			);
		}
	}

	/**
	 * A Custom Order's fee and labels line item(s) all carry
	 * _yp_custom_order_id (class-order-item-meta.php), and — since
	 * batching (direct request) — there can now be several labels items
	 * for one custom order, one per batch row. The link still needs to
	 * print exactly once per custom order, not once per item:
	 *
	 * - If a fee item exists for this custom order, that's the one item
	 *   that prints it (unchanged from before batching existed).
	 * - A customer-provided-design or fee-free-reorder order never has a
	 *   fee item at all (YeffoPrint_Custom_Order_Meta::is_fee_skipped()) —
	 *   for those, the labels item with the lowest _yp_batch_row_index
	 *   (i.e. row 0) prints it instead, so the link doesn't just silently
	 *   disappear for exactly the orders customers are most likely to
	 *   want to reorder again.
	 */
	private function should_render_link_for_item( \WC_Order_Item_Product $item, int $custom_order_id, \WC_Order $order ): bool {
		$fee_item_id       = null;
		$lowest_row_index  = null;
		$lowest_row_item_id = null;

		foreach ( $order->get_items() as $sibling ) {
			if ( (int) $sibling->get_meta( '_yp_custom_order_id' ) !== $custom_order_id ) {
				continue;
			}

			if ( ! $sibling->get_meta( '_yp_batch_quantity' ) ) {
				$fee_item_id = $sibling->get_id();
				continue;
			}

			$row_index = (int) $sibling->get_meta( '_yp_batch_row_index' );
			if ( null === $lowest_row_index || $row_index < $lowest_row_index ) {
				$lowest_row_index   = $row_index;
				$lowest_row_item_id = $sibling->get_id();
			}
		}

		$target_item_id = $fee_item_id ?? $lowest_row_item_id;

		return null !== $target_item_id && $target_item_id === $item->get_id();
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
