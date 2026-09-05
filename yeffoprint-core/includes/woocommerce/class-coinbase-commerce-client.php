<?php
/**
 * Thin wrapper over the Coinbase Commerce REST API
 * (https://docs.cloud.coinbase.com/commerce/reference) — same shape as
 * class-shippo-client.php: one private call() helper, public methods
 * that return an array on success or \WP_Error on failure, no local
 * state beyond the API key.
 *
 * Only one call is needed for checkout: create_charge() creates a
 * "fixed_price" Charge for the order's total, and Coinbase's own
 * hosted_url is where the customer actually picks BTC/USDC/USDT/etc.
 * and pays — this plugin never touches wallet addresses or chain
 * selection itself, exactly like the Shippo panel never touches a
 * label image, only the API that produces one.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Coinbase_Commerce_Client {

	private const API_BASE = 'https://api.commerce.coinbase.com';

	// Coinbase Commerce requires a fixed API version on every request —
	// pinned rather than "latest" so a future Coinbase-side schema change
	// can't silently alter this integration's request/response shape
	// out from under it.
	private const API_VERSION = '2018-03-22';

	private string $api_key;

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * @param array $args {
	 *   @type string $name         Shown on Coinbase's hosted checkout page.
	 *   @type string $description
	 *   @type string $amount       Decimal string, e.g. "51.50".
	 *   @type string $currency     ISO code, e.g. "USD".
	 *   @type string $order_id     This site's own order id, for metadata + webhook lookup.
	 *   @type string $redirect_url Where Coinbase sends the customer after a successful payment.
	 *   @type string $cancel_url   Where Coinbase sends the customer if they back out.
	 * }
	 * @return array{id:string,code:string,hosted_url:string}|\WP_Error
	 */
	public function create_charge( array $args ) {
		$response = $this->call( 'POST', '/charges', [
			'name'         => $args['name'],
			'description'  => $args['description'],
			'pricing_type' => 'fixed_price',
			'local_price'  => [
				'amount'   => $args['amount'],
				'currency' => $args['currency'],
			],
			'metadata'     => [ 'order_id' => $args['order_id'] ],
			'redirect_url' => $args['redirect_url'],
			'cancel_url'   => $args['cancel_url'],
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return [
			'id'         => (string) ( $response['id'] ?? '' ),
			'code'       => (string) ( $response['code'] ?? '' ),
			'hosted_url' => (string) ( $response['hosted_url'] ?? '' ),
		];
	}

	/** @return array|\WP_Error The Charge resource's own `data` object on success. */
	private function call( string $method, string $path, array $body = [] ) {
		if ( '' === $this->api_key ) {
			return new \WP_Error( 'yeffoprint_coinbase_no_key', __( 'No Coinbase Commerce API key is configured.', 'yeffoprint-core' ) );
		}

		$response = wp_remote_request( self::API_BASE . $path, [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'X-CC-Api-Key' => $this->api_key,
				'X-CC-Version' => self::API_VERSION,
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
			return new \WP_Error( 'yeffoprint_coinbase_bad_response', __( 'Unexpected response from Coinbase Commerce.', 'yeffoprint-core' ) );
		}

		if ( $code >= 400 ) {
			$detail = (string) ( $data['error']['message'] ?? wp_json_encode( $data ) );
			return new \WP_Error( 'yeffoprint_coinbase_api_error', sprintf(
				/* translators: %s: error detail from Coinbase Commerce */
				__( 'Coinbase Commerce API error: %s', 'yeffoprint-core' ),
				$detail
			) );
		}

		return is_array( $data['data'] ?? null ) ? $data['data'] : [];
	}
}
