<?php
/**
 * Overrides WC_Email_Customer_Completed_Order's own default subject/
 * heading — direct report: those defaults ("Your order from {site}
 * is on its way!" / "Good things are heading your way!") read like a
 * shipping notice, but on this store "Completed" means something far
 * more specific than it does on a typical WooCommerce site.
 * class-order-delivery-status.php's hourly sweep only ever moves an
 * order into this status once every one of its shipments shows
 * carrier-confirmed delivery (class-order-shipment-status.php's own
 * docblock: "'Delivered' ... moves it on to 'completed' once every one
 * of its shipments has arrived"). A customer reading "on its way" the
 * moment their package is already sitting on their porch has it
 * exactly backwards.
 *
 * Only overrides the two get_default_*() methods, so WooCommerce's own
 * settings-priority logic (get_option_or_transient('subject',
 * $this->get_default_subject())) is untouched — a subject or heading a
 * staff member deliberately types into Settings -> Emails -> Completed
 * order still wins over this default, exactly as it would for any
 * other WC_Email. Everything else (trigger hook, template resolution,
 * admin title/description) stays whatever the parent class already
 * does.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Email_Customer_Completed_Order extends \WC_Email_Customer_Completed_Order {

	public function get_default_subject() {
		return __( 'Your {site_title} order #{order_number} has been delivered!', 'yeffoprint-core' );
	}

	public function get_default_heading() {
		return __( 'Your order has arrived!', 'yeffoprint-core' );
	}
}
