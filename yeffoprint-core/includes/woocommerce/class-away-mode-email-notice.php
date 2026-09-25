<?php
/**
 * Away Mode's delay reminder on the order-confirmation family of
 * emails — direct request: "Maybe even include a reminder on the
 * confirmation email?" Same hook/priority idiom as
 * class-telegram-order-email-badge.php (woocommerce_email_before_order_table,
 * right after the greeting, on every email below), but at priority 4 so
 * this lands above that YeffoBot card and the order-status stepper —
 * this is the most operationally important thing in the email, so it
 * reads first.
 *
 * Shown on every email that can fire before an order actually ships —
 * a customer paying by card lands on customer_processing_order, a
 * manual-payment order (Venmo/Zelle/NOWPayments) sits on
 * customer_on_hold_order until confirmed, and customer_invoice is the
 * payment-reminder email for either of those. Deliberately not shown
 * on customer_shipped_order/customer_completed_order — those only ever
 * fire once an order is already moving/done, past the point this
 * notice is useful for.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Away_Mode_Email_Notice {

	/** @var string[] WC_Email ids this notice appears on. */
	private const EMAIL_IDS = [
		'customer_processing_order',
		'customer_on_hold_order',
		'customer_invoice',
	];

	public function __construct() {
		add_action( 'woocommerce_email_before_order_table', [ $this, 'render' ], 4, 4 );
	}

	public function render( \WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email ): void {
		if ( $sent_to_admin || ! in_array( $email->id, self::EMAIL_IDS, true ) ) {
			return;
		}

		$away = function_exists( 'yeffoprint_core_away_mode' ) ? yeffoprint_core_away_mode() : null;
		if ( ! $away ) {
			return;
		}

		if ( $plain_text ) {
			echo esc_html__( 'A quick note on timing:', 'yeffoprint-core' ) . "\n";
			printf(
				/* translators: %s: the date production resumes, e.g. "March 18, 2026" */
				esc_html__( "We’re currently away and resuming production on %s. Your order is confirmed and queued — we’ll start on it as soon as we’re back, and you’ll get the usual updates once it ships.\n\n", 'yeffoprint-core' ),
				esc_html( $away['return_label'] )
			);
			return;
		}

		printf(
			'<table class="yp-away-email-notice" role="presentation" cellpadding="0" cellspacing="0" width="100%%"><tr><td>' .
				'<span class="yp-away-email-notice-eyebrow">%1$s</span>' .
				'<p class="yp-away-email-notice-body">%2$s</p>' .
			'</td></tr></table>',
			esc_html__( 'A quick note on timing', 'yeffoprint-core' ),
			sprintf(
				/* translators: %s: the date production resumes, e.g. "March 18, 2026" */
				esc_html__( 'We’re currently away and resuming production on %s. Your order is confirmed and queued — we’ll start on it as soon as we’re back, and you’ll get the usual updates once it ships.', 'yeffoprint-core' ),
				esc_html( $away['return_label'] )
			)
		);
	}
}
