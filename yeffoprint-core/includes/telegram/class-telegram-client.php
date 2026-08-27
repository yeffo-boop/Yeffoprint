<?php
/**
 * Thin wrapper over the Telegram Bot API — outbound calls only
 * (sendMessage, answerCallbackQuery, getMe, setWebhook, deleteWebhook,
 * getWebhookInfo). Inbound updates arrive at class-telegram-webhook-
 * controller.php instead, so this class never needs to know about
 * commands/FAQ/order lookup/proof approval.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Client {

	private const API_BASE = 'https://api.telegram.org/bot';

	private string $token;

	public function __construct( string $token ) {
		$this->token = $token;
	}

	/**
	 * $inline_keyboard, when given, is a plain `[ [ ['text'=>.., 'callback_data'=>..], .. ], .. ]`
	 * array (rows of buttons) — this class just JSON-encodes it into
	 * Telegram's own `reply_markup` shape; callers (e.g.
	 * class-telegram-order-notifications.php) build the button labels/
	 * callback_data themselves.
	 */
	public function send_message( int $chat_id, string $text, ?array $inline_keyboard = null ): bool {
		$params = [
			'chat_id'                  => $chat_id,
			'text'                     => $text,
			'disable_web_page_preview' => true,
		];

		if ( $inline_keyboard ) {
			$params['reply_markup'] = wp_json_encode( [ 'inline_keyboard' => $inline_keyboard ] );
		}

		$response = $this->call( 'sendMessage', $params );

		return ! is_wp_error( $response );
	}

	/**
	 * Acknowledges a button tap (Telegram's own requirement — the
	 * tapped button shows a loading spinner/error state to the customer
	 * until this is called, or a few seconds pass and Telegram gives up
	 * waiting). $text, if given, shows as a small transient toast/alert
	 * in the customer's Telegram client — used for a quick "Approved!"
	 * without needing a whole extra sendMessage call.
	 */
	public function answer_callback_query( string $callback_query_id, string $text = '' ): bool {
		$response = $this->call( 'answerCallbackQuery', array_filter( [
			'callback_query_id' => $callback_query_id,
			'text'              => $text,
		] ) );

		return ! is_wp_error( $response );
	}

	/** @return array|\WP_Error */
	public function get_me() {
		return $this->call( 'getMe', [] );
	}

	/** @return array|\WP_Error */
	public function set_webhook( string $url, string $secret_token ) {
		return $this->call( 'setWebhook', [
			'url'             => $url,
			'secret_token'    => $secret_token,
			// 'callback_query' (direct request: approve/reject a proof
			// right from the bot) added alongside the original 'message'
			// — see class-telegram-webhook-sync.php's version-bump-triggered
			// re-sync for why an existing, already-registered webhook
			// actually picks this up after a deploy rather than needing an
			// admin to re-save Settings.
			'allowed_updates' => wp_json_encode( [ 'message', 'callback_query' ] ),
		] );
	}

	/** @return array|\WP_Error */
	public function delete_webhook() {
		return $this->call( 'deleteWebhook', [] );
	}

	/** @return array|\WP_Error */
	public function get_webhook_info() {
		return $this->call( 'getWebhookInfo', [] );
	}

	/** @return array|\WP_Error */
	private function call( string $method, array $params ) {
		if ( '' === $this->token ) {
			return new \WP_Error( 'yeffoprint_telegram_no_token', __( 'No Telegram bot token is configured.', 'yeffoprint-core' ) );
		}

		$response = wp_remote_post( self::API_BASE . $this->token . '/' . $method, [
			'timeout' => 15,
			'body'    => $params,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'yeffoprint_telegram_bad_response', __( 'Unexpected response from Telegram.', 'yeffoprint-core' ) );
		}

		if ( empty( $body['ok'] ) ) {
			return new \WP_Error( 'yeffoprint_telegram_api_error', (string) ( $body['description'] ?? __( 'Telegram API error.', 'yeffoprint-core' ) ) );
		}

		return $body;
	}
}
