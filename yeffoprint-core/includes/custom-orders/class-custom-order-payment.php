<?php
/**
 * Links a CustomOrder to its WooCommerce order once the $25 design
 * fee is actually paid, and starts the production-status workflow.
 *
 * "WooCommerce order status drives payment/fulfillment; CustomOrder.
 * status drives the production workflow" (Architecture §6) — this is
 * the seam between the two: once payment is confirmed, the CustomOrder
 * goes from draft (submitted, unpaid) to published and enters "Design
 * in progress", the first of PROJECT_SPEC §13's six states. Customer
 * name/email are picked up from the order's billing details here too —
 * see class-custom-order-controller.php for why they're not collected
 * on the form itself.
 *
 * Hooked to three separate events, not just `woocommerce_payment_complete`
 * — a real bug found via a live test order stuck on "Processing" but
 * never linked: `payment_complete()` is a method gateways are expected
 * to call themselves after actually processing a charge, but some
 * built-in ones don't. WC_Gateway_COD::process_payment(), for one,
 * moves the order straight to "processing"/"on-hold" via update_status()
 * and never calls payment_complete() at all, so a Cash on Delivery test
 * order — the easiest way to test checkout without a real card — would
 * never have fired this class's logic under the single-hook version.
 * `woocommerce_order_status_processing`/`_completed` fire on that exact
 * transition regardless of which gateway (or a manual admin status
 * change) got the order there, closing the gap. All three call the same
 * idempotent handler, so a real payment-gateway order that fires
 * `payment_complete` *and* transitions to "processing" only gets
 * processed once — the `'draft' !== $custom_order->post_status` check
 * already guards every entry point here, not just repeat fires of one.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Custom_Order_Payment {

	public function __construct() {
		add_action( 'woocommerce_payment_complete', [ $this, 'link_paid_custom_orders' ] );
		add_action( 'woocommerce_order_status_processing', [ $this, 'link_paid_custom_orders' ] );
		add_action( 'woocommerce_order_status_completed', [ $this, 'link_paid_custom_orders' ] );
	}

	public function link_paid_custom_orders( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$linked = [];

		foreach ( $order->get_items() as $item ) {
			$custom_order_id = (int) $item->get_meta( '_yp_custom_order_id' );
			if ( ! $custom_order_id || isset( $linked[ $custom_order_id ] ) ) {
				continue;
			}

			$custom_order = get_post( $custom_order_id );
			if ( ! $custom_order || 'yp_custom_order' !== $custom_order->post_type || 'draft' !== $custom_order->post_status ) {
				continue; // Already linked (e.g. a re-fire from a second event above), or not ours.
			}

			$linked[ $custom_order_id ] = true;

			wp_update_post( [
				'ID'          => $custom_order_id,
				'post_status' => 'publish',
			] );

			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, 'design_in_progress' );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::WC_ORDER_ID, $order_id );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::DESIGN_FEE, $this->find_design_fee( $order, $custom_order_id ) );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_EMAIL, $order->get_billing_email() );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_NAME, trim( $order->get_formatted_billing_full_name() ) );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_ID, $order->get_customer_id() );
		}
	}

	/**
	 * A CustomOrder can be linked from two order line items now (the
	 * flat design fee, and — as of the labels-pricing change — the
	 * customer's own print run), both carrying `_yp_custom_order_id` so
	 * either one can trigger linking. `$order->get_items()` isn't
	 * guaranteed to iterate the fee item first, so this looks it up
	 * explicitly by the one trait that tells them apart
	 * (`_yp_batch_quantity`, set only on the labels item — class-order-
	 * item-meta.php) rather than trusting "whichever item triggered
	 * this call" to be the fee.
	 *
	 * Custom Stickers has no equivalent flat fee item at all (direct
	 * pricing decision, docs/ARCHITECTURE.md — preset size tiers only,
	 * no separate proofing charge), so its one linked item *always*
	 * carries `_yp_batch_quantity` and would otherwise fall through to
	 * the label flow's own fee constant below — meaningless for a
	 * sticker order, and not even the right dollar amount. DESIGN_FEE's
	 * stored meaning here is really "what unlocked production," not
	 * literally "the flat design fee" — for a sticker order that's just
	 * the sum of its own linked item(s).
	 */
	private function find_design_fee( \WC_Order $order, int $custom_order_id ): float {
		// A customer-provided-design or fee-free-reorder order never has a
		// fee item at all (direct request) — without this check, falling
		// through to the fallback below would wrongly record the nominal
		// $25 as "what was paid" even though nothing was charged.
		if ( YeffoPrint_Custom_Order_Meta::is_fee_skipped( $custom_order_id ) ) {
			return 0.0;
		}

		if ( 'sticker' === YeffoPrint_Custom_Order_Meta::get_order_type( $custom_order_id ) ) {
			$total = 0.0;
			foreach ( $order->get_items() as $item ) {
				if ( (int) $item->get_meta( '_yp_custom_order_id' ) === $custom_order_id ) {
					$total += (float) $item->get_total();
				}
			}
			return $total;
		}

		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_meta( '_yp_custom_order_id' ) !== $custom_order_id ) {
				continue;
			}

			if ( ! $item->get_meta( '_yp_batch_quantity' ) ) {
				return (float) $item->get_total();
			}
		}

		return YeffoPrint_Pricing_Rule::get_custom_design_fee();
	}
}
