<?php
/**
 * Receives an "I got paid" notification from an outside automation
 * (Gmail + Apps Script, Zapier, Power Automate, …) watching the
 * admin's own inbox for Venmo/Zelle payment emails, and matches it to
 * an on-hold order (direct request: "automatically recognize when I
 * receive a Venmo payment, match the amount, and update the order
 * status").
 *
 * Matching is deliberately conservative — this moves real orders into
 * production and this endpoint has no way to *verify* a payment
 * actually happened, it only trusts whatever the automation reports:
 *   1. If the payment note contains something that looks like an order
 *      number, and that exact order is on-hold with the right gateway
 *      and amount, resolve it — as close to certain as this can get.
 *   2. Otherwise, fall back to amount + gateway alone. Exactly one
 *      on-hold order matching → resolve it. Zero or more than one →
 *      never guess; email the admin instead. Auto-marking the *wrong*
 *      order paid (shipping unpaid product, leaving a real payment
 *      unmatched) is a worse failure than making the admin resolve a
 *      handful of same-amount collisions by hand.
 *   3. Short payments (direct request: "alert a customer that they
 *      accidentally underpaid if a zelle/venmo comes in that's slightly
 *      short"): an order named in the note takes any amount below what's
 *      owed; without one, exactly one open order owing a little more
 *      (within SHORT_TOLERANCE) takes it. Either way the payment is
 *      recorded and the customer is emailed the balance
 *      (class-partial-payments.php). Every amount is compared against
 *      what's still owed, so the follow-up payment matches too.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Payment_Webhook_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	/**
	 * How short an unlabeled payment can be and still be matched to an
	 * order: up to 10% of what's owed, and never more than $20.
	 */
	private const SHORT_TOLERANCE_RATIO = 0.10;
	private const SHORT_TOLERANCE_MAX   = 20.00;

	private const GATEWAY_IDS = [
		'venmo' => 'yeffoprint_venmo',
		'zelle' => 'yeffoprint_zelle',
	];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/payments/notify', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'notify' ],
			'permission_callback' => [ $this, 'check_token' ],
			'args'                => [
				'token'  => [ 'required' => true ],
				'method' => [ 'required' => true ],
				'amount' => [ 'required' => true ],
				'note'   => [ 'required' => false ],
			],
		] );
	}

	/**
	 * @return true|\WP_Error
	 */
	public function check_token( \WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );

		if ( '' === $token || ! hash_equals( YeffoPrint_Payment_Webhook_Secret::get(), $token ) ) {
			return new \WP_Error( 'yeffoprint_invalid_token', __( 'Invalid or missing token.', 'yeffoprint-core' ), [ 'status' => 403 ] );
		}

		return true;
	}

	public function notify( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_Error( 'yeffoprint_wc_unavailable', __( 'WooCommerce is not available.', 'yeffoprint-core' ), [ 'status' => 503 ] );
		}

		$method     = sanitize_key( (string) $request->get_param( 'method' ) );
		$gateway_id = self::GATEWAY_IDS[ $method ] ?? '';

		if ( '' === $gateway_id ) {
			return new \WP_Error( 'yeffoprint_invalid_method', __( '"method" must be "venmo" or "zelle".', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$raw_amount = $request->get_param( 'amount' );
		if ( ! is_numeric( $raw_amount ) || (float) $raw_amount <= 0 ) {
			return new \WP_Error( 'yeffoprint_invalid_amount', __( '"amount" must be a positive number.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		$amount = round( (float) $raw_amount, 2 );

		$note = sanitize_text_field( (string) $request->get_param( 'note' ) );

		$order_from_note = $this->order_from_note( $note, $gateway_id, $amount );
		if ( $order_from_note ) {
			return $this->apply_payment( $order_from_note, $amount, $method, __( 'order number found in payment note', 'yeffoprint-core' ) );
		}

		$candidates = $this->open_orders_by_amount( $gateway_id, $amount );

		if ( 1 === count( $candidates ) ) {
			return $this->apply_payment( $candidates[0], $amount, $method, __( 'exact amount match, only one open order owing that amount', 'yeffoprint-core' ) );
		}

		if ( empty( $candidates ) ) {
			$short = $this->orders_slightly_over( $gateway_id, $amount );
			if ( 1 === count( $short ) ) {
				return $this->apply_payment( $short[0], $amount, $method, __( 'slightly short of the only open order near that amount', 'yeffoprint-core' ) );
			}
		}

		if ( empty( $candidates ) ) {
			$this->notify_admin(
				sprintf( /* translators: 1: Venmo/Zelle, 2: amount */ __( 'Unmatched %1$s payment: %2$s', 'yeffoprint-core' ), ucfirst( $method ), $this->plain_text_amount( $amount ) ),
				sprintf(
					/* translators: 1: Venmo/Zelle, 2: amount, 3: note text */
					__( "A %1\$s payment notification for %2\$s came in, but no unpaid order owing that amount (or slightly more) was found.\n\nPayment note: %3\$s\n\nIf this is a real payment, open the order in the admin app and use Record payment (it emails the customer if it's short).", 'yeffoprint-core' ),
					ucfirst( $method ),
					$this->plain_text_amount( $amount ),
					$note ?: __( '(none)', 'yeffoprint-core' )
				)
			);
			return rest_ensure_response( [ 'status' => 'unmatched' ] );
		}

		$this->notify_admin(
			sprintf( /* translators: 1: Venmo/Zelle, 2: amount */ __( 'Multiple orders match a %1$s payment: %2$s', 'yeffoprint-core' ), ucfirst( $method ), $this->plain_text_amount( $amount ) ),
			sprintf(
				/* translators: 1: Venmo/Zelle, 2: amount, 3: candidate count, 4: order list, 5: note text */
				__( "A %1\$s payment notification for %2\$s came in, but %3\$d unpaid orders owe that exact amount, so none were matched automatically (to avoid marking the wrong one paid). Open the right one in the admin app and use Record payment:\n\n%4\$s\n\nPayment note: %5\$s", 'yeffoprint-core' ),
				ucfirst( $method ),
				$this->plain_text_amount( $amount ),
				count( $candidates ),
				implode( "\n", array_map( [ $this, 'order_admin_line' ], $candidates ) ),
				$note ?: __( '(none)', 'yeffoprint-core' )
			)
		);

		return rest_ensure_response( [
			'status'    => 'ambiguous',
			'order_ids' => array_map( static function ( \WC_Order $order ) {
				return $order->get_id();
			}, $candidates ),
		] );
	}

	private function order_from_note( string $note, string $gateway_id, float $amount ): ?\WC_Order {
		if ( '' === trim( $note ) || ! preg_match( '/#?(\d{2,})/', $note, $matches ) ) {
			return null;
		}

		$order = wc_get_order( absint( $matches[1] ) );

		$open_ids = array_map( static function ( \WC_Order $open ): int {
			return $open->get_id();
		}, YeffoPrint_Partial_Payments::open_orders_for_gateway( $gateway_id ) );

		if ( ! $order || ! in_array( $order->get_id(), $open_ids, true ) ) {
			return null;
		}

		// A number that looks like an order id but the amount is more
		// than what's owed isn't trustworthy enough to force — could be a
		// coincidental number in the note (a phone digit, a date).
		// Falls through to amount-only matching instead of erroring. Less
		// than what's owed is a short payment on that order.
		if ( $amount - YeffoPrint_Partial_Payments::balance_due( $order ) >= 0.01 ) {
			return null;
		}

		return $order;
	}

	/** @return \WC_Order[] Open orders whose balance is exactly $amount. */
	private function open_orders_by_amount( string $gateway_id, float $amount ): array {
		return array_values( array_filter( YeffoPrint_Partial_Payments::open_orders_for_gateway( $gateway_id ), static function ( \WC_Order $order ) use ( $amount ) {
			return abs( YeffoPrint_Partial_Payments::balance_due( $order ) - $amount ) < 0.01;
		} ) );
	}

	/** @return \WC_Order[] Open orders owing a little more than $amount (see SHORT_TOLERANCE_*). */
	private function orders_slightly_over( string $gateway_id, float $amount ): array {
		return array_values( array_filter( YeffoPrint_Partial_Payments::open_orders_for_gateway( $gateway_id ), static function ( \WC_Order $order ) use ( $amount ) {
			$balance   = YeffoPrint_Partial_Payments::balance_due( $order );
			$shortfall = $balance - $amount;
			return $shortfall >= 0.01
				&& $shortfall <= min( self::SHORT_TOLERANCE_MAX, $balance * self::SHORT_TOLERANCE_RATIO ) + 0.001;
		} ) );
	}

	/**
	 * Records the payment on the order: paid in full moves it to
	 * Processing, short emails the customer the balance and the admin a
	 * heads-up.
	 */
	private function apply_payment( \WC_Order $order, float $amount, string $method, string $reason ) {
		$result = YeffoPrint_Partial_Payments::record(
			$order,
			$amount,
			$method,
			/* translators: %s: why the payment was matched to this order */
			sprintf( __( 'automated payment webhook, %s', 'yeffoprint-core' ), $reason )
		);

		if ( 'short' === $result['status'] ) {
			$this->notify_admin(
				sprintf(
					/* translators: 1: Venmo/Zelle, 2: order number, 3: amount still owed */
					__( 'Short %1$s payment on order #%2$s: %3$s still due', 'yeffoprint-core' ),
					ucfirst( $method ),
					$order->get_order_number(),
					$this->plain_text_amount( $result['balance'] )
				),
				sprintf(
					/* translators: 1: Venmo/Zelle, 2: amount, 3: order number, 4: order total, 5: amount still owed, 6: admin link */
					__( "A %1\$s payment of %2\$s was matched to order #%3\$s, but the order total is %4\$s. %5\$s is still due.\n\nThe customer has been emailed the remaining balance and how to pay it. The order stays unpaid until the rest comes in; the next payment for that amount will be matched automatically.\n\n%6\$s", 'yeffoprint-core' ),
					ucfirst( $method ),
					$this->plain_text_amount( $amount ),
					$order->get_order_number(),
					$this->plain_text_amount( (float) $order->get_total() ),
					$this->plain_text_amount( $result['balance'] ),
					$this->order_admin_line( $order )
				)
			);
		}

		return rest_ensure_response( [
			'status'   => 'paid' === $result['status'] ? 'matched' : 'short',
			'order_id' => $order->get_id(),
			'balance'  => $result['balance'],
		] );
	}

	private function order_admin_line( \WC_Order $order ): string {
		return '#' . $order->get_order_number() . ' — ' . admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
	}

	/**
	 * Direct report: the admin notification emails below showed literal
	 * "&#36;227.30" instead of "$227.30" — purely cosmetic, confirmed
	 * unrelated to matching (that compares $amount/$order->get_total()
	 * directly, never touching this formatted string). wc_price() returns
	 * HTML with the currency symbol as an entity (`&#36;` for `$`, or
	 * whatever the equivalent is for the store's actual currency);
	 * wp_strip_all_tags() only strips tags, not entities, and these
	 * strings land in a plain-text wp_mail() body, not an HTML context
	 * that would decode the entity for free the way an admin order note
	 * (rendered as HTML in wp-admin) already does.
	 */
	private function plain_text_amount( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	private function notify_admin( string $subject, string $body ): void {
		wp_mail( get_option( 'admin_email' ), $subject, $body );
	}
}
