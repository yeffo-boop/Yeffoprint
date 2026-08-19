<?php
/**
 * Class BatchableApiClient
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\Bulk;

use Automattic\WCShipping\Connect\WC_Connect_API_Client_Live;
use WP_Error;

/**
 * Subclass of WC_Connect_API_Client_Live that adds parallel-dispatch helpers for batch flows.
 *
 * Lives in src/ (per project conventions) but extends the legacy classes/ Live client so the
 * Jetpack auth machinery (request_body, request_headers, HMAC signing) can be reused via
 * protected inheritance instead of being re-implemented.
 */
class BatchableApiClient extends WC_Connect_API_Client_Live {

	/**
	 * Concurrency cap for the parallel batch dispatch.
	 *
	 * Tuned to stay within EasyPost's per-second rate limits and to avoid stampeding upstream
	 * carriers with one site's traffic. Conservative for v1; lift after we measure.
	 */
	private const MULTI_CONCURRENCY = 5;

	/**
	 * Per-request HTTP timeout, in seconds. Matches the single-order request() default.
	 */
	private const REQUEST_TIMEOUT = 60;

	/**
	 * Get label rates for many orders in one parallel dispatch.
	 *
	 * @param array $payloads Numerically-indexed list of rate-quote payloads, in the same shape
	 *                        each element passed to get_label_rates() expects.
	 *
	 * @return array Array of decoded responses (object) or WP_Error, aligned with input indices.
	 */
	public function get_label_rates_batch( array $payloads ) {
		return $this->dispatch_post_batch( 'shipping/label/rates', $payloads );
	}

	/**
	 * Purchase labels for many shipments in one parallel dispatch.
	 *
	 * Note: each payload becomes its own per-order BillingDaddy purchase, which means the
	 * merchant sees N Stripe charges. Prefer `send_grouped_label_batch_request()` for the
	 * "one batch = one charge" flow; this method is retained for the rate-quote sibling
	 * (`get_label_rates_batch`) and for any future parallel-dispatch label paths.
	 *
	 * @param array $payloads Numerically-indexed list of shipping-label purchase payloads, in the
	 *                        same shape each element passed to send_shipping_label_request() expects.
	 *
	 * @return array Array of decoded responses (object) or WP_Error, aligned with input indices.
	 */
	public function purchase_labels_batch( array $payloads ) {
		return $this->dispatch_post_batch( 'shipping/label', $payloads );
	}

	/**
	 * Send a single grouped batch label-purchase request to the Connect Server. The
	 * server flattens packages across all shipments into one BillingDaddy purchase, so
	 * the merchant sees one Stripe charge with N line items rather than N per-label
	 * charges. This is the "one batch = one charge" path consumed by the bulk label flow.
	 *
	 * Body shape sent on the wire:
	 *   `{ async, email_receipt, payment_method_id, shipments: [{ order_id, origin,
	 *      destination, packages, shipment_options, is_return }] }`
	 * Only one HTTP call is made — no parallel dispatch, no per-order fan-out.
	 *
	 * @param array $body Multi-shipment payload. Must contain a non-empty `shipments`
	 *                    array and a non-empty `payment_method_id`.
	 *
	 * @return object|WP_Error Decoded per-order response map or a transport-level error.
	 */
	public function send_grouped_label_batch_request( array $body ) {
		if ( empty( $body['payment_method_id'] ) ) {
			return new WP_Error(
				'wcc_missing_payment_method',
				__( 'A payment method is required to purchase labels. Set one in WooCommerce Shipping settings.', 'woocommerce-shipping' )
			);
		}
		if ( empty( $body['shipments'] ) || ! is_array( $body['shipments'] ) ) {
			return new WP_Error(
				'wcc_empty_batch',
				__( 'Cannot purchase a batch with no shipments.', 'woocommerce-shipping' )
			);
		}

		return $this->request( 'POST', '/shipping/labels/batch', $body );
	}

