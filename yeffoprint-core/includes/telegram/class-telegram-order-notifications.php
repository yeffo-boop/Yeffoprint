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
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Order_Notifications {

	private const META_KEY = '_yp_telegram_chat_ids';

	public function __construct() {
		add_action( 'woocommerce_order_status_shipped', [ $this, 'on_shipped' ] );
		add_action( 'yeffoprint_proof_ready_for_review', [ $this, 'on_proof_ready' ] );
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
		// account/token needed for the Telegram-linked chat either.
		$url = function_exists( 'yeffoprint_core_proof_approval_url' ) ? yeffoprint_core_proof_approval_url( $custom_order_id ) : '';

		$text = $url
			? sprintf( /* translators: %s: proof approval link */ __( "Your custom label proof is ready to review:\n\n%s", 'yeffoprint-core' ), $url )
			: __( 'Your custom label proof is ready to review — check your email for the link.', 'yeffoprint-core' );

		$this->notify_order( $order, $text );
	}

	private function notify_order( \WC_Order $order, string $text ): void {
		$token = YeffoPrint_Telegram_Settings::get_bot_token();
		if ( '' === $token || ! YeffoPrint_Telegram_Settings::is_enabled() ) {
			return;
		}

		$client = new YeffoPrint_Telegram_Client( $token );
		foreach ( self::chat_ids( $order ) as $chat_id ) {
			$client->send_message( $chat_id, $text );
		}
	}

	/** @return int[] */
	private static function chat_ids( \WC_Order $order ): array {
		$chat_ids = $order->get_meta( self::META_KEY, true );
		return is_array( $chat_ids ) ? array_map( 'intval', $chat_ids ) : [];
	}
}
