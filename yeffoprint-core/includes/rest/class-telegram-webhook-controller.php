<?php
/**
 * Receives incoming updates from Telegram (direct request: a Telegram
 * bot answering FAQs and order-status questions, later extended to
 * proof approve/reject button taps — class-telegram-callback-handler.php).
 * Registered with
 * Telegram via `setWebhook` (class-telegram-webhook-sync.php, kept in
 * sync with the Settings-screen bot token/on-off toggle) rather than
 * long-polling — a REST route Telegram calls fits this plugin's
 * existing request-response shape (same idea as
 * class-payment-webhook-controller.php/class-stripe-webhook-controller.php)
 * without needing a standalone always-running process.
 *
 * Authenticated by the `X-Telegram-Bot-Api-Secret-Token` header Telegram
 * echoes back on every call (Telegram's own documented webhook-security
 * mechanism) rather than a token in the URL — there's no WordPress
 * session to check a nonce against here, same reasoning as the payment
 * webhook's bearer token, just carried as a header instead since
 * Telegram supports that natively.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Webhook_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	/** Generous enough for normal back-and-forth, tight enough to blunt someone hammering /order to brute-force order numbers. */
	private const RATE_LIMIT_WINDOW = 5 * MINUTE_IN_SECONDS;
	private const RATE_LIMIT_MAX    = 20;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/telegram/webhook', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => [ $this, 'check_secret_token' ],
		] );
	}

	/**
	 * @return true|\WP_Error
	 */
	public function check_secret_token( \WP_REST_Request $request ) {
		$token = (string) $request->get_header( 'X-Telegram-Bot-Api-Secret-Token' );

		if ( '' === $token || ! hash_equals( YeffoPrint_Telegram_Webhook_Secret::get(), $token ) ) {
			return new \WP_Error( 'yeffoprint_telegram_invalid_token', __( 'Invalid token.', 'yeffoprint-core' ), [ 'status' => 403 ] );
		}

		return true;
	}

	public function handle( \WP_REST_Request $request ) {
		// Always 200 back to Telegram below — a non-2xx response makes
		// Telegram retry the same update repeatedly, which is never
		// useful here (an ignored update type, a disabled bot, or a
		// rate-limited chat all mean "nothing to do", not "try again").
		if ( ! YeffoPrint_Telegram_Settings::is_enabled() || '' === YeffoPrint_Telegram_Settings::get_bot_token() ) {
			return rest_ensure_response( [ 'ok' => true ] );
		}

		$update = $request->get_json_params();

		// Button taps (direct request: approve/reject a proof right from
		// the bot) — a different update shape entirely, dispatched to a
		// different handler than the plain-text messages below. See
		// class-telegram-callback-handler.php.
		$callback_query = is_array( $update ) ? ( $update['callback_query'] ?? null ) : null;
		if ( is_array( $callback_query ) ) {
			$chat_id = (int) ( $callback_query['message']['chat']['id'] ?? 0 );
			if ( $chat_id && ! $this->is_rate_limited( $chat_id ) ) {
				( new YeffoPrint_Telegram_Callback_Handler() )->handle( $callback_query, YeffoPrint_Telegram_Settings::get_bot_token() );
			}
			return rest_ensure_response( [ 'ok' => true ] );
		}

		// Telegram Business (direct request: "a customer I'm chatting with
		// can send over a list of labels needed and the bot can create the
		// order"). These are the owner's own personal chats, so they never
		// reach the FAQ bot below: only class-telegram-chat-orders.php
		// handles them, and it stays silent unless the owner types /order.
		if ( is_array( $update ) && is_array( $update['business_connection'] ?? null ) ) {
			YeffoPrint_Telegram_Chat_Orders::handle_connection( $update['business_connection'] );
			return rest_ensure_response( [ 'ok' => true ] );
		}
		$business_message = is_array( $update ) ? ( $update['business_message'] ?? $update['edited_business_message'] ?? null ) : null;
		if ( is_array( $business_message ) ) {
			YeffoPrint_Telegram_Chat_Orders::handle_message( $business_message, isset( $update['edited_business_message'] ) );
			return rest_ensure_response( [ 'ok' => true ] );
		}

		$message = is_array( $update ) ? ( $update['message'] ?? $update['edited_message'] ?? null ) : null;

		if ( ! is_array( $message ) || empty( $message['chat']['id'] ) || ! isset( $message['text'] ) || ! is_string( $message['text'] ) ) {
			return rest_ensure_response( [ 'ok' => true ] ); // Not a text message this bot handles (photo, sticker, channel post, …).
		}

		$chat_id = (int) $message['chat']['id'];

		if ( $this->is_rate_limited( $chat_id ) ) {
			return rest_ensure_response( [ 'ok' => true ] );
		}

		$reply = ( new YeffoPrint_Telegram_Message_Handler() )->handle( $message );

		( new YeffoPrint_Telegram_Client( YeffoPrint_Telegram_Settings::get_bot_token() ) )->send_message( $chat_id, $reply );

		return rest_ensure_response( [ 'ok' => true ] );
	}

	private function is_rate_limited( int $chat_id ): bool {
		$key   = 'yp_telegram_rl_' . $chat_id;
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return true;
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		return false;
	}
}
