<?php
/**
 * Keeps Telegram's own webhook registration in sync with the Bot
 * token/enabled settings, no matter which path saved them — the
 * classic Settings-API page (options.php) and the admin-app's
 * `/admin/settings` REST endpoint both end up calling update_option()
 * on these two keys, and WordPress fires `add_option_{name}` (first
 * save ever) or `update_option_{name}` (every save after, only when
 * the value actually changed) from inside that same call — so hooking
 * here once covers every save path without either one needing to know
 * Telegram exists.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Webhook_Sync {

	const LAST_SYNC_MESSAGE_OPTION = 'yeffoprint_telegram_last_sync_message';

	/** Both options can change in one request (the classic Settings page saves everything at once) — sync Telegram only once per request either way. */
	private static bool $synced_this_request = false;

	public function __construct() {
		$M = 'YeffoPrint_Admin_Menu';

		add_action( 'add_option_' . $M::TELEGRAM_BOT_TOKEN_OPTION, [ $this, 'sync' ] );
		add_action( 'update_option_' . $M::TELEGRAM_BOT_TOKEN_OPTION, [ $this, 'sync' ] );
		add_action( 'add_option_' . $M::TELEGRAM_ENABLED_OPTION, [ $this, 'sync' ] );
		add_action( 'update_option_' . $M::TELEGRAM_ENABLED_OPTION, [ $this, 'sync' ] );
	}

	public function sync(): void {
		if ( self::$synced_this_request ) {
			return;
		}
		self::$synced_this_request = true;

		$token   = YeffoPrint_Telegram_Settings::get_bot_token();
		$enabled = YeffoPrint_Telegram_Settings::is_enabled();

		if ( ! $enabled || '' === $token ) {
			// Actually tell Telegram to stop delivering, not just ignore
			// updates locally — an admin unchecking "active" should be a
			// real off switch.
			if ( '' !== $token ) {
				( new YeffoPrint_Telegram_Client( $token ) )->delete_webhook();
			}
			update_option( self::LAST_SYNC_MESSAGE_OPTION, __( 'Bot is off — not receiving messages.', 'yeffoprint-core' ), false );
			return;
		}

		$result = ( new YeffoPrint_Telegram_Client( $token ) )->set_webhook(
			YeffoPrint_Telegram_Webhook_Secret::webhook_url(),
			YeffoPrint_Telegram_Webhook_Secret::get()
		);

		$message = is_wp_error( $result )
			? sprintf(
				/* translators: %s: error message from Telegram */
				__( "Couldn't connect to Telegram: %s", 'yeffoprint-core' ),
				$result->get_error_message()
			)
			: __( 'Connected — the bot is live.', 'yeffoprint-core' );

		update_option( self::LAST_SYNC_MESSAGE_OPTION, $message, false );
	}

	public static function last_message(): string {
		return (string) get_option( self::LAST_SYNC_MESSAGE_OPTION, '' );
	}
}
