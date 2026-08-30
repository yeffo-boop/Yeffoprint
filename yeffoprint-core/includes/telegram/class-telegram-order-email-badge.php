<?php
/**
 * "Chat with YeffoBot" callout on the new-order confirmation email —
 * direct request: "a badge added to the front page as well as to the
 * new order email customers receive." Scoped to just that one email
 * (WC_Email_Customer_Processing_Order's own id, 'customer_processing_
 * order') rather than every customer-facing order email the way
 * class-order-tracking.php's "Track your order" button is (shipping
 * updates repeating "come say hi to the bot" would read as spam; a
 * first-touch invite on the very first confirmation doesn't).
 *
 * No mascot icon here, unlike the homepage promo — inline SVG isn't
 * reliably supported across email clients (Outlook desktop's rendering
 * engine drops it entirely), so this reuses the plain text-only
 * `.yp-email-callout` box already established for payment instructions
 * (email-styles.php) rather than risk a broken image in some inboxes.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Order_Email_Badge {

	/** WC_Email_Customer_Processing_Order's own $this->id — the "we've received your order" email, not every order-lifecycle email. */
	private const EMAIL_ID = 'customer_processing_order';

	public function __construct() {
		add_action( 'woocommerce_email_after_order_table', [ $this, 'render' ], 20, 4 );
	}

	public function render( \WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email ): void {
		if ( $sent_to_admin || self::EMAIL_ID !== $email->id ) {
			return;
		}

		$url = YeffoPrint_Telegram_Settings::public_url();
		if ( ! $url ) {
			return;
		}

		if ( $plain_text ) {
			echo esc_html__( 'Questions? Chat with YeffoBot on Telegram:', 'yeffoprint-core' ) . ' ' . esc_url( $url ) . "\n\n";
			return;
		}

		printf(
			'<table class="yp-email-callout" cellpadding="0" cellspacing="0" role="presentation"><tr><td>' .
				'<span class="yp-email-callout-label">%1$s</span>' .
				'<p>%2$s <a href="%3$s">%4$s</a></p>' .
			'</td></tr></table>',
			esc_html__( 'Questions?', 'yeffoprint-core' ),
			esc_html__( 'YeffoBot can check your order status or answer sizing/shipping questions instantly on Telegram.', 'yeffoprint-core' ),
			esc_url( $url ),
			esc_html__( 'Chat with YeffoBot →', 'yeffoprint-core' )
		);
	}
}
