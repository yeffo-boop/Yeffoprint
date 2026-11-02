<?php
/**
 * One payment reminder email for unpaid Venmo/Zelle orders. Direct
 * request: "Yes but just one reminder at 24 hours. No second reminder."
 *
 * A Venmo/Zelle order sits On hold until the payment is matched
 * (class-manual-payment-gateway.php, class-payment-webhook-controller.php).
 * The customer only ever got the on-hold email at checkout, so a
 * forgotten payment just sat there. An hourly sweep, same pattern as
 * class-review-request.php, emails each such order once, 24 hours after
 * it went On hold, with the amount owed and the usual "send it to …,
 * include your order number" instructions (plus the Venmo button/QR).
 *
 * The clock starts at AWAITING_SINCE_META, stamped whenever a Venmo/Zelle
 * order moves to On hold (checkout, or a customer picking Venmo/Zelle on
 * an admin-created order's pay link). Orders from before this existed
 * fall back to their created date. Either way an order is only emailed
 * within GRACE_HOURS of becoming due, so a late sweep or switching the
 * reminder on never mails old orders.
 *
 * Skipped when the order has already had part of a payment recorded
 * (the short payment email, class-partial-payments.php, already told
 * the customer the balance) or has no billing email. ORDER_META records
 * "sent" or the skip reason before the email goes out, so a mail outage
 * never loops and there's never a second reminder.
 *
 * On/off lives in the admin app's Settings › Store › Payment reminders.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Payment_Reminder {

	public const ENABLED_OPTION = 'yeffoprint_payment_reminder_enabled';
	public const ORDER_META     = '_yp_payment_reminder';
	public const AWAITING_SINCE_META = '_yp_awaiting_payment_since';

	public const DELAY_HOURS = 24;

	private const HOOK        = 'yeffoprint_payment_reminder_sweep';
	private const GRACE_HOURS = 48;
	private const BATCH       = 50;

	/** The two "send it yourself" gateways (class-venmo-gateway.php, class-zelle-gateway.php). */
	private const GATEWAY_IDS = [ 'yeffoprint_venmo', 'yeffoprint_zelle' ];

	public function __construct() {
		add_action( self::HOOK, [ $this, 'sweep' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
		add_action( 'woocommerce_order_status_on-hold', [ $this, 'stamp_awaiting_since' ], 10, 2 );
	}

	public static function enabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION, 1 );
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/** Starts the 24-hour clock the moment a Venmo/Zelle order goes On hold. */
	public function stamp_awaiting_since( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! in_array( $order->get_payment_method(), self::GATEWAY_IDS, true ) ) {
			return;
		}
		if ( $order->get_meta( self::AWAITING_SINCE_META ) ) {
			return; // Keep the first time it went On hold; a later bounce back doesn't restart the clock.
		}
		$order->update_meta_data( self::AWAITING_SINCE_META, time() );
		$order->save_meta_data();
	}

	public function sweep(): void {
		if ( ! self::enabled() ) {
			return;
		}

		$now   = time();
		$sent  = 0;
		// Pay-link orders can be created well before the customer picks
		// Venmo/Zelle, so this bound is generous; is_due() decides.
		$since = $now - 30 * DAY_IN_SECONDS;

		foreach ( self::GATEWAY_IDS as $gateway_id ) {
			$order_ids = wc_get_orders( [
				'status'         => 'on-hold',
				'type'           => 'shop_order',
				'payment_method' => $gateway_id,
				'date_created'   => '>' . $since,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'limit'          => -1,
				'return'         => 'ids',
			] );

			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( self::ORDER_META ) ) {
					continue;
				}
				if ( ! self::is_due( $order, $now ) ) {
					continue;
				}
				if ( self::maybe_send( $order ) && ++$sent >= self::BATCH ) {
					return; // The rest catch up on the next hourly run.
				}
			}
		}
	}

	/** True between DELAY_HOURS and DELAY_HOURS + GRACE_HOURS after the order went On hold. */
	private static function is_due( \WC_Order $order, int $now ): bool {
		$start = (int) $order->get_meta( self::AWAITING_SINCE_META );
		if ( ! $start && $order->get_date_created() ) {
			$start = $order->get_date_created()->getTimestamp();
		}
		if ( ! $start ) {
			return false;
		}

		$due = $start + self::DELAY_HOURS * HOUR_IN_SECONDS;
		return $now >= $due && $now < $due + self::GRACE_HOURS * HOUR_IN_SECONDS;
	}

	private static function maybe_send( \WC_Order $order ): bool {
		$skip = self::skip_reason( $order );
		if ( '' !== $skip ) {
			self::mark( $order, $skip );
			return false;
		}

		self::mark( $order, 'sent' );
		$sent = self::send_email( $order );
		$order->add_order_note( $sent
			? __( 'Payment reminder email sent (24 hours unpaid).', 'yeffoprint-core' )
			: __( 'Payment reminder email could not be sent.', 'yeffoprint-core' ) );

		return $sent;
	}

	private static function skip_reason( \WC_Order $order ): string {
		if ( ! is_email( $order->get_billing_email() ) ) {
			return 'no_email';
		}
		if ( YeffoPrint_Partial_Payments::received( $order ) > 0 ) {
			return 'part_paid';
		}
		if ( YeffoPrint_Partial_Payments::balance_due( $order ) <= 0 ) {
			return 'nothing_due';
		}
		return '';
	}

	private static function mark( \WC_Order $order, string $value ): void {
		$order->update_meta_data( self::ORDER_META, $value );
		$order->save_meta_data();
	}

	public static function send_email( \WC_Order $order ): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return false;
		}

		// WC_Email only exists once WooCommerce's mailer is loaded,
		// hence the late require (same as class-partial-payments.php).
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-email-payment-balance.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-email-payment-reminder.php';

		$balance  = YeffoPrint_Partial_Payments::balance_due( $order );
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
		$gateway  = $gateways[ $order->get_payment_method() ] ?? null;
		if ( ! $gateway instanceof YeffoPrint_Manual_Payment_Gateway ) {
			return false;
		}

		$method_label = $order->get_payment_method_title() ?: $gateway->get_title();

		$subject = sprintf(
			/* translators: 1: order number, 2: Venmo/Zelle */
			__( 'Reminder: order #%1$s is waiting on your %2$s payment', 'yeffoprint-core' ),
			$order->get_order_number(),
			$method_label
		);

		return ( new YeffoPrint_Email_Payment_Reminder() )->send_notice( $order->get_billing_email(), $subject, [
			'email_heading' => __( 'Your order is waiting on payment', 'yeffoprint-core' ),
			'order'         => $order,
			'name'          => $order->get_billing_first_name() ?: __( 'there', 'yeffoprint-core' ),
			'method_label'  => $method_label,
			'balance'       => $balance,
			'instructions'  => $gateway->instructions_html( $order, $balance, 'yp-email-button' ),
		] );
	}
}
