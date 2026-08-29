<?php
/**
 * Registers Shippo's `track_updated` webhook whenever the Shippo API
 * key is saved — same "sync on option save" shape as class-telegram-
 * webhook-sync.php (see that file's own docblock), just against Shippo
 * instead of Telegram's Bot API.
 *
 * Best-effort: class-shippo-client.php::register_webhook() is a
 * documented-but-unverified read of Shippo's webhook-management API
 * (unlike /shipments/, /tracks/, and /transactions/, all exercised
 * against a real account this session) — a WP_Error here is treated as
 * informational, stored as the last-sync message, not fatal to
 * anything. The webhook URL is always shown in Settings too, so a wrong
 * guess here just means the admin adds it by hand in the Shippo
 * dashboard (Settings → API → Webhooks) instead of it happening
 * automatically.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Shippo_Webhook_Sync {

	private const LAST_SYNC_MESSAGE_OPTION = 'yeffoprint_shippo_webhook_last_sync_message';

	public function __construct() {
		add_action( 'add_option_' . YeffoPrint_Shippo_Settings::API_KEY_OPTION, [ $this, 'sync' ] );
		add_action( 'update_option_' . YeffoPrint_Shippo_Settings::API_KEY_OPTION, [ $this, 'sync' ] );
	}

	public function sync(): void {
		if ( ! YeffoPrint_Shippo_Settings::is_configured() ) {
			update_option( self::LAST_SYNC_MESSAGE_OPTION, __( 'No Shippo API token configured yet.', 'yeffoprint-core' ), false );
			return;
		}

		$client = new YeffoPrint_Shippo_Client( YeffoPrint_Shippo_Settings::get_api_key() );
		$result = $client->register_webhook( YeffoPrint_Shippo_Webhook_Secret::webhook_url() );

		$message = is_wp_error( $result )
			? sprintf(
				/* translators: %s: error message from Shippo */
				__( "Couldn't register the webhook automatically (%s). You can add it by hand instead — Shippo dashboard → Settings → API → Webhooks — using the URL above, event \"track_updated\".", 'yeffoprint-core' ),
				$result->get_error_message()
			)
			: __( 'Registered — Shippo will push tracking updates here automatically, on top of the hourly check.', 'yeffoprint-core' );

		update_option( self::LAST_SYNC_MESSAGE_OPTION, $message, false );
	}

	public static function last_message(): string {
		return (string) get_option( self::LAST_SYNC_MESSAGE_OPTION, '' );
	}
}
