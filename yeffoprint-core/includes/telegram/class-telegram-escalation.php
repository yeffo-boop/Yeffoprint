<?php
/**
 * "Escalate to a human" — when the bot's FAQ/order-lookup logic can't
 * answer, this offers to relay the customer's own message to the
 * store instead of just telling them to email in. Two-step (offer,
 * then a confirmation reply) rather than forwarding every unmatched
 * message automatically — a stray "lol" or test message shouldn't
 * email the owner. State lives in a short-lived transient, the same
 * tool class-contact-controller.php's own rate limiter already uses
 * for per-request state that doesn't need a database column.
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
	}

	public static function is_affirmative( string $text ): bool {
		return in_array( strtolower( trim( $text, " \t\n\r\0\x0B." ) ), self::AFFIRMATIVE, true );
	}

	/** Emails the store (same recipient the Contact form uses) and pings the owner's Telegram, then clears the pending message. */
	public static function forward( int $chat_id, array $from ): void {
		$message = (string) get_transient( self::key( $chat_id ) );
		self::clear( $chat_id );

		if ( '' === $message ) {
			return;
		}

		$who = self::describe_sender( $chat_id, $from );

		$recipient = get_option( YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_OPTION, YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_DEFAULT );
		if ( is_email( $recipient ) ) {
			wp_mail(
				$recipient,
				sprintf( /* translators: %s: sender description */ __( 'Telegram bot escalation — %s', 'yeffoprint-core' ), $who ),
				sprintf(
					/* translators: 1: sender description, 2: message text */
					__( "%1\$s couldn't be helped by the bot and asked to reach a person:\n\n%2\$s", 'yeffoprint-core' ),
					$who,
					$message
				)
			);
		}

		YeffoPrint_Telegram_Admin_Alerts::notify( sprintf(
			/* translators: 1: sender description, 2: message text */
			__( "Telegram escalation from %1\$s:\n\n%2\$s", 'yeffoprint-core' ),
			$who,
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
}
