<?php
/**
 * Receives Stripe webhook events for the maintenance-plan subscription —
 * a direct Stripe Payment Link purchase, not routed through WooCommerce's
 * own checkout (WooPayments' own Stripe connection is managed/obfuscated
 * and doesn't expose keys this could reuse; see docs/ARCHITECTURE.md).
 * Keeps yp_maintenance_sub records
 * (includes/maintenance/class-maintenance-sub-meta.php) in sync with
 * what's actually true in Stripe: created on a successful checkout,
 * status kept current on every subscription update, marked canceled on
 * deletion.
 *
 * Authenticated by verifying Stripe's own webhook signature
 * (Stripe-Signature header) against the secret pasted into Settings
 * when the webhook endpoint was created in the Stripe Dashboard
 * (class-stripe-webhook-secret.php) — not a WordPress nonce, since the
 * caller is Stripe's servers, not a browser session; same
 * necessarily-public route shape as the existing Venmo/Zelle payment
 * webhook (class-payment-webhook-controller.php), just authenticated
 * differently. Verified with plain hash_hmac()/hash_equals() rather
 * than the Stripe PHP SDK — Stripe's signature scheme is simple enough
 * (HMAC-SHA256 over "{timestamp}.{payload}") that pulling in the full
 * SDK as a new Composer dependency just for this one check isn't
 * worth it.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Stripe_Webhook_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	// Stripe's own SDK defaults to this same 5-minute tolerance between
	// the event's signed timestamp and "now" — rejects anything older as
	// a possible replay of a captured request.
	private const TOLERANCE_SECONDS = 300;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/stripe/webhook', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle' ],
			// Authenticated via the Stripe-Signature check inside handle(),
			// not a WordPress capability — the caller is Stripe's servers.
			'permission_callback' => '__return_true',
		] );
	}

	public function handle( \WP_REST_Request $request ) {
		$payload   = $request->get_body();
		$signature = (string) $request->get_header( 'stripe-signature' );

		if ( ! $this->verify_signature( $payload, $signature ) ) {
			return new \WP_Error( 'yeffoprint_invalid_signature', __( 'Invalid Stripe signature.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new \WP_Error( 'yeffoprint_invalid_payload', __( 'Malformed webhook payload.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$object = $event['data']['object'] ?? [];

		switch ( $event['type'] ) {
			case 'checkout.session.completed':
				$this->handle_checkout_completed( is_array( $object ) ? $object : [] );
				break;

			case 'customer.subscription.updated':
				$this->handle_subscription_status( is_array( $object ) ? $object : [] );
				break;

			case 'customer.subscription.deleted':
				$this->handle_subscription_status( is_array( $object ) ? $object : [], 'canceled' );
				break;
		}

		return rest_ensure_response( [ 'received' => true ] );
	}

	private function verify_signature( string $payload, string $header ): bool {
		$secret = YeffoPrint_Stripe_Webhook_Secret::get();

		if ( '' === $secret || '' === $header ) {
			return false;
		}

		$timestamp  = null;
		$signatures = [];

		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		if ( null === $timestamp || empty( $signatures ) ) {
			return false;
		}

		if ( abs( time() - $timestamp ) > self::TOLERANCE_SECONDS ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}

		return false;
	}

	private function handle_checkout_completed( array $session ): void {
		if ( ( $session['mode'] ?? '' ) !== 'subscription' || empty( $session['subscription'] ) ) {
			return;
		}

		$subscription_id = (string) $session['subscription'];
		$customer_id     = (string) ( $session['customer'] ?? '' );
		$email           = (string) ( $session['customer_details']['email'] ?? $session['customer_email'] ?? '' );
		$plan_label      = (string) ( $session['metadata']['plan_label'] ?? __( 'Website Maintenance & Monitoring', 'yeffoprint-core' ) );

		$existing = YeffoPrint_Maintenance_Sub_Meta::find_by_subscription_id( $subscription_id );
		$post_id  = $existing ? $existing->ID : wp_insert_post( [
			'post_type'   => 'yp_maintenance_sub',
			'post_status' => 'publish',
			'post_title'  => $email ?: $subscription_id,
		], true );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return;
		}

		update_post_meta( $post_id, YeffoPrint_Maintenance_Sub_Meta::STRIPE_SUBSCRIPTION_ID, $subscription_id );
		update_post_meta( $post_id, YeffoPrint_Maintenance_Sub_Meta::STRIPE_CUSTOMER_ID, $customer_id );
		update_post_meta( $post_id, YeffoPrint_Maintenance_Sub_Meta::CUSTOMER_EMAIL, sanitize_email( $email ) );
		update_post_meta( $post_id, YeffoPrint_Maintenance_Sub_Meta::PLAN_LABEL, $plan_label );
		update_post_meta( $post_id, YeffoPrint_Maintenance_Sub_Meta::STATUS, 'active' );

		$user = $email ? get_user_by( 'email', $email ) : false;
		if ( $user ) {
			update_post_meta( $post_id, YeffoPrint_Maintenance_Sub_Meta::CUSTOMER_USER_ID, $user->ID );
		}
	}

	private function handle_subscription_status( array $subscription, string $status_override = '' ): void {
		$subscription_id = (string) ( $subscription['id'] ?? '' );
		if ( '' === $subscription_id ) {
			return;
		}

		$post = YeffoPrint_Maintenance_Sub_Meta::find_by_subscription_id( $subscription_id );
		if ( ! $post ) {
			return; // No matching checkout.session.completed seen yet — nothing to update.
		}

		$status = $status_override ?: $this->normalize_status( (string) ( $subscription['status'] ?? '' ) );
		update_post_meta( $post->ID, YeffoPrint_Maintenance_Sub_Meta::STATUS, $status );

		if ( ! empty( $subscription['current_period_end'] ) ) {
			update_post_meta( $post->ID, YeffoPrint_Maintenance_Sub_Meta::CURRENT_PERIOD_END, (int) $subscription['current_period_end'] );
		}
	}

	private function normalize_status( string $stripe_status ): string {
		if ( array_key_exists( $stripe_status, YeffoPrint_Maintenance_Sub_Meta::STATUSES ) ) {
			return $stripe_status;
		}

		// Stripe has several sub-statuses (trialing, incomplete, unpaid, …)
		// this plan doesn't distinguish between yet — anything
		// unrecognized collapses to past_due, a safe "needs a look"
		// default rather than silently dropping the update.
		return 'past_due';
	}
}
