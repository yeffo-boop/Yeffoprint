<?php
/**
 * Receives Shippo's `track_updated` webhook — direct question, then a
 * direct request: "Shippo support webhooks for tracking updates
 * whenever a package status changes. Would that be better? ... can we
 * integrate this into the bot..." (the bot half shipped separately;
 * this is the webhook half). Authenticated the same way as the existing
 * Venmo/Zelle payment webhook (class-payment-webhook-controller.php) —
 * a long random bearer token in the URL itself, since Shippo, unlike
 * Stripe, has no signing-secret mechanism to verify a payload against.
 *
 * Registered with Shippo automatically by class-shippo-webhook-sync.php
 * whenever the API key is saved; the exact same URL is also shown in
 * Settings so an admin can register it by hand in the Shippo dashboard
 * if that best-effort auto-registration didn't take.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Shippo_Webhook_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/shippo/webhook', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => [ $this, 'check_token' ],
		] );
	}

	/** @return true|\WP_Error */
	public function check_token( \WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );

		if ( '' === $token || ! hash_equals( YeffoPrint_Shippo_Webhook_Secret::get(), $token ) ) {
			return new \WP_Error( 'yeffoprint_invalid_token', __( 'Invalid or missing token.', 'yeffoprint-core' ), [ 'status' => 403 ] );
		}

		return true;
	}

	public function handle( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : [];

		// Only ever registered for track_updated (class-shippo-webhook-
		// sync.php), but a 200 rather than an error on anything else — a
		// misconfigured or future event type Shippo decides to send here
		// isn't this endpoint's fault, and erroring would just make Shippo
		// keep retrying a request that will never succeed.
		if ( 'track_updated' !== ( $body['event'] ?? '' ) ) {
			return rest_ensure_response( [ 'status' => 'ignored' ] );
		}

		$data = $body['data'] ?? [];
		if ( ! is_array( $data ) ) {
			return rest_ensure_response( [ 'status' => 'ignored' ] );
		}

		$carrier_id      = sanitize_key( (string) ( $data['carrier'] ?? '' ) );
		$tracking_number = trim( (string) ( $data['tracking_number'] ?? '' ) );

		if ( '' === $carrier_id || '' === $tracking_number ) {
			return rest_ensure_response( [ 'status' => 'ignored' ] );
		}

		$order = YeffoPrint_Order_Tracking::find_order_by_tracking_number( $tracking_number );
		if ( ! $order ) {
			// Not indexed (yet) — the hourly sweep backfills every Shipped
			// order's index, so a webhook that arrives before that has run
			// once just gets picked up on the next sweep instead. Not an
			// error: Shippo doesn't need to retry this.
			return rest_ensure_response( [ 'status' => 'unmatched' ] );
		}

		$parsed = YeffoPrint_Shippo_Client::parse_tracking_payload( $data );

		( new YeffoPrint_Order_Delivery_Status() )->record_live_status( $order, $tracking_number, $parsed['events'] );

		return rest_ensure_response( [ 'status' => 'ok', 'order_id' => $order->get_id() ] );
	}
}