	/**
	 * Generic POST batch dispatcher.
	 *
	 * Builds one HTTP request per payload and fires them all via the Requests library bundled
	 * with WordPress, capped at MULTI_CONCURRENCY in flight. Each response is decoded with the
	 * same JSON + error mapping as the single-order request() in the parent class.
	 *
	 * @param string $path     Relative Connect Server path (no leading slash, e.g. "shipping/label").
	 * @param array  $payloads Numerically-indexed list of body payloads.
	 *
	 * @return array Array of decoded responses (object) or WP_Error, aligned with input indices.
	 */
	private function dispatch_post_batch( string $path, array $payloads ) {
		if ( empty( $payloads ) ) {
			return array();
		}

		// Same Jetpack guards as request(), surfaced once for the whole batch.
		if ( ! class_exists( '\Automattic\Jetpack\Connection\Manager' ) && ! class_exists( '\Automattic\Jetpack\Connection\Tokens' ) ) {
			$error = new WP_Error(
				'jetpack_data_class_not_found',
				__( 'Unable to send request to WooCommerce Shipping server. Jetpack_Data was not found.', 'woocommerce-shipping' )
			);
			return array_fill_keys( array_keys( $payloads ), $error );
		}

		if ( ! method_exists( '\Automattic\Jetpack\Connection\Manager', 'get_access_token' ) && ! method_exists( '\Automattic\Jetpack\Connection\Tokens', 'get_access_token' ) ) {
			$error = new WP_Error(
				'jetpack_data_get_access_token_not_found',
				__( 'Unable to send request to WooCommerce Shipping server. Jetpack connection does not implement get_access_token.', 'woocommerce-shipping' )
			);
			return array_fill_keys( array_keys( $payloads ), $error );
		}

		$base_url = trailingslashit( WOOCOMMERCE_CONNECT_SERVER_URL );
		$base_url = apply_filters( 'wcshipping_server_url', $base_url );
		$url      = trailingslashit( $base_url ) . ltrim( $path, '/' );

		// Worst-case wall time is ceil(N/concurrency) * REQUEST_TIMEOUT when upstream stalls,
		// so scale the PHP time limit accordingly to avoid termination mid-batch.
		if ( function_exists( 'wc_set_time_limit' ) ) {
			$batch_count = (int) ceil( count( $payloads ) / self::MULTI_CONCURRENCY );
			wc_set_time_limit( ( $batch_count * self::REQUEST_TIMEOUT ) + 10 );
		}

		// Resolve per-request options through the same filter the single-order path uses
		// (wcshipping_request_args) so existing integrations adjusting timeout/etc. apply here too.
		$request_options = $this->resolve_batch_request_options();

		// Build per-payload Requests-compatible specs. Headers are built per request because the
		// signature uses a fresh nonce/timestamp from `request_headers()`; sharing one set across
		// the rolling-concurrency dispatch would reuse the same nonce and risk replay rejection.
		$requests    = array();
		$prep_errors = array();
		foreach ( $payloads as $index => $payload ) {
			if ( ! is_array( $payload ) ) {
				$prep_errors[ $index ] = new WP_Error(
					'request_body_should_be_array',
					__( 'Unable to send request to WooCommerce Shipping server. Body must be an array.', 'woocommerce-shipping' )
				);
				continue;
			}

			$body = $this->request_body( $payload );
			$body = wp_json_encode( apply_filters( 'wcshipping_api_client_body', $body ) );
			if ( ! $body ) {
				$prep_errors[ $index ] = new WP_Error(
					'unable_to_json_encode_body',
					__( 'Unable to encode body for request to WooCommerce Shipping server.', 'woocommerce-shipping' )
				);
				continue;
			}

			$headers = $this->request_headers();
			if ( is_wp_error( $headers ) ) {
				$prep_errors[ $index ] = $headers;
				continue;
			}

			$requests[ $index ] = array(
				'url'     => $url,
				'type'    => 'POST',
				'headers' => $headers,
				'data'    => $body,
				'options' => $request_options,
			);
		}

		// Dispatch in parallel via the Requests library.
		$multi_responses = array();
		if ( ! empty( $requests ) ) {
			$requests_class  = class_exists( '\WpOrg\Requests\Requests' )
				? '\WpOrg\Requests\Requests'
				: '\Requests';
			$multi_responses = $requests_class::request_multiple(
				$requests,
				array(
					'multiple_concurrency' => self::MULTI_CONCURRENCY,
				)
			);
		}

		// Parse each response with the same logic as the parent's request().
		$results = array();
		foreach ( $payloads as $index => $payload ) {
			if ( isset( $prep_errors[ $index ] ) ) {
				$results[ $index ] = $prep_errors[ $index ];
				continue;
			}
			$results[ $index ] = $this->parse_multi_response( $multi_responses[ $index ] ?? null );
		}

		return $results;
	}

