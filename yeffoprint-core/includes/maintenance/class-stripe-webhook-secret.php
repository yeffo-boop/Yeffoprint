<?php
/**
 * The signing secret Stripe issues when the maintenance-plan webhook
 * endpoint is created in the Stripe Dashboard — admin-pasted via the
 * Settings page, not generated here, since Stripe (not this site)
 * controls that value. Same option-backed storage shape as the
 * existing class-payment-webhook-secret.php, just admin-set instead of
 * auto-generated on first read (there's nothing to generate — a value
 * this site invented wouldn't match what Stripe actually signs with).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Stripe_Webhook_Secret {

	public const OPTION_KEY = 'yeffoprint_stripe_webhook_secret';

	public static function get(): string {
		$secret = get_option( self::OPTION_KEY, '' );

		return is_string( $secret ) ? $secret : '';
	}

	public static function is_configured(): bool {
		return '' !== self::get();
	}
}
