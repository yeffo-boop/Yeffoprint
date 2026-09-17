<?php
/**
 * Waives shipping on an add-on order's cart, and links the two orders
 * together the moment the add-on order is actually placed. See
 * class-order-addon.php's own docblock for the feature this belongs to.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Addon_Checkout {

	public function __construct() {
		// Priority 15 — before class-card-surcharge.php's own surcharge
		// (priority 20, itself already deliberately placed after
		// YeffoPrint_Rewards' redemption fee): a customer shouldn't be
		// card-surcharged on a shipping charge that's about to be waived
		// right below it in the same fee-calculation pass.
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'waive_shipping' ], 15 );

		add_action( 'woocommerce_checkout_order_processed', [ $this, 'attach_to_root' ], 10, 3 );
	}

	public function waive_shipping( \WC_Cart $cart ): void {
		$root_id = YeffoPrint_Order_Addon::pending_root_id();
		if ( ! $root_id || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$root = wc_get_order( $root_id );
		if ( ! $root instanceof \WC_Order || ! YeffoPrint_Order_Addon::eligibility( $root )['eligible'] ) {
			return;
		}

		$shipping_total = (float) $cart->get_shipping_total();
		if ( $shipping_total <= 0 ) {
			return;
		}

		$cart->add_fee(
			sprintf(
				/* translators: %s: the order this cart's own order will ship together with */
				__( 'Combined with Order %s — shipping waived', 'yeffoprint-core' ),
				$root->get_order_number()
			),
			-$shipping_total,
			false
		);
	}

	/**
	 * @param int       $order_id
	 * @param array     $posted_data
	 * @param \WC_Order $order
	 */
	public function attach_to_root( $order_id, $posted_data, $order ): void {
		$root_id = YeffoPrint_Order_Addon::pending_root_id();

		// One-shot regardless of outcome below — never carries into a
		// later, unrelated order this same browser session happens to
		// place afterward.
		YeffoPrint_Order_Addon::clear_session();

		if ( ! $root_id || $root_id === (int) $order_id || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$root = wc_get_order( $root_id );
		if ( ! $root instanceof \WC_Order || ! YeffoPrint_Order_Addon::eligibility( $root )['eligible'] ) {
			return; // Became ineligible between adding to cart and placing the order — this just completes as a normal, separately-shipped order.
		}

		$order->update_meta_data( YeffoPrint_Order_Addon::SHIP_WITH_ORDER_ID_META, $root_id );
		$order->save();

		$order->add_order_note( sprintf(
			/* translators: %s: the original order this one ships together with */
			__( 'Placed as an add-on to Order %s — ships together, no separate shipping charge.', 'yeffoprint-core' ),
			$root->get_order_number()
		) );

		$root->add_order_note( sprintf(
			/* translators: %s: the new add-on order that ships together with this one */
			__( 'Order %s was added on to this order — pack and ship them together.', 'yeffoprint-core' ),
			$order->get_order_number()
		) );
	}
}
