<?php
/**
 * "We received part of your payment" (customer) email — sent by
 * YeffoPrint_Partial_Payments when a Venmo/Zelle payment comes in short.
 * Same approach as class-email-proof-notice.php: extends \WC_Email only
 * to reuse the branded header/footer/styles, never registered as a
 * Settings → Emails row.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Email_Payment_Balance extends \WC_Email {

	private array $args = [];

	public function __construct() {
		$this->id             = 'yeffoprint_payment_balance';
		$this->customer_email = true;
		$this->template_html  = 'emails/customer-payment-balance.php';

		parent::__construct();
	}

	public function send_notice( string $to, string $subject, array $args ): bool {
		$this->args      = $args;
		$this->recipient = $to;
		$this->object    = $args['order'] ?? null;

		$this->setup_locale();
		$sent = $this->send( $to, $subject, $this->get_content_html(), $this->get_headers(), $this->get_attachments() );
		$this->restore_locale();

		return (bool) $sent;
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array_merge( $this->args, [ 'email' => $this ] )
		);
	}
}
