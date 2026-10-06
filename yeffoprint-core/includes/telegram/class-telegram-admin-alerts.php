<?php
/**
 * Pushes real-time alerts to the store owner's own Telegram chat — a
 * new order (or custom design request) getting paid, a Contact form
 * submission, or a Web Design quote request — as a faster companion to
 * the existing email notifications, not a replacement for them. Hooks
 * the same stable events those email paths already fire on
 * (`woocommerce_payment_complete`, `yeffoprint_contact_form_submitted`
 * on class-contact-controller.php, `yeffoprint_web_design_quote_submitted`
 * on class-web-design-quote-controller.php) rather than duplicating
 * their trigger logic.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Admin_Alerts {

	public function __construct() {
		add_action( 'yeffoprint_contact_form_submitted', [ $this, 'on_contact_form_submitted' ], 10, 5 );
		add_action( 'yeffoprint_web_design_quote_submitted', [ $this, 'on_web_design_quote_submitted' ] );
		add_action( 'woocommerce_payment_complete', [ $this, 'on_payment_complete' ] );
		add_action( 'woocommerce_order_status_pending_to_on-hold', [ $this, 'on_awaiting_payment' ], 10, 2 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_possible_dispute' ], 10, 4 );
	}

	public function on_contact_form_submitted( string $name, string $email, string $method, string $handle, string $message ): void {
		self::notify( sprintf(
			/* translators: 1: sender name, 2: sender email, 3: message text */
			__( "New contact form message\n\nFrom: %1\$s <%2\$s>\n\n%3\$s", 'yeffoprint-core' ),
			$name,
			$email,
			$message
		), [ 'section' => 'messages' ] );
	}

	/**
	 * @param array<string,string> $answers Same shape class-web-design-
	 *   quote-controller.php::submit() builds. Direct report: this used
	 *   to hand-format just business/name/email/package, dropping every
	 *   other answer the email notification already included (phone,
	 *   what they sell, hosting/domain status, timeline, etc.) — now
	 *   reuses that same controller's format_answers() so both channels
	 *   show the identical full answer set and can't drift apart again.
	 */
	public function on_web_design_quote_submitted( array $answers ): void {
		$lines = array_merge(
			[ __( 'New web design quote request', 'yeffoprint-core' ), '' ],
			YeffoPrint_Web_Design_Quote_Controller::format_answers( $answers )
		);

		self::notify( implode( "\n", $lines ), [ 'section' => 'messages' ] );
	}

	public function on_payment_complete( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// An express order's own "⚡ EXPRESS order paid" alert (class-
		// telegram-express-alerts.php, sent when it reached Processing just
		// before this hook) already covers it; a second alert under the
		// same phone notification tag would replace the one with the
		// Acknowledge button.
		if ( '' !== (string) $order->get_meta( YeffoPrint_Telegram_Express_Alerts::ALERT_COUNT_META ) ) {
			return;
		}

		$heading = $this->has_custom_design( $order )
			/* translators: 1: order number, 2: formatted order total */
			? __( 'New custom design request paid: %1$s (%2$s)', 'yeffoprint-core' )
			/* translators: 1: order number, 2: formatted order total */
			: __( 'New order paid: %1$s (%2$s)', 'yeffoprint-core' );

		$this->notify_order( $order, $heading );
	}

	/**
	 * Direct request: "uninstall the [WooCommerce] app ... and just use
	 * the yeffodesign dashboard" — the Woo app pinged on every new order,
	 * but a Venmo, Zelle or crypto checkout only parks the order on hold
	 * (class-manual-payment-gateway.php, class-nowpayments-gateway.php), so woocommerce_payment_complete
	 * doesn't fire until the money is matched. This sends the alert as
	 * soon as the order is placed; the paid alert follows later under the
	 * same phone notification tag, so it replaces this one.
	 */
	public function on_awaiting_payment( int $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! in_array( $order->get_payment_method(), [ 'yeffoprint_venmo', 'yeffoprint_zelle', 'yeffoprint_nowpayments' ], true ) ) {
			return;
		}

		/* translators: 1: order number, 2: formatted order total, 3: payment method title, e.g. "Venmo" */
		$heading = __( 'New order: %1$s (%2$s), waiting on %3$s payment', 'yeffoprint-core' );

		$this->notify_order( $order, $heading, $order->get_payment_method_title() ?: __( 'their', 'yeffoprint-core' ) );
	}

	/**
	 * WooPayments puts a paid order back On hold when the customer's bank
	 * opens a dispute. Alerts with a link to the dashboard's Disputes
	 * screen, where it can be answered before the deadline.
	 */
	public function on_possible_dispute( int $order_id, string $from, string $to, $order = null ): void {
		if ( 'on-hold' !== $to || ! in_array( $from, [ 'processing', 'in-design', 'in-production', 'shipped', 'completed' ], true ) ) {
			return;
		}
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || 'woocommerce_payments' !== $order->get_payment_method() ) {
			return;
		}

		delete_transient( 'yeffoprint_admin_disputes' );

		self::notify(
			sprintf(
				/* translators: 1: order number, 2: formatted order total */
				__( 'Card payment disputed: %1$s (%2$s)', 'yeffoprint-core' ),
				$order->get_order_number(),
				YeffoPrint_Telegram_Order_Lookup::plain_total( $order )
			) . "\n" . trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) . "\n" . __( 'Answer it in the dashboard under Orders > Disputes before the deadline.', 'yeffoprint-core' ),
			[ 'section' => 'disputes' ]
		);
	}

	private function has_custom_design( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta( '_yp_custom_order_id' ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param string $heading sprintf() format: 1 = order number, 2 = order total, then $extra in order. */
	private function notify_order( \WC_Order $order, string $heading, string ...$extra ): void {
		$item_lines = [];
		foreach ( $order->get_items() as $item ) {
			$item_lines[] = sprintf( '• %1$s × %2$d', $item->get_name(), $item->get_quantity() );
		}

		// YeffoPrint_Telegram_Order_Lookup::plain_total() — not a bare
		// wp_strip_all_tags() — decodes WooCommerce's HTML-entity
		// currency symbol (e.g. USD's "&#36;") back into a real "$" for
		// this plain-text message; see that method's own docblock.
		$heading = sprintf( $heading, $order->get_order_number(), YeffoPrint_Telegram_Order_Lookup::plain_total( $order ), ...$extra );
		$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		self::notify( implode( "\n", array_merge( [ $heading, $name, '' ], $item_lines ) ), [ 'order_id' => $order->get_id() ] );
	}

	/**
	 * @param array{order_id?:int,section?:string} $context Where a phone
	 *   alert should open in the new admin app (class-admin-push.php),
	 *   which hears every owner alert through `yeffoprint_owner_alert`
	 *   whether or not Telegram is set up.
	 */
	public static function notify( string $text, array $context = [] ): void {
		do_action( 'yeffoprint_owner_alert', $text, $context );

		$chat_id = self::telegram_chat_id();
		if ( ! $chat_id ) {
			return;
		}

		( new YeffoPrint_Telegram_Client( YeffoPrint_Telegram_Settings::get_bot_token() ) )->send_message( $chat_id, $text );
	}

	/**
	 * The owner's chat when owner alerts should also go to Telegram, else
	 * 0. Off unless Settings > Integrations > "Also send my alerts to
	 * Telegram" is ticked (TELEGRAM_OWNER_ALERTS_OPTION), so the phone
	 * push from the admin app is the only copy. Every owner alert sent to
	 * Telegram checks this: notify() above, express reminders, abandoned
	 * carts and tracker feedback.
	 */
	public static function telegram_chat_id(): int {
		if ( ! get_option( YeffoPrint_Admin_Menu::TELEGRAM_OWNER_ALERTS_OPTION, false ) ) {
			return 0;
		}
		$chat_id = (int) get_option( YeffoPrint_Admin_Menu::TELEGRAM_ADMIN_CHAT_ID_OPTION, 0 );
		if ( ! $chat_id || '' === YeffoPrint_Telegram_Settings::get_bot_token() || ! YeffoPrint_Telegram_Settings::is_enabled() ) {
			return 0;
		}
		return $chat_id;
	}
}
