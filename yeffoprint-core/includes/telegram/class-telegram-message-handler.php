<?php
/**
 * Pure command-routing/reply-text logic for an incoming Telegram
 * message — kept separate from class-telegram-webhook-controller.php
 * so the Telegram-plumbing (secret token, JSON update shape, actually
 * calling sendMessage) and the actual FAQ/order-status/escalation
 * behavior don't live in the same class.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Message_Handler {

	/** @param array $message The Telegram "message" object as decoded from the webhook update — chat.id, text, and (optionally) from.username/first_name/last_name. */
	public function handle( array $message ): string {
		$chat_id = (int) ( $message['chat']['id'] ?? 0 );
		$text    = trim( (string) ( $message['text'] ?? '' ) );
		$from    = is_array( $message['from'] ?? null ) ? $message['from'] : [];

		if ( YeffoPrint_Telegram_Escalation::has_pending( $chat_id ) ) {
			return $this->handle_pending_escalation( $chat_id, $text, $from );
		}

		// A tapped "Request changes" button (class-telegram-callback-
		// handler.php) leaves this chat waiting for the customer's next
		// message to use as their change-request notes — checked before
		// any command routing, same "pending state short-circuits
		// everything else" shape as the escalation check above, just with
		// the payload arriving *after* the prompt rather than before it.
		if ( YeffoPrint_Telegram_Callback_Handler::has_pending_reject( $chat_id ) ) {
			return $this->handle_pending_reject( $chat_id, $text );
		}

		if ( '' === $text ) {
			return $this->help_text();
		}

		$command = strtolower( strtok( $text, " \n" ) ?: '' );

		// `/start link_CODE` — the deep-link button on My Account's
		// "Connect Telegram" tab (class-account-endpoints.php) opens the
		// chat and taps Telegram's own "Start" button for the customer,
		// which delivers exactly this as the chat's first message. Typed
		// `/link CODE` (below) is the same flow for someone typing it by
		// hand instead of tapping the button.
		if ( '/start' === $command ) {
			$payload = trim( substr( $text, strlen( $command ) ) );
			if ( 0 === strpos( $payload, 'link_' ) ) {
				return $this->link_account_reply( substr( $payload, 5 ), $chat_id );
			}
			return $this->help_text();
		}

		if ( '/help' === $command ) {
			return $this->help_text();
		}

		if ( '/link' === $command ) {
			return $this->link_account_reply( trim( substr( $text, strlen( $command ) ) ), $chat_id );
		}

		if ( in_array( $command, [ '/whoami', '/id' ], true ) ) {
			return sprintf( /* translators: %d: this Telegram chat's numeric id */ __( "This chat's ID is %d.", 'yeffoprint-core' ), $chat_id );
		}

		if ( in_array( $command, [ '/order', '/track', '/status' ], true ) ) {
			return $this->order_status_reply( trim( substr( $text, strlen( $command ) ) ), $chat_id );
		}

		if ( '/reorder' === $command ) {
			return $this->reorder_reply( trim( substr( $text, strlen( $command ) ) ) );
		}

		if ( '/faq' === $command ) {
			return YeffoPrint_Telegram_Faq::topics_text();
		}

		// Free text: an order number + email typed without a command
		// still works, since that's the natural way to answer this
		// bot's own prompt for one.
		if ( self::extract_order_ref_and_email( $text ) ) {
			return $this->order_status_reply( $text, $chat_id );
		}

		$faq_answer = YeffoPrint_Telegram_Faq::match( $text );
		if ( $faq_answer ) {
			return $faq_answer;
		}

		YeffoPrint_Telegram_Escalation::store_pending( $chat_id, $text );
		return $this->fallback_text();
	}

	private function handle_pending_escalation( int $chat_id, string $text, array $from ): string {
		if ( YeffoPrint_Telegram_Escalation::is_affirmative( $text ) ) {
			YeffoPrint_Telegram_Escalation::forward( $chat_id, $from );
			return __( "Sent! Our team will follow up with you here. Anything else I can help with?", 'yeffoprint-core' );
		}

		YeffoPrint_Telegram_Escalation::clear( $chat_id );
		return __( "No problem — I won't send that. Try /help or /faq, or ask me something else.", 'yeffoprint-core' );
	}

	private function handle_pending_reject( int $chat_id, string $text ): string {
		$custom_order_id = YeffoPrint_Telegram_Callback_Handler::consume_pending_reject( $chat_id );

		if ( ! $custom_order_id || '' === trim( $text ) ) {
			return __( "No changes were recorded — send a message describing what you'd like changed, or /help for other commands.", 'yeffoprint-core' );
		}

		return YeffoPrint_Telegram_Callback_Handler::apply_reject( $custom_order_id, $text );
	}

	/**
	 * Completes an account link (class-telegram-account-link.php) started
	 * from My Account's "Connect Telegram" tab — via either a typed
	 * `/link CODE` or a `/start link_CODE` deep link, both routed here.
	 */
	private function link_account_reply( string $code, int $chat_id ): string {
		if ( '' === trim( $code ) ) {
			return __( "To connect your account, open My Account → Connect Telegram on the site and follow the instructions there.", 'yeffoprint-core' );
		}

		$user_id = YeffoPrint_Telegram_Account_Link::consume_code( $code );

		if ( ! $user_id ) {
			return __( "That code has expired or isn't valid. Head back to My Account → Connect Telegram on the site to get a fresh one.", 'yeffoprint-core' );
		}

		YeffoPrint_Telegram_Account_Link::link( $user_id, $chat_id );

		return __( "You're connected! I'll message you here about your orders — including a chance to approve or request changes on a proof right from this chat.", 'yeffoprint-core' );
	}

	private function order_status_reply( string $args_text, int $chat_id ): string {
		$parsed = self::extract_order_ref_and_email( $args_text );

		if ( ! $parsed ) {
			return __( "To check an order, send your order number and the email you used at checkout, like:\nYP-1042 jane@example.com", 'yeffoprint-core' );
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return __( "Order lookup isn't available right now — please try again shortly.", 'yeffoprint-core' );
		}

		$order = YeffoPrint_Telegram_Order_Lookup::find( $parsed['order_ref'], $parsed['email'] );

		if ( ! $order ) {
			return __( "I couldn't find an order matching that number and email. Double-check both and try again, or use /help.", 'yeffoprint-core' );
		}

		$reply = YeffoPrint_Telegram_Order_Lookup::format_status( $order );

		if ( $chat_id && YeffoPrint_Telegram_Order_Notifications::link( $order, $chat_id ) ) {
			$reply .= "\n\n" . __( "I'll message you here when your proof is ready or your order ships.", 'yeffoprint-core' );
		}

		return $reply;
	}

	private function reorder_reply( string $args_text ): string {
		$parsed = self::extract_order_ref_and_email( $args_text );

		if ( ! $parsed ) {
			return __( "To get a reorder link, send your order number and the email you used at checkout, like:\n/reorder YP-1042 jane@example.com", 'yeffoprint-core' );
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return __( "Reorder links aren't available right now — please try again shortly.", 'yeffoprint-core' );
		}

		$order = YeffoPrint_Telegram_Order_Lookup::find( $parsed['order_ref'], $parsed['email'] );

		if ( ! $order ) {
			return __( "I couldn't find an order matching that number and email. Double-check both and try again.", 'yeffoprint-core' );
		}

		$links = YeffoPrint_Reorder::urls_for_order( $order );

		if ( ! $links ) {
			return __( "Nothing in that order can be reordered directly — head to the site to start a new design.", 'yeffoprint-core' );
		}

		$lines = [ __( 'Tap a link to reorder that design (review and edit before checkout):', 'yeffoprint-core' ) ];
		foreach ( $links as $link ) {
			$lines[] = sprintf( '%1$s — %2$s', $link['label'], $link['url'] );
		}

		return implode( "\n", $lines );
	}

	/** @return array{order_ref:string,email:string}|null */
	private static function extract_order_ref_and_email( string $text ): ?array {
		if ( ! preg_match( '/([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $text, $email_match ) ) {
			return null;
		}

		if ( ! preg_match( '/(?:YP-?)?(\d{1,10})/i', $text, $number_match ) ) {
			return null;
		}

		return [
			'order_ref' => $number_match[1],
			'email'     => $email_match[1],
		];
	}

	private function help_text(): string {
		return __(
			"Hi! I'm the YeffoPrint order & FAQ bot. I can help with:\n\n" .
			"📦 Order status — send your order number and checkout email, e.g. \"YP-1042 jane@example.com\"\n" .
			"🔁 Reorder — /reorder plus your order number and email\n" .
			"🔗 Connect your account — /link plus the code from My Account → Connect Telegram, so I can message you directly and let you approve proofs right here\n" .
			"❓ Questions — ask about sizes, materials, shipping, the custom design fee, or accounts\n\n" .
			'Commands: /order, /reorder, /link, /faq, /help',
			'yeffoprint-core'
		);
	}

	private function fallback_text(): string {
		return sprintf(
			/* translators: %s: support email address */
			__( "Sorry, I didn't quite catch that. Want me to send your message to our team? Reply \"yes\" to send it, or try /help / /faq.\n\n(You can also email %s directly.)", 'yeffoprint-core' ),
			get_option( YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_OPTION, YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_DEFAULT )
		);
	}
}
