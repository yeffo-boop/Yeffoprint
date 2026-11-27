<?php
/**
 * When an order joined the work queue.
 *
 * Direct request: "the order timer for how long its been in a queue, when
 * it goes overdue, etc needs to go by the date the order is paid, not the
 * day the order is created." Venmo/Zelle and pay-link orders are often
 * created days before the money arrives, so counting from date_created
 * made them look late (or float to the top) the moment they were paid.
 *
 * The queue date is WooCommerce's date_paid, or date_created for an order
 * that has no paid date (unpaid, or an old order paid before WooCommerce
 * recorded one). Board and Today ages, the Late tag from the "Order due
 * date (days)" setting and the dashboard's overdue rows all use it.
 *
 * @package YeffoPrint_Core
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Queue_Date {

	/** date_paid, else date_created; null only for an order with neither. */
	public static function get( \WC_Order $order ): ?\WC_DateTime {
		return $order->get_date_paid() ?: $order->get_date_created();
	}

	/** The queue date as ISO 8601 for the admin app, or null. */
	public static function iso( \WC_Order $order ): ?string {
		$date = self::get( $order );
		return $date ? $date->date( 'c' ) : null;
	}
}
