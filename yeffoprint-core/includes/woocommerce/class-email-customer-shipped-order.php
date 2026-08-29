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
		// This fires from inside WC_Order::status_transition(), itself
		// called synchronously from $order->save() — the same save that
		// just persisted the "shipped" status to the DB. WooCommerce's
		// own core emails only ever reach this point via
		// WC_Emails::send_transactional_email()'s own try/catch (see
		// class-wc-emails.php); hooking the raw woocommerce_order_status_
		// {status} action directly the way this class (and WC's own
		// per-email classes) do bypasses that wrapper entirely. Wrapping
		// the whole thing here means a bug in this email's own
		// content/template code can never again crash the order save
		// that's already succeeded by the time this runs — it can only
		// cost this one email, not the status change or the request.
		try {
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

			// Sends on any transition into "shipped" — including a manual
			// status change from the drawer's own dropdown, direct report:
			// "I also manually marked the order shipped and it didn't
			// send the email." An earlier version of this only sent when
			// YeffoPrint_Order_Tracking::get_shipments() found real label
			// data, to avoid an empty shipping-details box — but staff
			// marking an order shipped by hand (e.g. it went out without
			// a label purchased through this system) is a real "it
			// shipped" signal the customer should still hear about.
			// customer-shipped-order.php's own template already renders
			// the shipping-details box conditionally (`if ( $shipments )`),
			// so this degrades gracefully to a shipped notice with no
			// carrier/tracking section rather than an empty one.
			//
			// Direct report on a *second* order that DID already have real
			// tracking data attached: still no email, even though the
			// get_shipments() gate above was already removed by that
			// point. Every angle checked by hand (is_enabled() defaults,
			// get_recipient()/is_email() filtering, hook-registration
			// timing relative to WC_Emails::instance()) reads correct — so
			// rather than guess further blind, log every attempt's actual
			// runtime state. WooCommerce → Status → Logs, source
			// "yeffoprint-shipped-email", is where the next occurrence
			// shows up in a fresh log entry.
			if ( is_a( $this->object, \WC_Order::class ) ) {
				$result = $this->send_notification();
				wc_get_logger()->info(
					sprintf(
						'trigger() ran for order #%d — enabled: %s, recipient: %s, shipments: %d, sent: %s',
						$this->object->get_id(),
						$this->is_enabled() ? 'yes' : 'no',
						$this->get_recipient() ?: '(none)',
						count( YeffoPrint_Order_Tracking::get_shipments( $this->object ) ),
						$result ? 'yes' : 'no'
					),
					[ 'source' => 'yeffoprint-shipped-email' ]
				);
			} else {
				wc_get_logger()->info(
					sprintf( 'trigger() ran with no resolvable order (order_id: %s)', $order_id ),
					[ 'source' => 'yeffoprint-shipped-email' ]
				);
			}

			$this->restore_locale();
		} catch ( \Throwable $e ) {
			wc_get_logger()->error(
				'Shipped-order email failed to send: ' . $e->getMessage(),
				[ 'source' => 'yeffoprint-shipped-email', 'order_id' => $order_id ]
			);
		}
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
