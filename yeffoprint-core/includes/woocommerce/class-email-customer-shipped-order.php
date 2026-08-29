<?php
/**
 * "Your order has shipped!" customer email — direct request: "does the
 * customer get a shipped email?" No. There's no built-in WooCommerce
 * email for this (Shipped isn't a core status), so this adds one.
 *
 * Same shape as WooCommerce's own WC_Email_Customer_Processing_Order
 * (wp-content/plugins/woocommerce/includes/emails/
 * class-wc-email-customer-processing-order.php) — this class only
 * differs in *when* it fires and what its own template says; the
 * order table, customer details, and "Track your order" button below
 * it all come from the exact same shared hooks/classes every other
 * customer order email already uses (class-order-tracking.php's
 * render_tracking_button(), hooked to woocommerce_email_after_order_table
 * for every customer email, needs no changes here to also cover this
 * one).
 *
 * Trigger: `woocommerce_order_status_shipped` — WooCommerce's own
 * WC_Order::status_transition() fires `woocommerce_order_status_{$to}`
 * for *any* status a save transitions into, custom ones included, so
 * this needs no special wiring beyond the same pattern core emails use.
 * That means it fires correctly regardless of which status an order
 * was in before landing on "shipped" — whether that's the normal
 * Processing/In Production path, or class-order-shipment-status.php's
 * own completed-back-to-shipped redirect.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Email_Customer_Shipped_Order extends \WC_Email {

	public function __construct() {
		$this->id             = 'customer_shipped_order';
		$this->customer_email = true;

		$this->title       = __( 'Shipped order', 'yeffoprint-core' );
		$this->description = __( 'Sent to the customer the moment their order is marked Shipped — includes the carrier and a tracking link.', 'yeffoprint-core' );

		$this->template_html  = 'emails/customer-shipped-order.php';
		$this->template_plain = 'emails/plain/customer-shipped-order.php';
		$this->placeholders   = [
			'{order_date}'   => '',
			'{order_number}' => '',
		];

		add_action( 'woocommerce_order_status_' . YeffoPrint_Order_Shipment_Status::STATUS, [ $this, 'trigger' ], 10, 2 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your {site_title} order #{order_number} has shipped!', 'yeffoprint-core' );
	}

	public function get_default_heading() {
		return __( 'Your order has shipped!', 'yeffoprint-core' );
	}

	/**
	 * @param int            $order_id The order ID.
	 * @param \WC_Order|false $order Order object.
	 */
	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, \WC_Order::class ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, \WC_Order::class ) ) {
			$this->object                         = $order;
			$this->recipient                      = $this->object->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $this->object->get_date_created() );
			$this->placeholders['{order_number}'] = $this->object->get_order_number();
		}

		// Staff can pick "Shipped" from the status dropdown by hand,
		// with no real label ever purchased — this guards against
		// sending an email with an empty shipping-details box (no
		// carrier, no tracking number, nothing to show) in that case.
		// Same source of truth render_tracking_button() already checks.
		if ( is_a( $this->object, \WC_Order::class ) && YeffoPrint_Order_Tracking::get_shipments( $this->object ) ) {
			$this->send_notification();
		}

		$this->restore_locale();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			[
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
			]
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			[
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			]
		);
	}

	public function get_default_additional_content() {
		return __( 'Questions about your shipment? Just reply to this email.', 'yeffoprint-core' );
	}
}
