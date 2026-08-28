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
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Payment_Webhook_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

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
			$this->mark_paid( $order_from_note, $amount, $method, __( 'order number found in payment note', 'yeffoprint-core' ) );
			return rest_ensure_response( [ 'status' => 'matched', 'order_id' => $order_from_note->get_id() ] );
		}

		$candidates = $this->on_hold_orders_by_amount( $gateway_id, $amount );

		if ( 1 === count( $candidates ) ) {
			$this->mark_paid( $candidates[0], $amount, $method, __( 'exact amount match, only one on-hold order at that total', 'yeffoprint-core' ) );
			return rest_ensure_response( [ 'status' => 'matched', 'order_id' => $candidates[0]->get_id() ] );
		}

		if ( empty( $candidates ) ) {
			$this->notify_admin(
				sprintf( /* translators: 1: Venmo/Zelle, 2: amount */ __( 'Unmatched %1$s payment: %2$s', 'yeffoprint-core' ), ucfirst( $method ), $this->plain_text_amount( $amount ) ),
				sprintf(
					/* translators: 1: Venmo/Zelle, 2: amount, 3: note text */
					__( "A %1\$s payment notification for %2\$s came in, but no on-hold order for that exact amount was found.\n\nPayment note: %3\$s\n\nIf this is a real payment, find the order and update its status manually.", 'yeffoprint-core' ),
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
				__( "A %1\$s payment notification for %2\$s came in, but %3\$d on-hold orders share that exact total, so none were matched automatically (to avoid marking the wrong one paid). Please review and update the correct one manually:\n\n%4\$s\n\nPayment note: %5\$s", 'yeffoprint-core' ),
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

		if ( ! $order || ! $order->has_status( 'on-hold' ) || $order->get_payment_method() !== $gateway_id ) {
			return null;
		}

		// A number that looks like an order id but the amount doesn't
		// match isn't trustworthy enough to force — could be a
		// coincidental number in the note (a phone digit, a date).
		// Falls through to amount-only matching instead of erroring.
		if ( abs( (float) $order->get_total() - $amount ) >= 0.01 ) {
			return null;
		}

		return $order;
	}

	/** @return \WC_Order[] */
	private function on_hold_orders_by_amount( string $gateway_id, float $amount ): array {
		$orders = wc_get_orders( [
			'status'         => 'on-hold',
			'payment_method' => $gateway_id,
			'limit'          => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
		] );

		return array_values( array_filter( $orders, static function ( $order ) use ( $amount ) {
			return $order instanceof \WC_Order && abs( (float) $order->get_total() - $amount ) < 0.01;
		} ) );
	}

	private function mark_paid( \WC_Order $order, float $amount, string $method, string $reason ): void {
		$order->add_order_note( sprintf(
			/* translators: 1: Venmo/Zelle, 2: amount, 3: match reason */
			__( 'Matched to an incoming %1$s payment of %2$s (%3$s) via the automated payment webhook.', 'yeffoprint-core' ),
			ucfirst( $method ),
			wp_strip_all_tags( wc_price( $amount ) ),
			$reason
		) );

		// payment_complete() — not a direct update_status() call — is
		// what fires woocommerce_payment_complete/the processing-status
		// transition, which is what already links a paid Custom Order
		// to its production workflow (class-custom-order-payment.php).
		// An order placed through either flow resolves the same way here.
		$order->payment_complete();
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
	 * (mark_paid() below, rendered as HTML in wp-admin) already does.
	 */
	private function plain_text_amount( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	private function notify_admin( string $subject, string $body ): void {
		wp_mail( get_option( 'admin_email' ), $subject, $body );
	}
}
