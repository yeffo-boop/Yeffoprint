<?php
/**
 * "Escalate to a human" — when the bot's FAQ/order-lookup logic can't
 * answer, this offers to relay the customer's own message to the
 * store instead of just telling them to email in. State lives in a
 * short-lived transient, the same tool class-contact-controller.php's
 * own rate limiter already uses for per-request state that doesn't
 * need a database column.
 *
 * Three steps, not two — direct report: "it didn't capture any contact
 * information for me to reach out to them." The original flow (offer,
 * then a yes/no confirmation) forwarded the message the moment the
 * customer said yes, with no way to reach them beyond whatever Telegram
 * happened to expose (a @username, if they have one) or, for the
 * website chat widget, nothing at all — `describe_sender()` falls back
 * to the literal placeholder "Website visitor" there, since a browser
 * chat session has no name or username to read. confirm() below adds a
 * third step for exactly that gap: unless the chat is a linked Telegram
 * account with a real email already on file, it asks for a name and an
 * email or Telegram handle before ever forwarding anything — direct
 * follow-up specified name + email/handle rather than a phone number.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Escalation {

	private const PENDING_TTL = 10 * MINUTE_IN_SECONDS;

	private const AFFIRMATIVE = [ 'yes', 'y', 'yeah', 'yep', 'sure', 'please', 'send', 'send it', 'ok', 'okay' ];

	public static function has_pending( int $chat_id ): bool {
		return false !== get_transient( self::key( $chat_id ) );
	}

	public static function store_pending( int $chat_id, string $text ): void {
		set_transient( self::key( $chat_id ), $text, self::PENDING_TTL );
	}

	public static function clear( int $chat_id ): void {
		delete_transient( self::key( $chat_id ) );
		delete_transient( self::contact_key( $chat_id ) );
	}

	public static function is_affirmative( string $text ): bool {
		return in_array( strtolower( trim( $text, " \t\n\r\0\x0B." ) ), self::AFFIRMATIVE, true );
	}

	/** True once the customer has said yes and is now expected to reply with contact info next, rather than a new question — checked before command routing, same as has_pending() itself. */
	public static function has_pending_contact( int $chat_id ): bool {
		return false !== get_transient( self::contact_key( $chat_id ) );
	}

	/**
	 * Called once the customer confirms they want to escalate. A linked
	 * Telegram account (YeffoPrint_Telegram_Account_Link — the My Account
	 * "Connect Telegram" tab) already has a real email on file, so that
	 * case forwards immediately, same as before this revision; anyone
	 * else — including every website chat widget session, which has no
	 * linked account to look up — moves to a third step asking for
	 * contact info instead of forwarding blind.
	 *
	 * @return string The reply to send back: either the "sent" confirmation, or a prompt for contact info.
	 */
	public static function confirm( int $chat_id, array $from ): string {
		$message = (string) get_transient( self::key( $chat_id ) );
		delete_transient( self::key( $chat_id ) );

		if ( '' === $message ) {
			return __( "Sorry, that request expired — go ahead and ask your question again.", 'yeffoprint-core' );
		}

		$user_id = YeffoPrint_Telegram_Account_Link::get_user_id_for_chat( $chat_id );
		$user    = $user_id ? get_userdata( $user_id ) : false;

		if ( $user && is_email( $user->user_email ) ) {
			self::forward_message( $chat_id, $from, $message, $user->user_email );
			return __( "Sent! Our team will follow up with you. Anything else I can help with?", 'yeffoprint-core' );
		}

		set_transient( self::contact_key( $chat_id ), $message, self::PENDING_TTL );
		return __( "What's your name, and your email or Telegram handle? Our team will follow up there.", 'yeffoprint-core' );
	}

	/** The customer's reply to confirm()'s own prompt above — sent as-is, no format validation (a name plus an email or @handle, all in one message, is exactly what's asked for and expected here, since the owner reads this themselves). */
	public static function capture_contact( int $chat_id, array $from, string $contact ): string {
		$contact = trim( $contact );
		if ( '' === $contact ) {
			return __( "I didn't catch that — what's your name, and your email or Telegram handle?", 'yeffoprint-core' );
		}

		$message = (string) get_transient( self::contact_key( $chat_id ) );
		delete_transient( self::contact_key( $chat_id ) );

		if ( '' === $message ) {
			return __( "Sorry, that request expired — go ahead and ask your question again.", 'yeffoprint-core' );
		}

		self::forward_message( $chat_id, $from, $message, $contact );
		return __( "Got it — sent! Our team will follow up with you. Anything else I can help with?", 'yeffoprint-core' );
	}

	/** Emails the store (same recipient the Contact form uses) and pings the owner's Telegram. */
	private static function forward_message( int $chat_id, array $from, string $message, string $contact ): void {
		$who          = self::describe_sender( $chat_id, $from );
		$contact_line = '' !== $contact
			? sprintf( /* translators: %s: how the customer can be reached */ __( "Reach them at: %s\n\n", 'yeffoprint-core' ), $contact )
			: '';

		$recipient = get_option( YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_OPTION, YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_DEFAULT );
		if ( is_email( $recipient ) ) {
			wp_mail(
				$recipient,
				sprintf( /* translators: %s: sender description */ __( 'Telegram bot escalation — %s', 'yeffoprint-core' ), $who ),
				sprintf(
					/* translators: 1: sender description, 2: "Reach them at" line (or empty), 3: message text */
					__( "%1\$s couldn't be helped by the bot and asked to reach a person:\n\n%2\$s%3\$s", 'yeffoprint-core' ),
					$who,
					$contact_line,
					$message
				)
			);
		}

		YeffoPrint_Telegram_Admin_Alerts::notify( sprintf(
			/* translators: 1: sender description, 2: "Reach them at" line (or empty), 3: message text */
			__( "Telegram escalation from %1\$s:\n\n%2\$s%3\$s", 'yeffoprint-core' ),
			$who,
			$contact_line,
			$message
		) );
	}

	private static function describe_sender( int $chat_id, array $from ): string {
		$username = (string) ( $from['username'] ?? '' );
		if ( '' !== $username ) {
			return '@' . $username;
		}

		$name = trim( (string) ( $from['first_name'] ?? '' ) . ' ' . (string) ( $from['last_name'] ?? '' ) );
		return '' !== $name ? $name : sprintf( 'chat %d', $chat_id );
	}

	private static function key( int $chat_id ): string {
		return 'yp_telegram_escalate_' . $chat_id;
	}

	private static function contact_key( int $chat_id ): string {
		return 'yp_telegram_escalate_contact_' . $chat_id;
	}
}
