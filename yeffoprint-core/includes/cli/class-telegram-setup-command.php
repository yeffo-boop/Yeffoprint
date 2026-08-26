<?php
/**
 * `wp yeffoprint telegram sync-webhook` / `wp yeffoprint telegram info`
 * — the Settings save flow (class-telegram-webhook-sync.php) already
 * re-registers the webhook automatically whenever the bot token/on-off
 * option changes; these exist as a manual fallback (a token set only
 * via the YEFFOPRINT_TELEGRAM_BOT_TOKEN wp-config.php constant never
 * fires that options hook) and a way to check Telegram's own view of
 * the webhook without leaving the terminal.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Setup_Command {

	public function register(): void {
		\WP_CLI::add_command( 'yeffoprint telegram sync-webhook', [ $this, 'sync_webhook' ] );
		\WP_CLI::add_command( 'yeffoprint telegram info', [ $this, 'info' ] );
	}

	/**
	 * ## EXAMPLES
	 *
	 *     wp yeffoprint telegram sync-webhook
	 */
	public function sync_webhook(): void {
		$token = YeffoPrint_Telegram_Settings::get_bot_token();

		if ( '' === $token ) {
			\WP_CLI::error( 'No Telegram bot token is configured — set one in wp-admin → YeffoPrint → Settings, or define YEFFOPRINT_TELEGRAM_BOT_TOKEN in wp-config.php.' );
			return;
		}

		$result = ( new YeffoPrint_Telegram_Client( $token ) )->set_webhook(
			YeffoPrint_Telegram_Webhook_Secret::webhook_url(),
			YeffoPrint_Telegram_Webhook_Secret::get()
		);

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( 'Telegram rejected the webhook: ' . $result->get_error_message() );
			return;
		}

		\WP_CLI::success( 'Webhook registered: ' . YeffoPrint_Telegram_Webhook_Secret::webhook_url() );
	}

	/**
	 * ## EXAMPLES
	 *
	 *     wp yeffoprint telegram info
	 */
	public function info(): void {
		$token = YeffoPrint_Telegram_Settings::get_bot_token();

		if ( '' === $token ) {
			\WP_CLI::error( 'No Telegram bot token is configured.' );
			return;
		}

		$result = ( new YeffoPrint_Telegram_Client( $token ) )->get_webhook_info();

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		\WP_CLI::log( print_r( $result['result'] ?? $result, true ) );
	}
}
