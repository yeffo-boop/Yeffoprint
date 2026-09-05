<?php
/**
 * Receives Coinbase Commerce webhook events — the actual verification
 * step behind the Coinbase Commerce checkout option (class-coinbase-
 * gateway.php): the gateway only ever creates a Charge and sends the
 * customer to Coinbase's own hosted page; this is what confirms a
 * payment really happened and moves the order to Processing, the same
 * payment_complete() call every other automated gateway on this site
 * already uses.
 *
 * Authenticated via Coinbase's own X-CC-Webhook-Signature header — an
 * HMAC-SHA256 of the raw request body, keyed with the "Webhook Shared
 * Secret" Coinbase Commerce shows once when the webhook endpoint is
 * added in its dashboard (pasted into the gateway's own settings,
 * class-coinbase-gateway.php::webhook_secret()). Same hash_hmac()/
 * hash_equals() approach as the existing Stripe webhook controller
 * (class-stripe-webhook-controller.php) — Coinbase's scheme has no
 * timestamp component to check, just the raw signature.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Coinbase_Webhook_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/coinbase/webhook', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle' ],
			// Authenticated via the signature check inside handle(), not a
			// WordPress capability — the caller is Coinbase's servers.
			'permission_callback' => '__return_true',
		] );
	}

	public function handle( \WP_REST_Request $request ) {
		$payload   = $request->get_body();
		$signature = (string) $request->get_header( 'x-cc-webhook-signature' );

		if ( ! $this->verify_signature( $payload, $signature ) ) {
			return new \WP_Error( 'yeffoprint_invalid_signature', __( 'Invalid Coinbase Commerce signature.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$body  = json_decode( $payload, true );
		$event = is_array( $body ) ? ( $body['event'] ?? [] ) : [];

		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new \WP_Error( 'yeffoprint_invalid_payload', __( 'Malformed webhook payload.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$charge = is_array( $event['data'] ?? null ) ? $event['data'] : [];

		switch ( $event['type'] ) {
			case 'charge:confirmed':
			case 'charge:resolved':
				return $this->handle_confirmed( $charge );

			case 'charge:failed':
				return $this->handle_failed( $charge );

			default:
				// created/pending/delayed — nothing to act on yet (delayed
				// just means Coinbase is still waiting on more block
				// confirmations; confirmed/resolved is what actually
				// finishes it). A 200 either way — Coinbase doesn't need
				// to retry an event this endpoint has no work to do for.
				return rest_ensure_response( [ 'status' => 'ignored' ] );
		}
	}

	private function verify_signature( string $payload, string $signature ): bool {
		$secret = YeffoPrint_Coinbase_Gateway::webhook_secret();

		if ( '' === $secret || '' === $signature ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha256', $payload, $secret ), $signature );
	}

	/**
	 * metadata.order_id (set on every charge this site creates,
	 * class-coinbase-gateway.php) is the primary lookup; the charge code
	 * this site also stores on the order is the fallback for the one
	 * case that wouldn't have it — a charge created some other way (e.g.
	 * directly in the Coinbase dashboard) that still somehow matches this
	 * store, which shouldn't normally happen but costs nothing to guard.
	 */
	private function find_order( array $charge ): ?\WC_Order {
		$order_id = (int) ( $charge['metadata']['order_id'] ?? 0 );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( $order instanceof \WC_Order ) {
			return $order;
		}

		$code = (string) ( $charge['code'] ?? '' );
		if ( '' === $code ) {
			return null;
		}

		$order_ids = wc_get_orders( [
			'meta_key'   => '_yeffoprint_coinbase_charge_code', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one charge's own lookup, not a listing screen.
			'meta_value' => $code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'limit'      => 1,
			'return'     => 'ids',
		] );

		return $order_ids ? wc_get_order( $order_ids[0] ) ?: null : null;
	}

	private function handle_confirmed( array $charge ) {
		$order = $this->find_order( $charge );
		if ( ! $order ) {
			return rest_ensure_response( [ 'status' => 'unmatched' ] );
		}

		$payment = is_array( $charge['payments'][0] ?? null ) ? $charge['payments'][0] : [];
		$network = (string) ( $payment['network'] ?? '' );

		$order->add_order_note(
			'' !== $network
				? sprintf(
					/* translators: 1: crypto amount, 2: crypto currency, 3: network/chain, 4: transaction id */
					__( 'Coinbase Commerce confirmed payment of %1$s %2$s on %3$s (tx %4$s).', 'yeffoprint-core' ),
					(string) ( $payment['value']['crypto']['amount'] ?? '' ),
					(string) ( $payment['value']['crypto']['currency'] ?? '' ),
					$network,
					(string) ( $payment['transaction_id'] ?? '—' )
				)
				: __( 'Coinbase Commerce confirmed this payment.', 'yeffoprint-core' )
		);

		// payment_complete() — not a direct update_status() call — fires
		// woocommerce_payment_complete/the processing-status transition,
		// the same hook that already links a paid Custom Order to its
		// production workflow (class-custom-order-payment.php). It's also
		// a safe no-op once an order is past on-hold (WC_Order::needs_
		// payment() gates it), so a redelivered webhook never double-
		// processes an order.
		$order->payment_complete();

		return rest_ensure_response( [ 'status' => 'ok', 'order_id' => $order->get_id() ] );
	}

	/** Coinbase's own checkout page already retries/expires a charge on its side — this only ever fires once that's given up, so the order needs a human, not another automatic step. */
	private function handle_failed( array $charge ) {
		$order = $this->find_order( $charge );
		if ( ! $order ) {
			return rest_ensure_response( [ 'status' => 'unmatched' ] );
		}

		$order->add_order_note( __( 'Coinbase Commerce reported this payment as failed/expired. The order remains on-hold — verify with the customer before proceeding.', 'yeffoprint-core' ) );

		wp_mail(
			get_option( 'admin_email' ),
			sprintf( /* translators: %s: order number */ __( 'Crypto payment failed for order #%s', 'yeffoprint-core' ), $order->get_order_number() ),
			sprintf(
				/* translators: 1: order number, 2: order edit URL */
				__( "Coinbase Commerce reported a failed/expired crypto payment for order #%1\$s. It's still on-hold — review it here:\n\n%2\$s", 'yeffoprint-core' ),
				$order->get_order_number(),
				admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' )
			)
		);

		return rest_ensure_response( [ 'status' => 'ok', 'order_id' => $order->get_id() ] );
	}
}
