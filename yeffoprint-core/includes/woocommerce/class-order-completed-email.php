<?php
/**
 * Swaps WooCommerce's own WC_Email_Customer_Completed_Order for
 * class-email-customer-completed-order.php's subclass, via the same
 * `woocommerce_email_classes` filter every WC_Email (core's own or a
 * plugin's) goes through — same pattern as class-order-shipped-email.php,
 * just replacing a stock class instead of registering a brand new one.
 *
 * Lazily require_once'd inside register_email() for the same reason as
 * that file: the subclass declaration needs both WC_Email and
 * WC_Email_Customer_Completed_Order already loaded, and
 * `woocommerce_email_classes` only ever fires from deep inside
 * WooCommerce's fully-booted email registry, so this can never fire too
 * early.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Completed_Email {

	public function __construct() {
		add_filter( 'woocommerce_email_classes', [ $this, 'register_email' ] );
	}

	/** @param array<string, \WC_Email> $email_classes */
	public function register_email( array $email_classes ): array {
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-email-customer-completed-order.php';

		$email_classes['customer_completed_order'] = new YeffoPrint_Email_Customer_Completed_Order();
		return $email_classes;
	}
}
