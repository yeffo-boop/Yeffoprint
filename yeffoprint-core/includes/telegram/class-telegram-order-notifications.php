<?php
/**
 * Proactive Telegram pings for two order milestones a guest customer
 * has no other way to watch live: a custom design's proof becoming
 * ready to review, and an order shipping. Opt-in happens for free the
 * first time someone successfully checks an order's status through
 * the bot (class-telegram-message-handler.php) — no separate /notify
 * command, and no new access rule either: linking a chat to an order
 * only ever happens right after the exact same order-number +
 * checkout-email check that already gates reading that order's status.
 *
 * A proof-related notification (on_proof_ready(), on_reminder_due())
 * additionally attaches Approve/Reject buttons for any linked chat
 * that's ALSO account-linked (class-telegram-account-link.php) to that
 * Custom Order's own owner — see notify_order()'s own docblock. That's
 * a stronger, separate trust bar than this class's own order-scoped
 * linking above; a chat linked only via order+email lookup still gets
 * the plain notification, never buttons.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Order_Notifications {

	private const META_KEY = '_yp_telegram_chat_ids';

	public function __construct() {
		add_action( 'woocommerce_order_status_shipped', [ $this, 'on_shipped' ] );
		add_action( 'yeffoprint_proof_ready_for_review', [ $this, 'on_proof_ready' ] );
		add_action( 'yeffoprint_proof_reminder_due', [ $this, 'on_reminder_due' ], 10, 2 );
	}

	/** @return bool True if this chat was newly linked (wasn't already) — lets the caller decide whether to mention it. */
	public static function link( \WC_Order $order, int $chat_id ): bool {
		if ( ! $chat_id ) {
			return false;
		}

		$chat_ids = self::chat_ids( $order );
		if ( in_array( $chat_id, $chat_ids, true ) ) {
			return false;
		}

		$chat_ids[] = $chat_id;
		$order->update_meta_data( self::META_KEY, $chat_ids );
		$order->save_meta_data();

		return true;
	}

	public function on_shipped( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$lines = [ sprintf( /* translators: %s: order number */ __( 'Your order %s has shipped!', 'yeffoprint-core' ), $order->get_order_number() ) ];

		foreach ( YeffoPrint_Order_Tracking::get_shipments( $order ) as $shipment ) {
			$lines[] = sprintf( '%1$s %2$s', $shipment['carrier_label'], $shipment['tracking_number'] );
			if ( $shipment['carrier_url'] ) {
				$lines[] = $shipment['carrier_url'];
			}
		}

		$this->notify_order( $order, implode( "\n", $lines ) );
	}

	public function on_proof_ready( int $custom_order_id ): void {
		$wc_order_id = (int) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::WC_ORDER_ID, true );
		$order       = $wc_order_id ? wc_get_order( $wc_order_id ) : false;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// The guest-access proof-approval link (class-proof-meta.php's
		// own customer email uses the exact same one) — no separate
		// account/token needed for the Telegram-linked chat either. Still
		// included even for a chat that's about to get Approve/Reject
		// buttons too (below), since the link works from anywhere the
		// buttons don't (a browser, a different device).
		$url = function_exists( 'yeffoprint_core_proof_approval_url' ) ? yeffoprint_core_proof_approval_url( $custom_order_id ) : '';

		$text = $url
			? sprintf( /* translators: %s: proof approval link */ __( "Your custom label proof is ready to review:\n\n%s", 'yeffoprint-core' ), $url )
			: __( 'Your custom label proof is ready to review — check your email for the link.', 'yeffoprint-core' );

		$this->notify_order( $order, $text, $custom_order_id );
	}

	/** class-proof-reminder-scheduler.php's 24h/48h nudge — same guest-access link, just a shorter/more urgent line since the full proof-ready text already went out to this chat once. */
	public function on_reminder_due( int $custom_order_id, int $stage ): void {
		$wc_order_id = (int) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::WC_ORDER_ID, true );
		$order       = $wc_order_id ? wc_get_order( $wc_order_id ) : false;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$url = function_exists( 'yeffoprint_core_proof_approval_url' ) ? yeffoprint_core_proof_approval_url( $custom_order_id ) : '';
		if ( ! $url ) {
			return;
		}

		$text = 2 === $stage
			? sprintf( /* translators: %s: proof approval link */ __( "Still waiting on your OK for this proof — take a look when you get a chance:\n\n%s", 'yeffoprint-core' ), $url )
			: sprintf( /* translators: %s: proof approval link */ __( "Friendly reminder — your proof is still waiting on your review:\n\n%s", 'yeffoprint-core' ), $url );

		$this->notify_order( $order, $text, $custom_order_id );
	}

	/**
	 * $custom_order_id, when given, is a proof-related notification
	 * (proof-ready or a reminder) — each linked chat that's ALSO
	 * account-linked (class-telegram-account-link.php) to that Custom
	 * Order's own owner gets Approve/Reject buttons attached, so tapping
	 * one talks to the actual customer, not just whichever chat once
	 * looked this order up. Every other linked chat (order-linked but
	 * not account-linked, or account-linked to someone else) still gets
	 * the plain text/link, unchanged — buttons are additive, not a
	 * replacement for the existing notification.
	 */
	private function notify_order( \WC_Order $order, string $text, int $custom_order_id = 0 ): void {
		$token = YeffoPrint_Telegram_Settings::get_bot_token();
		if ( '' === $token || ! YeffoPrint_Telegram_Settings::is_enabled() ) {
			return;
		}

		$client   = new YeffoPrint_Telegram_Client( $token );
		$keyboard = $custom_order_id ? [ [
			[ 'text' => __( '✅ Approve', 'yeffoprint-core' ), 'callback_data' => 'proof_approve:' . $custom_order_id ],
			[ 'text' => __( '✏️ Request changes', 'yeffoprint-core' ), 'callback_data' => 'proof_reject:' . $custom_order_id ],
		] ] : null;

		foreach ( self::chat_ids( $order ) as $chat_id ) {
			$buttons = ( $keyboard && YeffoPrint_Telegram_Callback_Handler::chat_owns_order( $chat_id, $custom_order_id ) ) ? $keyboard : null;
			$client->send_message( $chat_id, $text, $buttons );
		}
	}

	/** @return int[] */
	private static function chat_ids( \WC_Order $order ): array {
		$chat_ids = $order->get_meta( self::META_KEY, true );
		return is_array( $chat_ids ) ? array_map( 'intval', $chat_ids ) : [];
	}
}
