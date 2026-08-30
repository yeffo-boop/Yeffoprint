<?php
/**
 * Reads the two Telegram options `class-admin-menu.php` registers
 * (Settings screen, both classic and the admin-app REST path) — one
 * place every other Telegram class reads bot token/on-off state from,
 * rather than each calling get_option() against YeffoPrint_Admin_Menu's
 * constants directly.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Settings {

	/**
	 * A YEFFOPRINT_TELEGRAM_BOT_TOKEN constant (wp-config.php) wins over
	 * the database option, for admins who'd rather keep the token out
	 * of the database entirely — same override idiom as, e.g., DB
	 * credentials. The Settings-screen option stays the default path so
	 * the bot can be turned on entirely from wp-admin, no code access
	 * required.
	 */
	public static function get_bot_token(): string {
		if ( defined( 'YEFFOPRINT_TELEGRAM_BOT_TOKEN' ) && YEFFOPRINT_TELEGRAM_BOT_TOKEN ) {
			return (string) YEFFOPRINT_TELEGRAM_BOT_TOKEN;
		}

		return (string) get_option( YeffoPrint_Admin_Menu::TELEGRAM_BOT_TOKEN_OPTION, '' );
	}

	public static function is_enabled(): bool {
		return (bool) get_option( YeffoPrint_Admin_Menu::TELEGRAM_ENABLED_OPTION, false );
	}

	/** The bot's public @handle, without the "@" — e.g. "yeffoprint_bot". */
	public static function get_bot_username(): string {
		return ltrim( (string) get_option( YeffoPrint_Admin_Menu::TELEGRAM_BOT_USERNAME_OPTION, YeffoPrint_Admin_Menu::TELEGRAM_BOT_USERNAME_DEFAULT ), '@' );
	}

	/**
	 * A real t.me/ link to the bot, ready to drop into a "Chat on
	 * Telegram" button/badge — null whenever no username is configured
	 * yet, so a caller can simply skip rendering the link rather than
	 * pointing it at a broken URL.
	 */
	public static function public_url(): ?string {
		$username = self::get_bot_username();
		return $username ? 'https://t.me/' . rawurlencode( $username ) : null;
	}
}
