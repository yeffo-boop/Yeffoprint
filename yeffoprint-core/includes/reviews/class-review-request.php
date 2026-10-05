<?php
/**
 * Follow-up review request email. Direct request: "Can we setup an
 * automated email asking for a review on a customers most recent order
 * that sends out like 2 days after their order shows delivered?"
 *
 * "Delivered" on this store is the Completed status
 * (class-order-delivery-status.php only completes an order once every
 * package shows delivered), so the clock starts at the order's
 * date_completed. An hourly sweep, same pattern as
 * class-proof-reminder-scheduler.php, picks up orders whose delay has
 * passed and sends each one at most once (`_yp_review_request` on the
 * order records "sent" or why it was skipped, before the email goes
 * out, so a mail outage never loops).
 *
 * Skipped when:
 * - the order isn't reviewable (class-order-reviews.php: not Completed,
 *   web design, nothing to review) or already has a review;
 * - the same customer has a newer delivered order, so they're only
 *   ever asked about their most recent one (the newer order gets its
 *   own email when its delay passes);
 * - the order was delivered more than GRACE_DAYS past the delay, so a
 *   late sweep or switching the email on never mails old orders.
 *
 * On/off and the delay live with the other review settings in the
 * admin app's Sales > Reviews screen.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Review_Request {

	public const ORDER_META = '_yp_review_request';

	private const HOOK       = 'yeffoprint_review_request_sweep';
	private const GRACE_DAYS = 3;
	private const BATCH      = 50;

	public function __construct() {
		add_action( self::HOOK, [ $this, 'sweep' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
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

	public function sweep(): void {
		$settings = YeffoPrint_Order_Reviews::settings();
		if ( ! $settings['enabled'] || ! $settings['request_email'] ) {
			return;
		}

		$due_by   = time() - $settings['request_days'] * DAY_IN_SECONDS;
		$too_old  = $due_by - self::GRACE_DAYS * DAY_IN_SECONDS;
		$order_ids = wc_get_orders( [
			'status'         => 'completed',
			'type'           => 'shop_order',
			'date_completed' => $too_old . '...' . $due_by,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'limit'          => -1,
			'return'         => 'ids',
		] );

		$sent = 0;
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( self::ORDER_META ) ) {
				continue;
			}
			if ( self::maybe_send( $order ) && ++$sent >= self::BATCH ) {
				return; // The rest catch up on the next hourly run.
			}
		}
	}

	/** Sends the request for one due order, or records why it was skipped. */
	private static function maybe_send( \WC_Order $order ): bool {
		$skip = self::skip_reason( $order );
		if ( '' !== $skip ) {
			self::mark( $order, $skip );
			return false;
		}

		self::mark( $order, 'sent' );
		$sent = self::send_email( $order );
		$order->add_order_note( $sent
			? __( 'Review request email sent.', 'yeffoprint-core' )
			: __( 'Review request email could not be sent.', 'yeffoprint-core' ) );

		return $sent;
	}

	private static function skip_reason( \WC_Order $order ): string {
		if ( ! YeffoPrint_Order_Reviews::is_reviewable( $order ) ) {
			return 'not_reviewable';
		}
		if ( YeffoPrint_Order_Reviews::get_review( $order ) ) {
			return 'reviewed';
		}
		if ( ! is_email( $order->get_billing_email() ) ) {
			return 'no_email';
		}
		if ( self::has_newer_delivered_order( $order ) ) {
			return 'newer_order';
		}
		return '';
	}

	private static function has_newer_delivered_order( \WC_Order $order ): bool {
		$created = $order->get_date_created();
		if ( ! $created ) {
			return false;
		}

		$newer = wc_get_orders( [
			'status'        => 'completed',
			'type'          => 'shop_order',
			'billing_email' => $order->get_billing_email(),
			'date_created'  => '>' . $created->getTimestamp(),
			'exclude'       => [ $order->get_id() ],
			'limit'         => 5,
		] );

		foreach ( $newer as $other ) {
			if ( $other instanceof \WC_Order && YeffoPrint_Order_Reviews::is_reviewable( $other ) ) {
				return true;
			}
		}
		return false;
	}

	private static function mark( \WC_Order $order, string $value ): void {
		$order->update_meta_data( self::ORDER_META, $value );
		$order->save_meta_data();
	}

	private static function send_email( \WC_Order $order ): bool {
		WC()->mailer();
		require_once YEFFOPRINT_CORE_PATH . 'includes/reviews/class-email-review-request.php';

		$first = trim( $order->get_billing_first_name() );
		$lines = YeffoPrint_Order_Reviews::order_lines( $order );
		$stars = [];
		for ( $i = 1; $i <= 5; $i++ ) {
			$stars[ $i ] = YeffoPrint_Order_Reviews::review_url( $order, $i );
		}

		return ( new YeffoPrint_Email_Review_Request() )->send_request( $order->get_billing_email(), [
			'subject'       => '' !== $first
				/* translators: %s: customer's first name */
				? sprintf( __( '%s, how did we do? Leave a quick review', 'yeffoprint-core' ), $first )
				: __( 'How did we do? Leave a quick review', 'yeffoprint-core' ),
			'email_heading' => __( 'How did your order turn out?', 'yeffoprint-core' ),
			'name'          => '' !== $first ? $first : __( 'there', 'yeffoprint-core' ),
			'order_number'  => $order->get_order_number(),
			'lines'         => array_slice( $lines, 0, 4 ),
			'more'          => max( 0, count( $lines ) - 4 ),
			'star_urls'     => $stars,
			'review_url'    => YeffoPrint_Order_Reviews::review_url( $order ),
		] );
	}
}
