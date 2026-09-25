<?php
/**
 * Receives NOWPayments IPN (instant payment notification) callbacks —
 * the actual verification step behind the NOWPayments checkout option
 * (class-nowpayments-gateway.php): the gateway only ever creates an
 * Invoice and sends the customer to NOWPayments' hosted page; this is
 * what confirms a payment really happened and moves the order to
 * Processing, the same payment_complete() call every other automated
 * gateway on this site already uses.
 *
 * Authenticated via NOWPayments' own x-nowpayments-sig header — an
 * HMAC-SHA512 of the request body re-encoded with its keys sorted,
 * keyed with the "IPN Secret Key" from the NOWPayments dashboard
 * (pasted into the gateway's own settings,
 * class-nowpayments-gateway.php::ipn_secret()).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_NOWPayments_Webhook_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	// "confirmed" = enough block confirmations; "sending" = NOWPayments is
	// forwarding it to the payout wallet; "finished" = landed there. Any of
	// them means the customer has paid in full.
	private const PAID_STATUSES = [ 'confirmed', 'sending', 'finished' ];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/nowpayments/ipn', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle' ],
			// Authenticated via the signature check inside handle(), not a
			// WordPress capability — the caller is NOWPayments' servers.
			'permission_callback' => '__return_true',
		] );
	}

	public function handle( \WP_REST_Request $request ) {
		$payload   = $request->get_body();
		$signature = (string) $request->get_header( 'x-nowpayments-sig' );
		$data      = json_decode( $payload, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'yeffoprint_invalid_payload', __( 'Malformed IPN payload.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		if ( ! self::verify_signature( $data, $signature, YeffoPrint_NOWPayments_Gateway::ipn_secret() ) ) {
			return new \WP_Error( 'yeffoprint_invalid_signature', __( 'Invalid NOWPayments signature.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$status = (string) ( $data['payment_status'] ?? '' );

		if ( in_array( $status, self::PAID_STATUSES, true ) ) {
			return $this->handle_paid( $data );
		}

		switch ( $status ) {
			case 'partially_paid':
				return $this->handle_problem( $data, __( 'NOWPayments reports the customer paid less than the invoice amount (%1$s %2$s received). The order remains on-hold — contact the customer before proceeding.', 'yeffoprint-core' ), true );

			case 'failed':
				return $this->handle_problem( $data, __( 'NOWPayments reported this payment as failed (%1$s %2$s received). The order remains on-hold — verify with the customer before proceeding.', 'yeffoprint-core' ), true );

			case 'expired':
				// Customer opened the invoice and never paid — common and
				// harmless (same as an abandoned Venmo order), so a note but
				// no email.
				return $this->handle_problem( $data, __( 'NOWPayments invoice expired without payment (%1$s %2$s received).', 'yeffoprint-core' ), false );

			default:
				// waiting/confirming — nothing to act on yet. A 200 either
				// way so NOWPayments doesn't retry.
				return rest_ensure_response( [ 'status' => 'ignored' ] );
		}
	}

	/**
	 * NOWPayments signs the body re-serialized with its keys sorted
	 * (recursively). Their docs' PHP sample uses JSON_UNESCAPED_SLASHES
	 * while their own WooCommerce plugin uses plain json_encode(), so
	 * both encodings are accepted — either way it's an HMAC keyed with
	 * this store's secret.
	 */
	public static function verify_signature( array $data, string $signature, string $secret ): bool {
		if ( '' === $secret || '' === $signature ) {
			return false;
		}

		self::ksort_recursive( $data );

		foreach ( [ JSON_UNESCAPED_SLASHES, 0 ] as $flags ) {
			$json = json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- must match NOWPayments' exact serialization, not wp_json_encode's.
			if ( false !== $json && hash_equals( hash_hmac( 'sha512', $json, $secret ), strtolower( $signature ) ) ) {
				return true;
			}
		}

		return false;
	}

	private static function ksort_recursive( array &$data ): void {
		ksort( $data );
		foreach ( $data as &$value ) {
			if ( is_array( $value ) ) {
				self::ksort_recursive( $value );
			}
		}
	}

	/** order_id is set on every invoice this site creates; the stored invoice id is the fallback. */
	private function find_order( array $data ): ?\WC_Order {
		$order_id = (int) ( $data['order_id'] ?? 0 );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( $order instanceof \WC_Order && YeffoPrint_NOWPayments_Gateway::ID === $order->get_payment_method() ) {
			return $order;
		}

		$invoice_id = (string) ( $data['invoice_id'] ?? '' );
		if ( '' === $invoice_id ) {
			return null;
		}

		$order_ids = wc_get_orders( [
			'meta_key'   => '_yeffoprint_nowpayments_invoice_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one invoice's own lookup, not a listing screen.
			'meta_value' => $invoice_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'limit'      => 1,
			'return'     => 'ids',
		] );

		return $order_ids ? wc_get_order( $order_ids[0] ) ?: null : null;
	}

	private function handle_paid( array $data ) {
		$order = $this->find_order( $data );
		if ( ! $order ) {
			return rest_ensure_response( [ 'status' => 'unmatched' ] );
		}

		// NOWPayments sends several paid statuses per payment (confirmed,
		// sending, finished) — only the first one needs to do anything.
		if ( ! $order->needs_payment() ) {
			return rest_ensure_response( [ 'status' => 'already_paid', 'order_id' => $order->get_id() ] );
		}

		// The invoice's own price must still match the order — guards
		// against an invoice created for a different amount/currency being
		// replayed against this order.
		$price    = (float) ( $data['price_amount'] ?? 0 );
		$currency = strtoupper( (string) ( $data['price_currency'] ?? '' ) );
		if ( $currency !== strtoupper( $order->get_currency() ) || $price + 0.01 < (float) $order->get_total() ) {
			$order->add_order_note( sprintf(
				/* translators: 1: invoice amount, 2: invoice currency */
				__( 'NOWPayments confirmed a payment for an invoice of %1$s %2$s, which doesn\'t match this order\'s total. The order remains on-hold — check it in the NOWPayments dashboard.', 'yeffoprint-core' ),
				(string) ( $data['price_amount'] ?? '' ),
				$currency
			) );
			return rest_ensure_response( [ 'status' => 'amount_mismatch', 'order_id' => $order->get_id() ] );
		}

		$order->add_order_note( sprintf(
			/* translators: 1: crypto amount, 2: crypto currency/network code (e.g. usdttrc20), 3: NOWPayments payment id */
			__( 'NOWPayments confirmed payment of %1$s %2$s (payment %3$s).', 'yeffoprint-core' ),
			(string) ( $data['actually_paid'] ?? $data['pay_amount'] ?? '' ),
			strtoupper( (string) ( $data['pay_currency'] ?? '' ) ),
			(string) ( $data['payment_id'] ?? '—' )
		) );

		// payment_complete() — not a direct update_status() call — fires
		// woocommerce_payment_complete/the processing-status transition,
		// the same hook that already links a paid Custom Order to its
		// production workflow (class-custom-order-payment.php).
		$order->set_transaction_id( (string) ( $data['payment_id'] ?? '' ) );
		$order->payment_complete();

		return rest_ensure_response( [ 'status' => 'ok', 'order_id' => $order->get_id() ] );
	}

	private function handle_problem( array $data, string $note, bool $email ) {
		$order = $this->find_order( $data );
		if ( ! $order || ! $order->needs_payment() ) {
			return rest_ensure_response( [ 'status' => 'ignored' ] );
		}

		$order->add_order_note( sprintf(
			$note,
			(string) ( $data['actually_paid'] ?? '0' ),
			strtoupper( (string) ( $data['pay_currency'] ?? '' ) )
		) );

		if ( $email ) {
			wp_mail(
				get_option( 'admin_email' ),
				sprintf( /* translators: %s: order number */ __( 'Crypto payment problem for order #%s', 'yeffoprint-core' ), $order->get_order_number() ),
				sprintf(
					/* translators: 1: order number, 2: payment status, 3: order edit URL */
					__( "NOWPayments reported \"%2\$s\" for order #%1\$s. It's still on-hold — review it here:\n\n%3\$s", 'yeffoprint-core' ),
					$order->get_order_number(),
					(string) ( $data['payment_status'] ?? '' ),
					admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' )
				)
			);
		}

		return rest_ensure_response( [ 'status' => 'ok', 'order_id' => $order->get_id() ] );
	}
}
