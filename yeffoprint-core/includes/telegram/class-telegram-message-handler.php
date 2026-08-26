<?php
/**
 * Pure command-routing/reply-text logic for an incoming Telegram
 * message — kept separate from class-telegram-webhook-controller.php
 * so the Telegram-plumbing (secret token, JSON update shape, actually
 * calling sendMessage) and the actual FAQ/order-status behavior don't
 * live in the same class.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Message_Handler {

	public function handle( string $text ): string {
		$text = trim( $text );

		if ( '' === $text ) {
			return $this->help_text();
		}

		$command = strtolower( strtok( $text, " \n" ) ?: '' );

		if ( in_array( $command, [ '/start', '/help' ], true ) ) {
			return $this->help_text();
		}

		if ( in_array( $command, [ '/order', '/track', '/status' ], true ) ) {
			return $this->order_status_reply( trim( substr( $text, strlen( $command ) ) ) );
		}

		if ( '/faq' === $command ) {
			return YeffoPrint_Telegram_Faq::topics_text();
		}

		// Free text: an order number + email typed without a command
		// still works, since that's the natural way to answer this
		// bot's own prompt for one.
		if ( self::extract_order_ref_and_email( $text ) ) {
			return $this->order_status_reply( $text );
		}

		$faq_answer = YeffoPrint_Telegram_Faq::match( $text );
		if ( $faq_answer ) {
			return $faq_answer;
		}

		return $this->fallback_text();
	}

	private function order_status_reply( string $args_text ): string {
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

		return YeffoPrint_Telegram_Order_Lookup::format_status( $order );
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
			"❓ Questions — ask about sizes, materials, shipping, the custom design fee, or accounts\n\n" .
			'Commands: /order, /faq, /help',
			'yeffoprint-core'
		);
	}

	private function fallback_text(): string {
		return sprintf(
			/* translators: %s: support email address */
			__( "Sorry, I didn't quite catch that. Try /help to see what I can do, or email %s for anything else.", 'yeffoprint-core' ),
			get_option( YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_OPTION, YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_DEFAULT )
		);
	}
}
