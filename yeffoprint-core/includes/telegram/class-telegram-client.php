<?php
/**
 * Thin wrapper over the Telegram Bot API — outbound calls only
 * (sendMessage, sendPhoto, sendDocument, answerCallbackQuery, getMe,
 * setWebhook, deleteWebhook, getWebhookInfo). Inbound updates arrive at
 * class-telegram-webhook-controller.php instead, so this class never
 * needs to know about commands/FAQ/order lookup/proof approval.
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
	 * Posts the actual proof image inline in the chat (direct follow-up
	 * report: buttons alone let a customer approve/reject "blind,"
	 * without ever seeing what they're responding to) — Telegram fetches
	 * $photo_url itself server-side, same as it would for a link
	 * preview, so this needs no file upload/multipart handling on our
	 * end. $caption is the same text send_message() would otherwise
	 * send (1024-char Telegram cap — comfortably clear of anything this
	 * plugin's own proof-notification copy ever generates).
	 */
	public function send_photo( int $chat_id, string $photo_url, string $caption = '', ?array $inline_keyboard = null ): bool {
		return $this->send_media( 'sendPhoto', 'photo', $chat_id, $photo_url, $caption, $inline_keyboard );
	}

	/** Same as send_photo() but for a non-image proof file (a PDF, say) Telegram can't render as a photo — still lets the customer open/preview it from within Telegram rather than needing the web link. */
	public function send_document( int $chat_id, string $document_url, string $caption = '', ?array $inline_keyboard = null ): bool {
		return $this->send_media( 'sendDocument', 'document', $chat_id, $document_url, $caption, $inline_keyboard );
	}

	private function send_media( string $method, string $media_param, int $chat_id, string $media_url, string $caption, ?array $inline_keyboard ): bool {
		$params = [
			'chat_id'     => $chat_id,
			$media_param  => $media_url,
			'caption'     => $caption,
		];

		if ( $inline_keyboard ) {
			$params['reply_markup'] = wp_json_encode( [ 'inline_keyboard' => $inline_keyboard ] );
		}

		$response = $this->call( $method, $params );

		return ! is_wp_error( $response );
	}

	/**
	 * Uploads a photo from disk (multipart), for images Telegram can't
	 * fetch itself because they aren't public — e.g. Dose Tracker
	 * feedback screenshots (class-tracker-feedback.php).
	 */
	public function send_photo_file( int $chat_id, string $path, string $caption = '', ?array $inline_keyboard = null ): bool {
		if ( '' === $this->token || ! is_readable( $path ) ) {
			return false;
		}

		$fields = [
			'chat_id' => (string) $chat_id,
			'caption' => $caption,
		];
		if ( $inline_keyboard ) {
			$fields['reply_markup'] = (string) wp_json_encode( [ 'inline_keyboard' => $inline_keyboard ] );
		}

		$boundary = 'yp' . wp_generate_password( 24, false );
		$body     = '';
		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"photo\"; filename=\"" . basename( $path ) . "\"\r\nContent-Type: " . ( wp_get_image_mime( $path ) ?: 'image/jpeg' ) . "\r\n\r\n";
		$body .= (string) file_get_contents( $path ) . "\r\n--{$boundary}--\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$response = wp_remote_post( self::API_BASE . $this->token . '/sendPhoto', [
			'timeout' => 20,
			'headers' => [ 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ],
			'body'    => $body,
		] );
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $json ) && ! empty( $json['ok'] );
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
