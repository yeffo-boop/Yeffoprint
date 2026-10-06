<?php
/**
 * Escalating alerts for paid Express orders (class-express-order.php):
 * a phone push from the admin app (class-admin-push.php), and the
 * owner's Telegram chat too when owner alerts are copied there
 * (YeffoPrint_Telegram_Admin_Alerts::telegram_chat_id()), right away
 * and then every 30 minutes until the owner acknowledges it, or the
 * order moves on to In Production / Shipped (or anywhere else past
 * Processing).
 *
 * Acknowledging in the admin app (direct request: "I need a way to
 * acknowledge express orders in the web app like I did in telegram"):
 * the order page and the Today queue show an Acknowledge button
 * (POST /admin/order/{id}/express-ack), and on phones that show
 * notification buttons (Android, desktop; not iPhone) the push itself
 * has a "Got it" button that calls /express-ack/{id} with a signed
 * token, so it works without opening the app.
 *
 * Acknowledging on Telegram: every alert carries a "✅ Got it" button
 * (class-telegram-callback-handler.php routes `express_ack:<id>` taps
 * here), and typing `/ack` (or just "ack") in the owner's chat
 * acknowledges every open express alert at once — `/ack 1234` just
 * that order (class-telegram-message-handler.php).
 *
 * Starts at Processing, i.e. once the order is actually paid — a
 * Venmo/Zelle order sits On Hold until the owner confirms payment, and
 * nagging about an unpaid order would be noise. Same sweep-over-a-flag
 * shape as class-proof-reminder-scheduler.php rather than one cron
 * event per order: a 5-minute sweep over orders flagged ESCALATING_META
 * sends whatever's due, so a missed run just catches up on the next.
 * WP-Cron only runs on site traffic, so on a quiet night a reminder can
 * land a few minutes late.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Express_Alerts {

	private const HOOK     = 'yeffoprint_express_alert_sweep';
	private const SCHEDULE = 'yeffoprint_five_minutes';

	const INTERVAL = 30 * MINUTE_IN_SECONDS;

	/** 'yes' while alerts should keep going; deleted once acknowledged or past Processing. */
	const ESCALATING_META  = '_yp_express_escalating';
	const NEXT_ALERT_META  = '_yp_express_next_alert';
	const ALERT_COUNT_META = '_yp_express_alert_count';
	const ACKED_AT_META    = '_yp_express_acked_at';

	public function __construct() {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::HOOK, [ $this, 'sweep' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );

		add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 4 );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( 'yeffoprint-core/v1', '/admin/order/(?P<id>\d+)/express-ack', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_acknowledge' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		// The push notification's "Got it" button. The service worker has
		// no REST nonce, so the push carries a token signed for this one
		// order instead. All it can do is stop that order's reminders.
		register_rest_route( 'yeffoprint-core/v1', '/express-ack/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_acknowledge' ],
			'permission_callback' => static function ( \WP_REST_Request $request ): bool {
				return hash_equals( self::ack_token( (int) $request['id'] ), (string) $request->get_param( 'token' ) );
			},
		] );
	}

	public function rest_acknowledge( \WP_REST_Request $request ): \WP_REST_Response {
		$ok = self::acknowledge( (int) $request['id'], __( 'Express order acknowledged in the dashboard — alerts stopped.', 'yeffoprint-core' ) );
		return new \WP_REST_Response( [ 'acknowledged' => $ok, 'waiting' => false ] );
	}

	private static function ack_token( int $order_id ): string {
		return wp_hash( 'express_ack|' . $order_id );
	}

	/** True while reminders are still going out for this order (Acknowledge buttons in the admin app). */
	public static function is_waiting( \WC_Order $order ): bool {
		return 'yes' === $order->get_meta( self::ESCALATING_META ) && 'processing' === $order->get_status();
	}

	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'yeffoprint-core' ),
		];
		return $schedules;
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * @param int       $order_id
	 * @param string    $from
	 * @param string    $to
	 * @param \WC_Order $order
	 */
	public function on_status_changed( $order_id, $from, $to, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( 'processing' === $to ) {
			$this->start( $order );
			return;
		}

		// In Production, Shipped, Completed — or cancelled/refunded — all
		// mean nobody needs nagging about this one any more.
		if ( 'yes' === $order->get_meta( self::ESCALATING_META ) ) {
			self::stop( $order, sprintf(
				/* translators: %s: the order's new status, e.g. "In Production" */
				__( 'Express alerts stopped: order moved to %s.', 'yeffoprint-core' ),
				wc_get_order_status_name( $to )
			) );
		}
	}

	/** Once per order: a Processing → On Hold → Processing round trip, or an already-acknowledged order, doesn't restart the alerts. */
	private function start( \WC_Order $order ): void {
		if ( ! YeffoPrint_Express_Order::is_express( $order ) ) {
			return;
		}
		if ( $order->get_meta( self::ACKED_AT_META ) || '' !== (string) $order->get_meta( self::ALERT_COUNT_META ) ) {
			return;
		}

		$order->update_meta_data( self::ESCALATING_META, 'yes' );
		$order->update_meta_data( self::ALERT_COUNT_META, 0 );
		$order->save_meta_data();

		self::send_alert( $order );
	}

	public function sweep(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$orders = wc_get_orders( [
			'status'     => [ 'processing' ],
			'limit'      => 50,
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, flagged set; no indexed alternative.
				[
					'key'   => self::ESCALATING_META,
					'value' => 'yes',
				],
			],
		] );

		foreach ( $orders as $order ) {
			if ( (int) $order->get_meta( self::NEXT_ALERT_META ) <= time() ) {
				self::send_alert( $order );
			}
		}
	}

	private static function send_alert( \WC_Order $order ): void {
		$count = (int) $order->get_meta( self::ALERT_COUNT_META ) + 1;

		// Recorded before sending, so an outage retries on the next
		// 30-minute mark rather than every 5-minute sweep.
		$order->update_meta_data( self::ALERT_COUNT_META, $count );
		$order->update_meta_data( self::NEXT_ALERT_META, time() + self::INTERVAL );
		$order->save_meta_data();

		self::send_push( $order, $count );

		$chat_id = YeffoPrint_Telegram_Admin_Alerts::telegram_chat_id();
		if ( ! $chat_id ) {
			return;
		}

		( new YeffoPrint_Telegram_Client( YeffoPrint_Telegram_Settings::get_bot_token() ) )->send_message(
			$chat_id,
			self::alert_text( $order, $count ),
			[ [ [ 'text' => __( '✅ Got it', 'yeffoprint-core' ), 'callback_data' => 'express_ack:' . $order->get_id() ] ] ]
		);
	}

	/**
	 * Same tag as the order's "New order paid" push, so the phone keeps
	 * one notification per order; renotify makes each reminder buzz again
	 * instead of silently replacing the last one.
	 */
	private static function send_push( \WC_Order $order, int $count ): void {
		if ( ! class_exists( 'YeffoPrint_Admin_Push' ) ) {
			return;
		}

		$lines = explode( "\n", self::alert_text( $order, $count ) );
		$items = [];
		foreach ( $order->get_items() as $item ) {
			$items[] = sprintf( '%1$s × %2$d', $item->get_name(), $item->get_quantity() );
		}

		YeffoPrint_Admin_Push::send_to_admins( [
			'title'              => mb_strimwidth( $lines[0], 0, 120, '…' ),
			'body'               => mb_strimwidth( implode( ' · ', array_filter( [ $lines[1] ?? '', implode( ', ', $items ) ] ) ), 0, 200, '…' ) . "\n" . __( 'Acknowledge to stop the reminders.', 'yeffoprint-core' ),
			'url'                => admin_url( 'admin.php?page=' . YeffoPrint_Admin_Push::APP_SLUG ) . '#/order/' . $order->get_id(),
			'tag'                => 'order-' . $order->get_id(),
			'renotify'           => true,
			'requireInteraction' => true,
			'actions'            => [ [ 'action' => 'ack', 'title' => __( '✅ Got it', 'yeffoprint-core' ) ] ],
			'ack_url'            => add_query_arg( 'token', self::ack_token( $order->get_id() ), rest_url( 'yeffoprint-core/v1/express-ack/' . $order->get_id() ) ),
		] );
	}

	private static function alert_text( \WC_Order $order, int $count ): string {
		$heading = 1 === $count
			? sprintf(
				/* translators: 1: order number, 2: formatted order total */
				__( '⚡ EXPRESS order paid: %1$s (%2$s)', 'yeffoprint-core' ),
				$order->get_order_number(),
				YeffoPrint_Telegram_Order_Lookup::plain_total( $order )
			)
			: sprintf(
				/* translators: 1: order number, 2: how many alerts have been sent for it, 3: time since it was paid, e.g. "1 hour" */
				__( '⚡ Reminder #%2$d: EXPRESS order %1$s is still waiting (paid %3$s ago)', 'yeffoprint-core' ),
				$order->get_order_number(),
				$count,
				human_time_diff( $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : $order->get_date_created()->getTimestamp() )
			);

		$lines = [ $heading, trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), '' ];
		foreach ( $order->get_items() as $item ) {
			$lines[] = sprintf( '• %1$s × %2$d', $item->get_name(), $item->get_quantity() );
		}
		$lines[] = '';
		$lines[] = __( 'Tap "Got it" or reply /ack to stop these alerts. They also stop once the order is In Production or Shipped.', 'yeffoprint-core' );

		return implode( "\n", $lines );
	}

	/** @return bool False when the order isn't an express order with alerts still running. */
	public static function acknowledge( int $order_id, string $note = '' ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || 'yes' !== $order->get_meta( self::ESCALATING_META ) ) {
			return false;
		}

		$order->update_meta_data( self::ACKED_AT_META, time() );
		self::stop( $order, '' !== $note ? $note : __( 'Express order acknowledged on Telegram — alerts stopped.', 'yeffoprint-core' ) );
		return true;
	}

	/** @return string[] Order numbers acknowledged. */
	public static function acknowledge_all(): array {
		$numbers = [];
		foreach ( self::escalating_order_ids() as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order && self::acknowledge( $order_id ) ) {
				$numbers[] = $order->get_order_number();
			}
		}
		return $numbers;
	}

	/** @return int[] */
	public static function escalating_order_ids(): array {
		return wc_get_orders( [
			'status'     => [ 'processing' ],
			'limit'      => 50,
			'return'     => 'ids',
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, flagged set; no indexed alternative.
				[
					'key'   => self::ESCALATING_META,
					'value' => 'yes',
				],
			],
		] );
	}

	private static function stop( \WC_Order $order, string $note ): void {
		$order->delete_meta_data( self::ESCALATING_META );
		$order->delete_meta_data( self::NEXT_ALERT_META );
		$order->save_meta_data();
		$order->add_order_note( $note );
	}

	/** Owner-chat `/ack [order number]` reply text — see class-telegram-message-handler.php. */
	public static function ack_reply( string $order_ref ): string {
		$order_ref = ltrim( trim( $order_ref ), '#' );

		if ( '' === $order_ref ) {
			$numbers = self::acknowledge_all();
			return $numbers
				? sprintf(
					/* translators: %s: comma-separated order numbers */
					__( '✅ Acknowledged: %s. No more express alerts for these.', 'yeffoprint-core' ),
					implode( ', ', $numbers )
				)
				: __( 'No express orders are waiting on you right now.', 'yeffoprint-core' );
		}

		foreach ( self::escalating_order_ids() as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order && ( (string) $order->get_order_number() === $order_ref || (string) $order_id === $order_ref ) ) {
				self::acknowledge( $order_id );
				return sprintf(
					/* translators: %s: order number */
					__( '✅ Acknowledged express order %s. No more alerts for it.', 'yeffoprint-core' ),
					$order->get_order_number()
				);
			}
		}

		return sprintf(
			/* translators: %s: what the owner typed after /ack */
			__( "No express order %s is waiting on you. Send /ack on its own to acknowledge all of them.", 'yeffoprint-core' ),
			$order_ref
		);
	}
}
