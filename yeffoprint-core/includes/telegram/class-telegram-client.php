<?php
/**
 * Thin wrapper over the Telegram Bot API — outbound calls only
 * (sendMessage, setWebhook, deleteWebhook, getWebhookInfo). Inbound
 * updates arrive at class-telegram-webhook-controller.php instead, so
 * this class never needs to know about commands/FAQ/order lookup.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Client {

	private const API_BASE = 'https://api.telegram.org/bot';

	private string $token;

	public function __construct( string $token ) {
		$this->token = $token;
	}

	public function send_message( int $chat_id, string $text ): bool {
		$response = $this->call( 'sendMessage', [
			'chat_id'                  => $chat_id,
			'text'                     => $text,
			'disable_web_page_preview' => true,
		] );

		return ! is_wp_error( $response );
	}

	/** @return array|\WP_Error */
	public function set_webhook( string $url, string $secret_token ) {
		return $this->call( 'setWebhook', [
			'url'             => $url,
			'secret_token'    => $secret_token,
			'allowed_updates' => wp_json_encode( [ 'message' ] ),
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
