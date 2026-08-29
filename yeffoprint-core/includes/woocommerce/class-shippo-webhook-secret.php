<?php
/**
 * The one secret that authenticates incoming Shippo `track_updated`
 * webhook calls — Shippo has no signing-secret mechanism like Stripe's
 * (no header it echoes back to verify against), so same "long random
 * bearer token embedded in the URL itself" pattern as
 * class-payment-webhook-secret.php (Venmo/Zelle) and
 * class-telegram-webhook-secret.php.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Shippo_Webhook_Secret {

	private const OPTION_KEY = 'yeffoprint_shippo_webhook_secret';

	public static function get(): string {
		$secret = get_option( self::OPTION_KEY );

		if ( ! $secret || ! is_string( $secret ) ) {
			$secret = wp_generate_password( 48, false );
			update_option( self::OPTION_KEY, $secret, false );
		}

		return $secret;
	}

	/** The exact, ready-to-register URL — token included, matching class-payment-webhook-secret.php::webhook_url()'s own shape. */
	public static function webhook_url(): string {
		return add_query_arg( 'token', self::get(), rest_url( 'yeffoprint-core/v1/shippo/webhook' ) );
	}
}
