<?php
/**
 * Links a Telegram chat to a real WP customer account — a different,
 * new concept from class-telegram-order-notifications.php's existing
 * per-*order* chat_id list (which opts in implicitly the moment
 * someone looks up one order via order-number + checkout email, and
 * has never had any relationship to a WP account at all).
 *
 * Direct request: "I'd like as many people to use this as possible" —
 * an account-level link means a customer only has to connect once,
 * ever, rather than re-proving order+email in the bot for every future
 * order, and it's the trust anchor the bot-driven proof approve/reject
 * buttons (class-telegram-callback-handler.php) require: mutating a
 * proof's status needs a stronger guarantee than "once knew an order
 * number and an email," so those buttons only ever appear for a chat
 * linked this way, gated to proofs on Custom Orders that chat's linked
 * account actually owns — the exact same CUSTOMER_ID ownership check
 * class-proof-approval-controller.php's own check_access() already
 * uses for a logged-in web session.
 *
 * Linking flow mirrors class-social-login.php's own OAuth `state`
 * pattern almost exactly: a short code generated server-side (My
 * Account's "Connect Telegram" tab, class-account-endpoints.php),
 * stashed in a transient keyed by the code with the WP user_id as
 * payload, consumed once — win or lose — the moment the bot receives
 * it (typed as `/link CODE`, or automatically via a `/start
 * link_CODE` deep link, class-telegram-message-handler.php). A code is
 * human-typeable (8 chars) rather than the 32-char password used for
 * that OAuth state, since a customer might actually type it by hand
 * instead of always tapping the deep-link button.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Account_Link {

	public const CHAT_ID_META = '_yp_telegram_chat_id';

	/**
	 * Set on an account class-telegram-login.php creates for a brand-new
	 * Telegram Login Widget sign-in — Telegram never hands back an email
	 * address at all, unlike Google/Discord/Apple, but every other part
	 * of this system (order confirmations, proof-approval emails,
	 * rewards) assumes a real one exists. Rather than silently store an
	 * undeliverable address, the account gets a placeholder using the
	 * `.invalid` TLD (RFC 2606 — reserved specifically for exactly this:
	 * a syntactically valid address guaranteed never to be a real,
	 * deliverable one) and this flag, so the rest of the site can react
	 * to it — class-account-endpoints.php shows a persistent "add your
	 * email" banner and blocks checkout while it's set, clearing
	 * automatically the moment the customer saves a real address.
	 */
	public const PLACEHOLDER_EMAIL_META = '_yp_telegram_placeholder_email';

	private const CODE_TRANSIENT_PREFIX = 'yp_telegram_link_';
	private const CODE_TTL              = 15 * MINUTE_IN_SECONDS;

	private const BOT_USERNAME_TRANSIENT = 'yp_telegram_bot_username';
	private const BOT_USERNAME_TTL       = DAY_IN_SECONDS;

	public static function generate_code( int $user_id ): string {
		$code = strtoupper( substr( wp_generate_password( 10, false, false ), 0, 8 ) );
		set_transient( self::CODE_TRANSIENT_PREFIX . $code, $user_id, self::CODE_TTL );

		return $code;
	}

	/** @return int 0 if the code is missing/expired/already used. */
	public static function consume_code( string $code ): int {
		$key     = self::CODE_TRANSIENT_PREFIX . strtoupper( trim( $code ) );
		$user_id = (int) get_transient( $key );

		delete_transient( $key ); // Win or lose — same one-shot reasoning as class-social-login.php's own state transient.

		return $user_id;
	}

	public static function link( int $user_id, int $chat_id ): void {
		update_user_meta( $user_id, self::CHAT_ID_META, $chat_id );
	}

	public static function unlink( int $user_id ): void {
		delete_user_meta( $user_id, self::CHAT_ID_META );
	}

	public static function get_chat_id( int $user_id ): int {
		return (int) get_user_meta( $user_id, self::CHAT_ID_META, true );
	}

	public static function is_linked( int $user_id ): bool {
		return self::get_chat_id( $user_id ) > 0;
	}

	/** Reverse lookup for a chat identifying itself — 0 if this chat has no linked account. */
	public static function get_user_id_for_chat( int $chat_id ): int {
		if ( ! $chat_id ) {
			return 0;
		}

		$users = get_users( [
			'meta_key'   => self::CHAT_ID_META,
			'meta_value' => $chat_id,
			'number'     => 1,
			'fields'     => 'ID',
		] );

		return $users ? (int) $users[0] : 0;
	}

	/**
	 * The `t.me/<username>?start=link_<code>` deep link — tapping it
	 * opens the chat and, on hitting Telegram's own "Start" button,
	 * delivers `/start link_<code>` as the first message, which
	 * class-telegram-message-handler.php recognizes and completes the
	 * link automatically — no typing required. Falls back to an empty
	 * string (caller shows the typed `/link CODE` instructions instead)
	 * if the bot's username can't be resolved (no token configured, or
	 * the one Telegram API call this needs failed) — never blocks the
	 * fallback flow on this one nice-to-have.
	 */
	public static function deep_link_url( string $code ): string {
		$username = self::bot_username();

		return $username ? 'https://t.me/' . $username . '?start=link_' . rawurlencode( $code ) : '';
	}

	/** A syntactically-valid, never-real placeholder for an account that has no real address yet — see PLACEHOLDER_EMAIL_META's own docblock for why. One per Telegram numeric id, so a repeat "log in with Telegram" attempt before the customer's added a real address is idempotent rather than colliding on a second wc_create_new_customer() call. */
	public static function placeholder_email( int $telegram_id ): string {
		return 'telegram-' . $telegram_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST ) . '.invalid';
	}

	public static function mark_placeholder_email( int $user_id ): void {
		update_user_meta( $user_id, self::PLACEHOLDER_EMAIL_META, 1 );
	}

	public static function has_placeholder_email( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, self::PLACEHOLDER_EMAIL_META, true );
	}

	public static function clear_placeholder_email_flag( int $user_id ): void {
		delete_user_meta( $user_id, self::PLACEHOLDER_EMAIL_META );
	}

	/** The bot's own @username, resolved once via Telegram's getMe and cached — needed both for the /link deep link above and for the Login Widget's required data-telegram-login attribute (class-telegram-login.php). */
	public static function bot_username(): string {
		$cached = get_transient( self::BOT_USERNAME_TRANSIENT );
		if ( is_string( $cached ) ) {
			return $cached; // Cached '' (a prior failed lookup) intentionally still short-circuits — see below.
		}

		$token = YeffoPrint_Telegram_Settings::get_bot_token();
		if ( '' === $token ) {
			return '';
		}

		$me       = ( new YeffoPrint_Telegram_Client( $token ) )->get_me();
		$username = ! is_wp_error( $me ) ? (string) ( $me['result']['username'] ?? '' ) : '';

		// Cache a failure too, just for a short while — so a misconfigured
		// token doesn't turn "load the Connect Telegram tab" into a live
		// Telegram API call on every single page view.
		set_transient( self::BOT_USERNAME_TRANSIENT, $username, $username ? self::BOT_USERNAME_TTL : 5 * MINUTE_IN_SECONDS );

		return $username;
	}
}
