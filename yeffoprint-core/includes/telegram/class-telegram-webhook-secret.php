<?php
/**
 * The one secret that authenticates incoming Telegram webhook calls —
 * same generate-once-store-in-an-option pattern as
 * class-payment-webhook-secret.php (Venmo/Zelle) and
 * class-stripe-webhook-secret.php (maintenance subscriptions). Passed
 * to Telegram as `secret_token` on `setWebhook`, and Telegram echoes it
 * back on every update as the `X-Telegram-Bot-Api-Secret-Token` header
 * — class-telegram-webhook-controller.php checks it there.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Webhook_Secret {

	private const OPTION_KEY = 'yeffoprint_telegram_webhook_secret';

	public static function get(): string {
		$secret = get_option( self::OPTION_KEY );

		if ( ! $secret || ! is_string( $secret ) ) {
			$secret = wp_generate_password( 48, false );
			update_option( self::OPTION_KEY, $secret, false );
		}

		return $secret;
	}

	public static function webhook_url(): string {
		return rest_url( 'yeffoprint-core/v1/telegram/webhook' );
	}
}
