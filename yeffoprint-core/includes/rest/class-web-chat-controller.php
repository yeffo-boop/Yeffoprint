<?php
/**
 * The on-site chat widget's endpoint — direct request, following the
 * Telegram bot's own launch: "can you do some research into
 * integrating a chat bot into the site that does the same as the
 * telegram bot?" The finding that led here: the bot's actual FAQ/
 * order-lookup/escalation logic already lives entirely in
 * YeffoPrint_Telegram_Message_Handler::handle(), decoupled from every
 * Telegram-specific detail (the webhook, the secret token, actually
 * calling sendMessage) — so a website widget can call that exact same
 * method directly instead of reimplementing any of it. This controller
 * is the thin, website-specific wrapper that class-telegram-webhook-
 * controller.php already is for Telegram itself.
 *
 * A website visitor has no chat_id the way a Telegram user does —
 * yeffoprint/assets/js/web-chat-widget.js generates a random per-
 * browser session id (persisted in localStorage) and sends it as
 * `session_id` on every message; that id is used as handle()'s own
 * $chat_id, which is all the pending-escalation/pending-reject state
 * (class-telegram-escalation.php/class-telegram-callback-handler.php)
 * actually needs to isolate one conversation from another — a random,
 * sufficiently large integer collides with another session, or with a
 * real Telegram chat_id, with the same astronomically low probability
 * either of those systems already relies on. `false` for handle()'s
 * own $can_push_notifications means order_status_reply() never claims
 * "I'll message you here" or links this session_id to a real order for
 * a later push — there's no delivery channel to a closed browser tab to
 * make good on that promise.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Chat_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	private const MAX_TEXT_LENGTH = 1000;

	/** Generous enough for a real back-and-forth, tight enough to blunt a script hammering the endpoint — same bar and shape as class-telegram-webhook-controller.php's own limit, applied per session_id here since that's this endpoint's own notion of "one conversation," plus a coarser per-IP cap so cycling session_ids doesn't bypass it. */
	private const SESSION_RATE_LIMIT_WINDOW = 5 * MINUTE_IN_SECONDS;
	private const SESSION_RATE_LIMIT_MAX    = 20;
	private const IP_RATE_LIMIT_WINDOW      = 5 * MINUTE_IN_SECONDS;
	private const IP_RATE_LIMIT_MAX         = 40;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/web-chat/message', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'send' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'guest_or_nonced_write' ],
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function send( \WP_REST_Request $request ) {
		if ( ! YeffoPrint_Telegram_Settings::is_enabled() ) {
			return new \WP_Error( 'yeffoprint_web_chat_disabled', __( 'Chat is currently unavailable.', 'yeffoprint-core' ), [ 'status' => 403 ] );
		}

		$session_id = absint( $request->get_param( 'session_id' ) );
		if ( ! $session_id ) {
			return new \WP_Error( 'yeffoprint_web_chat_no_session', __( 'Missing session id.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$text = trim( sanitize_textarea_field( (string) $request->get_param( 'text' ) ) );
		if ( '' === $text ) {
			return new \WP_Error( 'yeffoprint_web_chat_empty', __( 'Type a message first.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		if ( mb_strlen( $text ) > self::MAX_TEXT_LENGTH ) {
			$text = mb_substr( $text, 0, self::MAX_TEXT_LENGTH );
		}

		$rate_limited = $this->check_rate_limit( $session_id );
		if ( is_wp_error( $rate_limited ) ) {
			return $rate_limited;
		}

		$message = [
			'chat' => [ 'id' => $session_id ],
			'text' => $text,
			// Only ever surfaces in an escalation email's "who this was
			// from" line (class-telegram-escalation.php's describe_sender())
			// when no username/name is otherwise known — a real Telegram
			// user's from.first_name would already say something more
			// useful than this, but a website visitor has no equivalent,
			// so this at least reads as a sentence instead of "chat
			// 8817263492".
			'from' => [ 'first_name' => __( 'Website visitor', 'yeffoprint-core' ) ],
		];

		$reply = ( new YeffoPrint_Telegram_Message_Handler() )->handle( $message, false );

		return rest_ensure_response( [ 'reply' => $reply ] );
	}

	/** @return \WP_Error|null Error if either cap has been hit; null (and both counters bumped) otherwise. */
	private function check_rate_limit( int $session_id ): ?\WP_Error {
		$session_key = 'yp_web_chat_rl_' . $session_id;
		if ( (int) get_transient( $session_key ) >= self::SESSION_RATE_LIMIT_MAX ) {
			return new \WP_Error( 'yeffoprint_web_chat_rate_limited', __( "You're sending messages a little fast — try again in a few minutes.", 'yeffoprint-core' ), [ 'status' => 429 ] );
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip_key = $ip ? 'yp_web_chat_rl_ip_' . md5( $ip ) : '';
		if ( $ip_key && (int) get_transient( $ip_key ) >= self::IP_RATE_LIMIT_MAX ) {
			return new \WP_Error( 'yeffoprint_web_chat_rate_limited', __( "You're sending messages a little fast — try again in a few minutes.", 'yeffoprint-core' ), [ 'status' => 429 ] );
		}

		set_transient( $session_key, (int) get_transient( $session_key ) + 1, self::SESSION_RATE_LIMIT_WINDOW );
		if ( $ip_key ) {
			set_transient( $ip_key, (int) get_transient( $ip_key ) + 1, self::IP_RATE_LIMIT_WINDOW );
		}

		return null;
	}
}
