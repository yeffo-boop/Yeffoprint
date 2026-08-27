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
	const WEBHOOK_CONFIG_VERSION_OPTION = 'yeffoprint_telegram_webhook_config_version';

	/**
	 * Bump this whenever set_webhook()'s own params change in a way
	 * Telegram needs told about again (class-telegram-client.php) — the
	 * `callback_query` addition to `allowed_updates` (direct request:
	 * approve/reject a proof from the bot) is the first such change.
	 * Re-syncing only ever happens automatically on a *save* of the bot
	 * token/enabled Settings otherwise (the four hooks below) — a code-
	 * only deploy that changes what set_webhook() sends never touches
	 * either option, so an already-registered webhook would otherwise
	 * keep running with Telegram still only delivering `message` updates
	 * until an admin happened to re-save Settings. Same "a version check
	 * on init covers every real-world deploy path, not just an option
	 * save" reasoning as class-yeffoprint-core.php's own rewrite-flush
	 * version check.
	 */
	private const WEBHOOK_CONFIG_VERSION = 2;

	public function __construct() {
		$M = 'YeffoPrint_Admin_Menu';

		add_action( 'add_option_' . $M::TELEGRAM_BOT_TOKEN_OPTION, [ $this, 'sync' ] );
		add_action( 'update_option_' . $M::TELEGRAM_BOT_TOKEN_OPTION, [ $this, 'sync' ] );
		add_action( 'add_option_' . $M::TELEGRAM_ENABLED_OPTION, [ $this, 'sync' ] );
		add_action( 'update_option_' . $M::TELEGRAM_ENABLED_OPTION, [ $this, 'sync' ] );
		add_action( 'init', [ $this, 'maybe_resync_for_version_change' ] );
	}

	/** A no-op API call on every request but the first one after a version bump — get_option() is cheap, sync() itself is not, so this only ever fires the real Telegram call once. */
	public function maybe_resync_for_version_change(): void {
		if ( (int) get_option( self::WEBHOOK_CONFIG_VERSION_OPTION, 0 ) === self::WEBHOOK_CONFIG_VERSION ) {
			return;
		}

		$this->sync();
		update_option( self::WEBHOOK_CONFIG_VERSION_OPTION, self::WEBHOOK_CONFIG_VERSION );
	}

	/**
	 * Deliberately re-runs in full on every one of the four hooks above
	 * that fires in a given request, rather than a "just once" guard —
	 * both options can change in the same save (e.g. the very first
	 * time a token is entered and "active" is checked together), and
	 * update_option() writes an option's new value to the DB/cache
	 * *before* firing its own hook, so whichever of these hooks fires
	 * last always sees both options' fully current values. A "sync
	 * once" guard broke exactly this case: token-save's hook could fire
	 * first, read the not-yet-updated old "enabled" value, decide the
	 * bot was off, and then block enabled-save's hook — the one that
	 * would've seen the correct final state — from running at all.
	 */
	public function sync(): void {
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
