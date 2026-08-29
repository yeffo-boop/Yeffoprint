<?php
/**
 * Registers YeffoPrint_Email_Customer_Shipped_Order with WooCommerce's
 * own email registry — the standard `woocommerce_email_classes` filter
 * every WC_Email subclass (core's own or a plugin's) has to go through
 * to actually be instantiated, sent, and get a Settings → Emails row
 * with its own enable/subject/heading fields. This class only wires
 * that filter; class-email-customer-shipped-order.php holds all the
 * actual email logic.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Shipped_Email {

	public function __construct() {
		add_filter( 'woocommerce_email_classes', [ $this, 'register_email' ] );
	}

	/** @param array<string, \WC_Email> $email_classes */
	public function register_email( array $email_classes ): array {
		$email_classes['customer_shipped_order'] = new YeffoPrint_Email_Customer_Shipped_Order();
		return $email_classes;
	}
}
