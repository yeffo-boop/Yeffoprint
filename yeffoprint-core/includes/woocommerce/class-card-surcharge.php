<?php
/**
 * Passes payment processing fees on to the customer — direct request,
 * at a different rate per gateway (a card and a "buy now, pay later"
 * gateway like Afterpay typically cost genuinely different percentages
 * to accept). Adds a cart fee (same mechanism YeffoPrint_Rewards
 * already uses for its own, negative, redemption fee) sized to whatever
 * rate an admin set for the customer's currently-selected payment
 * method (Dashboard → YeffoPrint → Settings → Card Surcharge).
 *
 * Deliberately opt-in per gateway (a gateway missing from the stored
 * rates, or with a rate at or below 0, is never surcharged) — this
 * store also has manual gateways (Venmo, Zelle) that must never be
 * surcharged, and there's no reliable way to distinguish "a gateway
 * that costs a processing fee" from "one that doesn't" by inspecting a
 * WC_Payment_Gateway object alone.
 *
 * The one thing this deliberately can't do: exclude debit cards.
 * Surcharging a debit card is a federal Durbin Amendment violation,
 * not just a card-network rule — but which type of card a customer is
 * about to use is only known to the payment processor (Stripe, here)
 * at the moment payment details are entered, inside its own secure
 * card element; WooCommerce/PHP never sees it before that, so nothing
 * at this layer can conditionally skip debit cards. Stripe's own
 * sanctioned answer to that exact problem is a certified surcharge
 * provider app (Yeeld or InterPayments, via Stripe's own App
 * Marketplace) that reads the real card data at authorization time —
 * this class is the "just add a flat fee regardless of card type"
 * alternative the store owner chose instead, with that gap understood.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Card_Surcharge {

	/**
	 * Flags the fee item this class itself added to an order (pay_order_
	 * sync() below) so a later run can find and replace it — a bare name
	 * match would be fragile (an admin could reuse the same label text)
	 * and there's no other way to tell "our fee, stale rate/gateway" from
	 * "an unrelated fee that happens to be on this order" apart.
	 */
	private const ORDER_FEE_META_KEY = '_yp_card_surcharge';

	public function __construct() {
		// Priority 20: after YeffoPrint_Rewards' own redemption fee (default
		// priority 10) has already been added, so a customer who redeems
		// points is surcharged on what they'll actually be charged, not
		// the pre-discount amount.
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_surcharge' ], 20 );

		// The classic "Pay for order" page (checkout/form-pay.php) never
		// touches WC()->cart at all — it works directly off an already-
		// placed WC_Order, so the hook above never fires there and this
		// store's Venmo/Zelle-must-never-be-surcharged, rate-per-gateway
		// fee was silently missing on that page entirely (a declined-
		// payment retry, or an admin-created order's payment link). This
		// fires early — before the page's own item/totals table is built
		// on display, and before WC_Form_Handler::pay_action() calls the
		// chosen gateway's process_payment() on submit — so both what's
		// shown and what's actually charged reflect it.
		add_action( 'woocommerce_before_pay_action', [ $this, 'sync_pay_order_surcharge' ] );
	}

	public function apply_surcharge( \WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$chosen_gateway = WC()->session ? (string) WC()->session->get( 'chosen_payment_method' ) : '';
		if ( '' === $chosen_gateway ) {
			return;
		}

		$rates = self::get_gateway_rates();
		$rate  = (float) ( $rates[ $chosen_gateway ]['rate'] ?? 0 );
		if ( $rate <= 0 ) {
			return;
		}

		// Everything charged to the card so far — line items, shipping,
		// and any fee already applied above this one (e.g. a Rewards
		// discount) — before tax. The fee itself is added non-taxable
		// (several states' own guidance is that a surcharge shouldn't
		// itself be taxed), matching how the Rewards discount fee is
		// also added non-taxable.
		$base = (float) $cart->get_cart_contents_total() + (float) $cart->get_shipping_total() + (float) $cart->get_fee_total();
		if ( $base <= 0 ) {
			return;
		}

		$surcharge = round( $base * ( $rate / 100 ), 2 );
		if ( $surcharge <= 0 ) {
			return;
		}

		$label = (string) ( $rates[ $chosen_gateway ]['label'] ?? '' );
		$cart->add_fee( self::format_label( $label, $rate ), $surcharge, false );
	}

	/**
	 * Order-object counterpart to apply_surcharge() above, for the
	 * classic Pay for Order page — see the constructor comment for why
	 * that page needs its own hook at all.
	 */
	public function sync_pay_order_surcharge( \WC_Order $order ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return; // Defensive only — woocommerce_before_pay_action never fires from wp-admin's own Edit Order screen.
		}

		// Remove whatever surcharge fee this class previously put on this
		// order first — a stale one for a since-changed gateway/rate must
		// never linger, and re-deriving from scratch is simpler than
		// trying to patch one row's amount in place.
		foreach ( $order->get_items( 'fee' ) as $item_id => $item ) {
			if ( '1' === $item->get_meta( self::ORDER_FEE_META_KEY ) ) {
				$order->remove_item( $item_id );
			}
		}

		$chosen = $this->chosen_gateway_for_pay_order( $order );
		$rates  = self::get_gateway_rates();
		$rate   = (float) ( $rates[ $chosen ]['rate'] ?? 0 );

		if ( $rate > 0 ) {
			// Same basis as apply_surcharge() above: everything charged so
			// far — line items (post-discount), shipping, and any other
			// fee already on the order (e.g. a Rewards redemption) — before
			// tax. The fee item this class just removed, if any, is
			// already gone from get_items('fee') by this point.
			$base = 0.0;
			foreach ( $order->get_items() as $item ) {
				$base += (float) $item->get_total();
			}
			$base += (float) $order->get_shipping_total();
			foreach ( $order->get_items( 'fee' ) as $item ) {
				$base += (float) $item->get_total();
			}

			$surcharge = round( $base * ( $rate / 100 ), 2 );

			if ( $surcharge > 0 ) {
				$label = (string) ( $rates[ $chosen ]['label'] ?? '' );

				$fee = new \WC_Order_Item_Fee();
				$fee->set_name( self::format_label( $label, $rate ) );
				$fee->set_amount( $surcharge );
				$fee->set_total( $surcharge );
				$fee->set_tax_status( 'none' ); // Same non-taxable treatment as the cart version above.
				$fee->add_meta_data( self::ORDER_FEE_META_KEY, '1', true );

				$order->add_item( $fee );
			}
		}

		$order->calculate_totals( true );
		$order->save();
	}

	/**
	 * Which gateway to surcharge for right now — the one actually being
	 * submitted (read straight off $_POST, since WC_Form_Handler::
	 * pay_action() hasn't parsed it yet at the point this fires) when
	 * this is a submission, otherwise whichever gateway the page is
	 * about to pre-select as the default radio. Mirrors WC_Payment_
	 * Gateways::set_current_gateway()'s own precedence (a valid session
	 * choice, then the order's existing payment method, then the first
	 * available gateway) since that's what actually ends up checked.
	 */
	private function chosen_gateway_for_pay_order( \WC_Order $order ): string {
		if ( isset( $_POST['payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only signal; the submission itself is nonce-verified by WC_Form_Handler::pay_action() before this fires.
			return sanitize_key( wp_unslash( $_POST['payment_method'] ) );
		}

		$available = WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : [];

		$session_choice = WC()->session ? (string) WC()->session->get( 'chosen_payment_method' ) : '';
		if ( '' !== $session_choice && isset( $available[ $session_choice ] ) ) {
			return $session_choice;
		}

		if ( $order->get_payment_method() && isset( $available[ $order->get_payment_method() ] ) ) {
			return $order->get_payment_method();
		}

		return $available ? (string) array_key_first( $available ) : '';
	}

	/** @return array<string, array{rate:float, label:string}> Keyed by gateway id — empty by default, so nothing is ever surcharged until configured. */
	public static function get_gateway_rates(): array {
		$stored = get_option( YeffoPrint_Admin_Menu::SURCHARGE_GATEWAY_RATES_OPTION, [] );
		return is_array( $stored ) ? $stored : [];
	}

	private static function format_label( string $label, float $rate ): string {
		$label      = '' !== $label ? $label : YeffoPrint_Admin_Menu::SURCHARGE_LABEL_DEFAULT;
		$rate_label = rtrim( rtrim( number_format( $rate, 2 ), '0' ), '.' );

		/* translators: 1: configured label text, 2: surcharge rate as a plain number */
		return sprintf( __( '%1$s (%2$s%%)', 'yeffoprint-core' ), $label, $rate_label );
	}
}
