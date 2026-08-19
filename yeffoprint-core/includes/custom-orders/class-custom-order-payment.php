<?php
/**
 * Links a CustomOrder to its WooCommerce order once the $25 design
 * fee is actually paid, and starts the production-status workflow.
 *
 * "WooCommerce order status drives payment/fulfillment; CustomOrder.
 * status drives the production workflow" (Architecture §6) — this is
 * the seam between the two: `woocommerce_payment_complete` is the
 * payment-confirmed signal (fires across gateways, including
 * WooPayments), at which point the CustomOrder goes from draft
 * (submitted, unpaid) to published and enters "Design in progress",
 * the first of PROJECT_SPEC §13's six states. Customer name/email are
 * picked up from the order's billing details here too — see
 * class-custom-order-controller.php for why they're not collected on
 * the form itself.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Custom_Order_Payment {

	public function __construct() {
		add_action( 'woocommerce_payment_complete', [ $this, 'link_paid_custom_orders' ] );
	}

	public function link_paid_custom_orders( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$custom_order_id = (int) $item->get_meta( '_yp_custom_order_id' );
			if ( ! $custom_order_id ) {
				continue;
			}

			$custom_order = get_post( $custom_order_id );
			if ( ! $custom_order || 'yp_custom_order' !== $custom_order->post_type || 'draft' !== $custom_order->post_status ) {
				continue; // Already linked (e.g. a payment-complete re-fire), or not ours.
			}

			wp_update_post( [
				'ID'          => $custom_order_id,
				'post_status' => 'publish',
			] );

			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, 'design_in_progress' );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::WC_ORDER_ID, $order_id );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::DESIGN_FEE, (float) $item->get_total() );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_EMAIL, $order->get_billing_email() );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_NAME, trim( $order->get_formatted_billing_full_name() ) );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_ID, $order->get_customer_id() );
		}
	}
}
