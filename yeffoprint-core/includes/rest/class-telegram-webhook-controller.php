<?php
/**
 * Receives incoming updates from Telegram (direct request: a Telegram
 * bot answering FAQs and order-status questions). Registered with
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

		$update  = $request->get_json_params();
		$message = is_array( $update ) ? ( $update['message'] ?? $update['edited_message'] ?? null ) : null;

		if ( ! is_array( $message ) || empty( $message['chat']['id'] ) || ! isset( $message['text'] ) || ! is_string( $message['text'] ) ) {
			return rest_ensure_response( [ 'ok' => true ] ); // Not a text message this bot handles (photo, sticker, channel post, …).
		}

		$chat_id = (int) $message['chat']['id'];

		if ( $this->is_rate_limited( $chat_id ) ) {
			return rest_ensure_response( [ 'ok' => true ] );
		}

		$reply = ( new YeffoPrint_Telegram_Message_Handler() )->handle( $message['text'] );

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
