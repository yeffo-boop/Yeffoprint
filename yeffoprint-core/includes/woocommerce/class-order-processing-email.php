<?php
/**
 * Swaps WooCommerce's own WC_Email_Customer_Processing_Order for
 * class-email-customer-processing-order.php's subclass — same pattern
 * as class-order-completed-email.php, keyed by the real class name
 * (not the semantic email id) so this genuinely replaces the stock
 * instance instead of firing alongside it; see that file's own
 * docblock for the direct report that taught this lesson the hard way.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Processing_Email {

	public function __construct() {
		add_filter( 'woocommerce_email_classes', [ $this, 'register_email' ] );
	}

	public function register_email( array $email_classes ): array {
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-email-customer-processing-order.php';

		$email_classes['WC_Email_Customer_Processing_Order'] = new YeffoPrint_Email_Customer_Processing_Order();
		return $email_classes;
	}
}
