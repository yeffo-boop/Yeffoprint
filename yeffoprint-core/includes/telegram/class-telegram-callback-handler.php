<?php
/**
 * Handles Telegram `callback_query` updates — button taps, currently
 * only the "✅ Approve" / "✏️ Request changes" pair
 * class-telegram-order-notifications.php attaches to a proof-ready or
 * reminder message (direct request: "how great would it be if
 * customers can view/approve/reject proofs right from the telegram
 * bot"). Kept separate from class-telegram-message-handler.php (plain
 * text messages) the same way that class is already kept separate from
 * class-telegram-webhook-controller.php (Telegram plumbing) — a
 * different Telegram update shape with a different dispatch path
 * (class-telegram-webhook-controller.php routes `callback_query`
 * updates here, `message`/`edited_message` updates there).
 *
 * Deliberately requires the tapping chat to be account-linked
 * (class-telegram-account-link.php) AND own the Custom Order via the
 * exact same CUSTOMER_ID check class-proof-approval-controller.php's
 * own check_access() already uses for a logged-in web session — a
 * mutating action needs a stronger trust bar than the plain per-order
 * chat_id list (class-telegram-order-notifications.php) that opts in
 * merely by once proving an order number + checkout email, which is
 * why buttons are only ever attached for an account-linked chat in the
 * first place (see class-telegram-order-notifications.php). A guest
 * customer with no account keeps their original guest-token proof-
 * approval link, unaffected — this is a new convenience layered on
 * top for a connected account, not a replacement.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Callback_Handler {

	private const PENDING_REJECT_PREFIX = 'yp_telegram_reject_';
	private const PENDING_REJECT_TTL    = 10 * MINUTE_IN_SECONDS;

	/** @param array $callback_query The Telegram "callback_query" object — id, data, message.chat.id. */
	public function handle( array $callback_query, string $bot_token ): void {
		$callback_query_id = (string) ( $callback_query['id'] ?? '' );
		$chat_id            = (int) ( $callback_query['message']['chat']['id'] ?? 0 );
		$data               = (string) ( $callback_query['data'] ?? '' );

		if ( '' === $callback_query_id || ! $chat_id || '' === $data ) {
			return;
		}

		$client = new YeffoPrint_Telegram_Client( $bot_token );
		$parts  = explode( ':', $data, 2 );
		$action = $parts[0] ?? '';
		$custom_order_id = absint( $parts[1] ?? '' );

		if ( ! $custom_order_id || ! in_array( $action, [ 'proof_approve', 'proof_reject' ], true ) ) {
			$client->answer_callback_query( $callback_query_id );
			return;
		}

		if ( ! self::chat_owns_order( $chat_id, $custom_order_id ) ) {
			$client->answer_callback_query( $callback_query_id, __( "You don't have access to that.", 'yeffoprint-core' ) );
			return;
		}

		if ( 'proof_approve' === $action ) {
			$this->handle_approve( $client, $callback_query_id, $chat_id, $custom_order_id );
			return;
		}

		$this->handle_reject_tap( $client, $callback_query_id, $chat_id, $custom_order_id );
	}

	private function handle_approve( YeffoPrint_Telegram_Client $client, string $callback_query_id, int $chat_id, int $custom_order_id ): void {
		$ok = YeffoPrint_Proof_Approval_Controller::approve_custom_order( $custom_order_id );

		$client->answer_callback_query( $callback_query_id, $ok ? __( 'Approved!', 'yeffoprint-core' ) : __( 'Already responded to.', 'yeffoprint-core' ) );
		$client->send_message(
			$chat_id,
			$ok
				? __( "✅ Approved — thanks! We'll get this printing.", 'yeffoprint-core' )
				: __( "Looks like this one's already been responded to.", 'yeffoprint-core' )
		);
	}

	/** Taps only *start* the reject flow — the actual status change waits for the customer's next message (apply_reject(), called from class-telegram-message-handler.php once that arrives). */
	private function handle_reject_tap( YeffoPrint_Telegram_Client $client, string $callback_query_id, int $chat_id, int $custom_order_id ): void {
		if ( 'awaiting_approval' !== (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, true ) ) {
			$client->answer_callback_query( $callback_query_id, __( 'Already responded to.', 'yeffoprint-core' ) );
			$client->send_message( $chat_id, __( "Looks like this one's already been responded to.", 'yeffoprint-core' ) );
			return;
		}

		self::store_pending_reject( $chat_id, $custom_order_id );
		$client->answer_callback_query( $callback_query_id );
		$client->send_message( $chat_id, __( "What would you like changed? Reply with your notes and I'll pass them along to our team.", 'yeffoprint-core' ) );
	}

	/** Applies a reject once the customer's follow-up notes arrive — called from class-telegram-message-handler.php's handle_pending_reject(), not from handle() above. @return string The chat reply. */
	public static function apply_reject( int $custom_order_id, string $notes ): string {
		$ok = YeffoPrint_Proof_Approval_Controller::reject_custom_order( $custom_order_id, sanitize_textarea_field( $notes ) );

		return $ok
			? __( "Got it — I've sent your notes to our team. We'll follow up here once a new proof is ready.", 'yeffoprint-core' )
			: __( "Looks like this one's already been responded to — no changes recorded.", 'yeffoprint-core' );
	}

	public static function has_pending_reject( int $chat_id ): bool {
		return false !== get_transient( self::key( $chat_id ) );
	}

	public static function store_pending_reject( int $chat_id, int $custom_order_id ): void {
		set_transient( self::key( $chat_id ), $custom_order_id, self::PENDING_REJECT_TTL );
	}

	/** Win or lose — same one-shot reasoning as every other transient-backed pending state in this plugin (class-telegram-escalation.php, class-social-login.php's OAuth state). @return int 0 if nothing was pending. */
	public static function consume_pending_reject( int $chat_id ): int {
		$key             = self::key( $chat_id );
		$custom_order_id = (int) get_transient( $key );
		delete_transient( $key );

		return $custom_order_id;
	}

	/** Also called from class-telegram-order-notifications.php to decide whether a given linked chat gets Approve/Reject buttons on a proof notification in the first place — same rule, one place. */
	public static function chat_owns_order( int $chat_id, int $custom_order_id ): bool {
		$user_id = YeffoPrint_Telegram_Account_Link::get_user_id_for_chat( $chat_id );
		if ( ! $user_id ) {
			return false;
		}

		$owner_id = (int) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_ID, true );

		return $owner_id > 0 && $owner_id === $user_id;
	}

	private static function key( int $chat_id ): string {
		return self::PENDING_REJECT_PREFIX . $chat_id;
	}
}