	/**
	 * Parse a single Requests-library response into the same shape request() returns.
	 *
	 * Mirrors the JSON-decode + error-mapping branches of the parent's request() method.
	 * Lifted here so the parallel path produces results indistinguishable from the sequential
	 * one.
	 *
	 * @param mixed $response Either a Requests response object, an exception, or null.
	 *
	 * @return object|WP_Error
	 */
	private function parse_multi_response( $response ) {
		if ( $response instanceof \WpOrg\Requests\Exception
			|| ( class_exists( '\Requests_Exception' ) && $response instanceof \Requests_Exception ) ) {
			return new WP_Error(
				'wcc_server_request_failed',
				$response->getMessage()
			);
		}

		if ( ! is_object( $response ) || ! isset( $response->status_code ) ) {
			return new WP_Error(
				'wcc_server_no_response',
				__( 'No response received from the WooCommerce Shipping server.', 'woocommerce-shipping' )
			);
		}

		$response_code = (int) $response->status_code;

		// Mirror the live client's content-type guard. Non-JSON error responses (e.g. an HTML 502
		// page from an upstream proxy) surface as `wcc_server_error` with the response code,
		// instead of silently producing a null decode that callers cannot distinguish from an
		// empty success body.
		$content_type = isset( $response->headers['content-type'] ) ? (string) $response->headers['content-type'] : '';
		if ( 200 !== $response_code && false === strpos( $content_type, 'application/json' ) ) {
			return new WP_Error(
				'wcc_server_error',
				sprintf(
					/* translators: %d: HTTP response code */
					__( 'Error: The WooCommerce Shipping server returned HTTP code: %d', 'woocommerce-shipping' ),
					$response_code
				),
				array( 'response_status_code' => $response_code )
			);
		}

		$decoded = ! empty( $response->body ) ? json_decode( $response->body ) : null;

		if ( 200 !== $response_code ) {
			if ( empty( $decoded ) ) {
				return new WP_Error(
					'wcc_server_empty_response',
					sprintf(
						/* translators: %d: HTTP response code */
						__( 'Error: The WooCommerce Shipping server returned ( %d ) and an empty response body.', 'woocommerce-shipping' ),
						$response_code
					),
					array( 'response_status_code' => $response_code )
				);
			}

			$error   = property_exists( $decoded, 'error' ) ? $decoded->error : '';
			$code    = property_exists( $decoded, 'code' ) ? $decoded->code : '';
			$message = property_exists( $decoded, 'message' ) ? $decoded->message : '';
			$data    = property_exists( $decoded, 'data' ) ? (array) $decoded->data : array();

			$data['response_status_code'] = $response_code;

			if ( 'missing_upsdap_terms_of_service_acceptance' === $code ) {
				$data['status'] = $response_code;
				return new WP_Error( $code, $message, $data );
			}

			return new WP_Error(
				'wcc_server_error_response',
				sprintf(
					/* translators: %1$s: error code, %2$s: error message, %3$d: HTTP response code */
					__( 'Error: The WooCommerce Shipping server returned: %1$s %2$s ( %3$d )', 'woocommerce-shipping' ),
					$error,
					$message,
					$response_code
				),
				$data
			);
		}

		return $decoded;
	}

	/**
	 * Build the per-request Requests-library options array, honoring `wcshipping_request_args`.
	 *
	 * The single-order path runs `wp_remote_request()` with an args array that integrations can
	 * tweak via `wcshipping_request_args`. The batch path uses `Requests::request_multiple()`,
	 * which takes a different shape, so we pass the same args through the filter and then map
	 * the keys that survive (timeout today; extend as integration needs surface).
	 *
	 * @return array Options array suitable for a single Requests-library request.
	 */
	private function resolve_batch_request_options(): array {
		$args = array(
			'method'      => 'POST',
			'redirection' => 0,
			'compress'    => true,
			'timeout'     => self::REQUEST_TIMEOUT,
		);
		$args = apply_filters( 'wcshipping_request_args', $args );

		$timeout = isset( $args['timeout'] ) ? (int) $args['timeout'] : self::REQUEST_TIMEOUT;

		return array(
			'timeout' => $timeout > 0 ? $timeout : self::REQUEST_TIMEOUT,
		);
	}
}
