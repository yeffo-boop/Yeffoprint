<?php
/**
 * "Your order is waiting on payment" (customer) email — the one
 * reminder YeffoPrint_Payment_Reminder sends 24 hours after a
 * Venmo/Zelle order goes On hold unpaid. Same plumbing as the short
 * payment email (class-email-payment-balance.php), with its own
 * template; never registered as a Settings → Emails row.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Email_Payment_Reminder extends YeffoPrint_Email_Payment_Balance {

	public function __construct() {
		parent::__construct();

		$this->id            = 'yeffoprint_payment_reminder';
		$this->template_html = 'emails/customer-payment-reminder.php';
	}
}
