<?php
/**
 * "Run the shop from your phone" commands, direct follow-up to Away
 * Mode — the store owner's own Telegram chat (TELEGRAM_ADMIN_CHAT_ID_OPTION,
 * the same one class-telegram-admin-alerts.php pushes new-order/quote/
 * contact alerts to) can now also *ask* the bot something instead of
 * only ever receiving pushes from it. Gated to that one chat id — is_admin_chat()
 * is the only thing standing between these commands and every other
 * chat, so nothing else needs to change if the option is ever unset.
 *
 * Read-only by design: this mirrors the admin dashboard's own "Pending
 * Orders" query (class-admin-dashboard-controller.php's pending_wc_orders())
 * and a same-day revenue count, not a way to *act* on an order from the
 * chat — there's no existing admin-side "approve this from Telegram"
 * step to hook into (proof approve/reject, class-telegram-callback-
 * handler.php, is the *customer's* own action on their own proof), so
 * building one here would be new surface, not wiring up something that
 * already exists elsewhere.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Admin_Commands {

	/** Same cap as the dashboard panel this mirrors (class-admin-dashboard-controller.php::ROW_LIMIT). */
	private const ROW_LIMIT = 20;

	public static function is_admin_chat( int $chat_id ): bool {
		$admin_chat_id = (int) get_option( YeffoPrint_Admin_Menu::TELEGRAM_ADMIN_CHAT_ID_OPTION, 0 );
		return $chat_id && $admin_chat_id && $chat_id === $admin_chat_id;
	}

	public static function pending_reply(): string {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return __( "Order lookup isn't available right now — please try again shortly.", 'yeffoprint-core' );
		}

		$orders = wc_get_orders( [
			'status'  => [ 'processing', YeffoPrint_Order_Production_Status::STATUS ],
			'limit'   => self::ROW_LIMIT,
			'orderby' => 'date',
			'order'   => 'ASC',
		] );

		if ( ! $orders ) {
			return __( "Nothing pending right now — every order is either shipped or not yet paid.", 'yeffoprint-core' );
		}

		$lines = [ sprintf(
			/* translators: %d: number of orders awaiting production/printing */
			_n( '%d order pending:', '%d orders pending (oldest first):', count( $orders ), 'yeffoprint-core' ),
			count( $orders )
		), '' ];

		foreach ( $orders as $order ) {
			$lines[] = sprintf(
				'• %1$s — %2$s — %3$s',
				$order->get_order_number(),
				$order->get_formatted_billing_full_name() ?: $order->get_billing_email(),
				wc_get_order_status_name( $order->get_status() )
			);
		}

		return implode( "\n", $lines );
	}

	public static function today_reply(): string {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return __( "Order lookup isn't available right now — please try again shortly.", 'yeffoprint-core' );
		}

		$orders = wc_get_orders( [
			'date_created' => current_time( 'Y-m-d' ),
			'limit'        => -1,
		] );

		if ( ! $orders ) {
			return __( "No orders placed yet today.", 'yeffoprint-core' );
		}

		$revenue     = 0.0;
		$paid_count  = 0;
		$dead_status = [ 'cancelled', 'failed', 'trash' ];

		foreach ( $orders as $order ) {
			if ( in_array( $order->get_status(), $dead_status, true ) ) {
				continue;
			}
			$revenue += (float) $order->get_total();
			$paid_count++;
		}

		$plain_revenue = html_entity_decode( wp_strip_all_tags( wc_price( $revenue ) ), ENT_QUOTES, 'UTF-8' );

		$lines   = [];
		$lines[] = sprintf(
			/* translators: 1: number of orders placed today, 2: today's revenue, already formatted with a currency symbol */
			__( "Today: %1\$d order(s), %2\$s in revenue.", 'yeffoprint-core' ),
			$paid_count,
			$plain_revenue
		);

		$pending = wc_get_orders( [
			'status' => [ 'processing', YeffoPrint_Order_Production_Status::STATUS ],
			'limit'  => 1,
			'return' => 'ids',
			'paginate' => true,
		] );
		$pending_count = is_object( $pending ) ? (int) $pending->total : 0;

		if ( $pending_count ) {
			$lines[] = sprintf(
				/* translators: %d: number of orders still awaiting production/printing, across all time not just today */
				_n( '%d order still pending overall.', '%d orders still pending overall.', $pending_count, 'yeffoprint-core' ),
				$pending_count
			);
		}

		return implode( "\n", $lines );
	}
}
