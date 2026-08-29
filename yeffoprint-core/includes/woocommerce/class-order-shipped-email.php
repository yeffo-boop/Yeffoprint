<?php
/**
 * Registers YeffoPrint_Email_Customer_Shipped_Order with WooCommerce's
 * own email registry — the standard `woocommerce_email_classes` filter
 * every WC_Email subclass (core's own or a plugin's) has to go through
 * to actually be instantiated, sent, and get a Settings → Emails row
 * with its own enable/subject/heading fields. This class only wires
 * that filter; class-email-customer-shipped-order.php holds all the
 * actual email logic.
 *
 * class-email-customer-shipped-order.php is require_once'd lazily,
 * inside register_email() below, rather than eagerly in class-yeffoprint-
 * core.php's top-level includes() block — same reasoning as this
 * plugin's payment-gateway classes (see the woocommerce_payment_gateways
 * lazy-require comment there). That file's class declares
 * `extends \WC_Email` directly, a class-declaration dependency, not a
 * lazy reference inside a method body — so the file itself needs
 * WC_Email already resolvable the instant it's require_once'd. Eagerly
 * requiring it crashed the site with a fatal "Class WC_Email not found"
 * on any request where WooCommerce's classes weren't yet loaded at that
 * point in plugin bootstrap. `woocommerce_email_classes` only ever
 * fires from deep inside WooCommerce's own fully-booted email registry,
 * so requiring the file here can never fire too early.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Shipped_Email {

	public function __construct() {
		add_filter( 'woocommerce_email_classes', [ $this, 'register_email' ] );
	}

	/** @param array<string, \WC_Email> $email_classes */
	public function register_email( array $email_classes ): array {
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-email-customer-shipped-order.php';

		$email_classes['customer_shipped_order'] = new YeffoPrint_Email_Customer_Shipped_Order();
		return $email_classes;
	}
}
