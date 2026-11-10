<?php
/**
 * "Your order is delayed" (customer) email, sent from the admin app by
 * YeffoPrint_Order_Delay_Email. Same plumbing as the short payment email
 * (class-email-payment-balance.php), with its own template; never
 * registered as a Settings → Emails row.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Email_Order_Delay extends YeffoPrint_Email_Payment_Balance {

	public function __construct() {
		parent::__construct();

		$this->id            = 'yeffoprint_order_delay';
		$this->template_html = 'emails/customer-order-delay.php';
	}
}
