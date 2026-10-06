<?php
/**
 * Payments that come in short — direct request: "is there some way to
 * alert a customer that they accidentally underpaid if a zelle/venmo
 * comes in that's slightly short?"
 *
 * Venmo/Zelle money arrives outside the site, so an order can only be
 * told about it: either by the automated payment webhook
 * (class-payment-webhook-controller.php) or by staff entering what they
 * received in the admin app's order window (Record payment, class-admin-
 * order-controller.php). Both go through record() below, which keeps a
 * running total of what has come in on the order:
 *   - received ≥ order total → payment_complete(), exactly as before.
 *   - received < order total → the order stays unpaid, a note logs the
 *     payment, and the customer gets an email (class-email-payment-
 *     balance.php) saying what came in and how much is still owed,
 *     with the same "send to" handle / Venmo button as their original
 *     payment instructions.
 *
 * The balance is what the webhook matches the next payment against
 * (balance_due()), so the customer's follow-up for the remainder
 * resolves the order on its own.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Partial_Payments {

	/** Running total received so far (float, store currency). */
	public const RECEIVED_META = '_yp_amount_received';

	/** Log of each recorded payment: list of {amount, method, source, date}. */
	public const LOG_META = '_yp_payments_received';

	/** Statuses an order can still take a payment in. */
	private const OPEN_STATUSES = [ 'pending', 'on-hold', 'failed' ];

	/** Payment methods staff can pick when recording one by hand. */
	public const METHODS = [
		'venmo' => 'Venmo',
		'zelle' => 'Zelle',
		'cash'  => 'Cash',
		'other' => 'Other',
	];

	private const GATEWAY_IDS = [
		'venmo' => 'yeffoprint_venmo',
		'zelle' => 'yeffoprint_zelle',
	];

	public static function received( \WC_Order $order ): float {
		return round( (float) $order->get_meta( self::RECEIVED_META ), 2 );
	}

	public static function balance_due( \WC_Order $order ): float {
		return max( 0.0, round( (float) $order->get_total() - self::received( $order ), 2 ) );
	}

	/** @return array<int, array{amount: float, method: string, source: string, date: string}> */
	public static function log( \WC_Order $order ): array {
		$log = $order->get_meta( self::LOG_META );
		return is_array( $log ) ? array_values( $log ) : [];
	}

	/** True while the order is unpaid and can still have a payment recorded against it. */
	public static function can_record( \WC_Order $order ): bool {
		return ! $order->get_date_paid() && $order->has_status( self::OPEN_STATUSES );
	}

	/**
	 * Orders a Venmo/Zelle payment could belong to: On hold on that
	 * gateway (the normal "waiting for the money" state), plus Pending/
	 * Failed ones that already have part of their payment recorded.
	 *
	 * @return \WC_Order[] Oldest first.
	 */
	public static function open_orders_for_gateway( string $gateway_id ): array {
		$orders = wc_get_orders( [
			'status'         => self::OPEN_STATUSES,
			'payment_method' => $gateway_id,
			'limit'          => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
		] );

		return array_values( array_filter( $orders, static function ( $order ) {
			return $order instanceof \WC_Order
				&& ! $order->get_date_paid()
				&& ( $order->has_status( 'on-hold' ) || self::received( $order ) > 0 );
		} ) );
	}

	/**
	 * Adds a payment to the order's running total.
	 *
	 * @param string $method         Key of METHODS ('venmo', 'zelle', 'cash', 'other').
	 * @param string $source         Where it came from, for the order note ("payment webhook", "recorded by staff").
	 * @param bool   $email_customer Email the customer when it leaves a balance.
	 * @return array{status: string, received: float, balance: float} status is 'paid' or 'short'.
	 */
	public static function record( \WC_Order $order, float $amount, string $method, string $source, bool $email_customer = true ): array {
		$amount       = round( $amount, 2 );
		$method_label = self::METHODS[ $method ] ?? ucfirst( $method );

		$log   = self::log( $order );
		$log[] = [
			'amount' => $amount,
			'method' => $method,
			'source' => $source,
			'date'   => gmdate( 'c' ),
		];
		$received = round( self::received( $order ) + $amount, 2 );

		$order->update_meta_data( self::LOG_META, $log );
		$order->update_meta_data( self::RECEIVED_META, $received );

		// Lets the webhook match the follow-up payment, which only looks
		// at On hold orders on its own gateway.
		$gateway_id = self::GATEWAY_IDS[ $method ] ?? '';
		if ( '' !== $gateway_id && $order->get_payment_method() !== $gateway_id ) {
			$order->set_payment_method( $gateway_id );
			$order->set_payment_method_title( $method_label );
		}

		$total   = round( (float) $order->get_total(), 2 );
		$balance = max( 0.0, round( $total - $received, 2 ) );

		if ( $balance < 0.01 ) {
			$order->add_order_note( sprintf(
				/* translators: 1: amount, 2: Venmo/Zelle/Cash, 3: source, 4: total received */
				__( '%2$s payment of %1$s received (%3$s). %4$s received in total, order paid in full.', 'yeffoprint-core' ),
				self::plain_amount( $amount ),
				$method_label,
				$source,
				self::plain_amount( $received )
			) );
			if ( $received - $total >= 0.01 ) {
				$order->add_order_note( sprintf(
					/* translators: %s: amount overpaid */
					__( 'Customer overpaid by %s.', 'yeffoprint-core' ),
					self::plain_amount( $received - $total )
				) );
			}
			$order->save();

			// payment_complete() — not update_status() — so a paid custom
			// order still links to its production workflow (class-custom-
			// order-payment.php), same as the webhook always did.
			$order->payment_complete();

			return [ 'status' => 'paid', 'received' => $received, 'balance' => 0.0 ];
		}

		// Status is left alone: moving a Pending order to On hold would
		// also send WooCommerce's own on-hold email on top of the
		// balance email below. The webhook matches Pending orders with a
		// partial payment too (open_orders_for_gateway()).
		$order->add_order_note( sprintf(
			/* translators: 1: amount, 2: Venmo/Zelle/Cash, 3: source, 4: amount still owed, 5: whether the customer was emailed */
			__( '%2$s payment of %1$s received (%3$s), short of the order total. %4$s still due. %5$s', 'yeffoprint-core' ),
			self::plain_amount( $amount ),
			$method_label,
			$source,
			self::plain_amount( $balance ),
			$email_customer && $order->get_billing_email()
				? __( 'Customer emailed about the balance.', 'yeffoprint-core' )
				: __( 'Customer not emailed.', 'yeffoprint-core' )
		) );
		$order->save();

		if ( $email_customer ) {
			self::send_balance_email( $order, $amount, $method );
		}

		return [ 'status' => 'short', 'received' => $received, 'balance' => $balance ];
	}

	/** "We got $X, $Y is still due" — also used by Resend from the admin order window. */
	public static function send_balance_email( \WC_Order $order, float $last_amount, string $method ): bool {
		$to = $order->get_billing_email();
		if ( ! $to || ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return false;
		}

		// WC_Email only exists once WooCommerce's mailer is loaded,
		// hence the late require (same as class-email-proof-notice.php).
		WC()->mailer();
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-email-payment-balance.php';

		$balance  = self::balance_due( $order );
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
		$gateway  = $gateways[ self::GATEWAY_IDS[ $method ] ?? $order->get_payment_method() ] ?? null;

		$instructions = $gateway instanceof YeffoPrint_Manual_Payment_Gateway
			? $gateway->instructions_html( $order, $balance, 'yp-email-button' )
			: sprintf(
				/* translators: 1: amount owed, 2: order number */
				esc_html__( 'Please send the remaining %1$s the same way you paid, with your order number, %2$s, in the payment note. Reply to this email if you have any questions.', 'yeffoprint-core' ),
				wp_kses_post( wc_price( $balance ) ),
				esc_html( '#' . $order->get_order_number() )
			);

		$subject = sprintf(
			/* translators: 1: amount still owed, 2: order number */
			__( '%1$s still due on order #%2$s', 'yeffoprint-core' ),
			self::plain_amount( $balance ),
			$order->get_order_number()
		);

		return ( new YeffoPrint_Email_Payment_Balance() )->send_notice( $to, $subject, [
			'email_heading' => __( 'A little more to go', 'yeffoprint-core' ),
			'order'         => $order,
			'name'          => $order->get_billing_first_name() ?: __( 'there', 'yeffoprint-core' ),
			'method_label'  => self::METHODS[ $method ] ?? ucfirst( $method ),
			'last_amount'   => $last_amount,
			'received'      => self::received( $order ),
			'total'         => (float) $order->get_total(),
			'balance'       => $balance,
			'instructions'  => $instructions,
		] );
	}

	/** wc_price() without the HTML/entities, for order notes and plain-text emails. */
	public static function plain_amount( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}
}
