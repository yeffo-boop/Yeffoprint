<?php
/**
 * Thin wrapper over the NOWPayments REST API
 * (https://documenter.getpostman.com/view/7907941/S1a32n38) — same shape
 * as class-shippo-client.php: one private call() helper, public methods
 * that return an array on success or \WP_Error on failure, no local
 * state beyond the API key.
 *
 * Only one call is needed for checkout: create_invoice() creates an
 * Invoice priced in the order's own currency, and NOWPayments' hosted
 * invoice_url is where the customer actually picks USDT/USDC/etc. and
 * the chain to pay on — this plugin never touches wallet addresses or
 * chain selection itself. (Replaces the old Coinbase Commerce client,
 * whose Charges API Coinbase retired.)
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_NOWPayments_Client {

	private const API_BASE = 'https://api.nowpayments.io/v1';

	private string $api_key;

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * @param array $args {
	 *   @type string $amount       Decimal string, e.g. "51.50".
	 *   @type string $currency     ISO code, e.g. "USD".
	 *   @type string $order_id     This site's own order id, echoed back on every IPN.
	 *   @type string $description  Shown on NOWPayments' hosted invoice page.
	 *   @type string $ipn_url      Where NOWPayments posts payment status updates.
	 *   @type string $success_url  Where the customer lands after paying.
	 *   @type string $cancel_url   Where the customer lands if they back out.
	 * }
	 * @return array{id:string,invoice_url:string}|\WP_Error
	 */
	public function create_invoice( array $args ) {
		$response = $this->call( 'POST', '/invoice', [
			'price_amount'      => (float) $args['amount'],
			'price_currency'    => strtolower( $args['currency'] ),
			'order_id'          => $args['order_id'],
			'order_description' => $args['description'],
			'ipn_callback_url'  => $args['ipn_url'],
			'success_url'       => $args['success_url'],
			'cancel_url'        => $args['cancel_url'],
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return [
			'id'          => (string) ( $response['id'] ?? '' ),
			'invoice_url' => (string) ( $response['invoice_url'] ?? '' ),
		];
	}

	/** @return array|\WP_Error The decoded response body on success. */
	private function call( string $method, string $path, array $body = [] ) {
		if ( '' === $this->api_key ) {
			return new \WP_Error( 'yeffoprint_nowpayments_no_key', __( 'No NOWPayments API key is configured.', 'yeffoprint-core' ) );
		}

		$response = wp_remote_request( self::API_BASE . $path, [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'x-api-key'    => $this->api_key,
				'Content-Type' => 'application/json',
			],
			'body'    => empty( $body ) ? null : wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'yeffoprint_nowpayments_bad_response', __( 'Unexpected response from NOWPayments.', 'yeffoprint-core' ) );
		}

		if ( $code >= 400 ) {
			$detail = (string) ( $data['message'] ?? wp_json_encode( $data ) );
			return new \WP_Error( 'yeffoprint_nowpayments_api_error', sprintf(
				/* translators: %s: error detail from NOWPayments */
				__( 'NOWPayments API error: %s', 'yeffoprint-core' ),
				$detail
			) );
		}

		return $data;
	}
}
