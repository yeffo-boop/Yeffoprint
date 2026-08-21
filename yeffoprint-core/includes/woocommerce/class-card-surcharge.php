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

	public function __construct() {
		// Priority 20: after YeffoPrint_Rewards' own redemption fee (default
		// priority 10) has already been added, so a customer who redeems
		// points is surcharged on what they'll actually be charged, not
		// the pre-discount amount.
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_surcharge' ], 20 );
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
