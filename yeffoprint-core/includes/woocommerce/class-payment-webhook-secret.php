<?php
/**
 * The one secret that authenticates incoming Venmo/Zelle payment
 * notifications (class-payment-webhook-controller.php) — there's no
 * WordPress session to check a nonce against here, the caller is an
 * external automation (Gmail/Apps Script, Zapier, Power Automate, …)
 * hitting the REST endpoint directly, so a long random bearer token in
 * the URL is the entire access control. Same generate-once-store-in-
 * an-option pattern as the Custom Order proof-approval access token
 * (class-custom-order-meta.php) — deliberately never rotated
 * automatically, since rotating it silently would break whatever
 * automation the admin already has configured with the old one.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Payment_Webhook_Secret {

	private const OPTION_KEY = 'yeffoprint_payment_webhook_secret';

	public static function get(): string {
		$secret = get_option( self::OPTION_KEY );

		if ( ! $secret || ! is_string( $secret ) ) {
			$secret = wp_generate_password( 48, false );
			update_option( self::OPTION_KEY, $secret, false );
		}

		return $secret;
	}

	/** The exact, ready-to-paste URL for a given method ('venmo'/'zelle') — token included. */
	public static function webhook_url( string $method ): string {
		return add_query_arg(
			[
				'method' => $method,
				'token'  => self::get(),
			],
			rest_url( 'yeffoprint-core/v1/payments/notify' )
		);
	}
}
