<?php
/**
 * Intercepts FedEx TOS errors from the Connect Server.
 *
 * The live API client (in classes/) only passes through the UPS DAP TOS error code
 * (`missing_upsdap_terms_of_service_acceptance`). All other error codes get formatted
 * into a generic message that strips the code. This interceptor catches the HTTP
 * response before the API client processes it, and replaces the response body's
 * `code` field so the API client's existing passthrough logic handles FedEx TOS
 * errors correctly. The original FedEx code is preserved in the `data` property.
 *
 * @package Automattic\WCShipping\Carrier\FedEx
 */

namespace Automattic\WCShipping\Carrier\FedEx;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FedEx TOS error interceptor.
 */
class FedExTosErrorInterceptor {

	private const FEDEX_TOS_CODE  = 'missing_fedex_terms_of_service_acceptance';
	private const UPSDAP_TOS_CODE = 'missing_upsdap_terms_of_service_acceptance';

	/**
	 * Register the HTTP response filter.
	 */
	public static function init() {
		add_filter( 'http_response', array( __CLASS__, 'intercept_fedex_tos_error' ), 10, 3 );
	}

	/**
	 * If the Connect Server responds with the FedEx TOS error, rewrite the
	 * response body `code` to `missing_upsdap_terms_of_service_acceptance` so
	 * the API client's existing TOS passthrough preserves it as a typed WP_Error.
	 * The real code is stored in `data.carrier_tos_code` for the frontend.
	 *
	 * @param array  $response    HTTP response.
	 * @param array  $parsed_args Request arguments.
	 * @param string $url         Request URL.
	 * @return array Modified response.
	 */
	public static function intercept_fedex_tos_error( $response, $parsed_args, $url ) {
		if ( ! defined( 'WOOCOMMERCE_CONNECT_SERVER_URL' ) ) {
			return $response;
		}

		$server_url = apply_filters( 'wcshipping_server_url', WOOCOMMERCE_CONNECT_SERVER_URL );
		if ( ! is_string( $server_url ) || '' === $server_url || 0 !== strpos( $url, $server_url ) ) {
			return $response;
		}

		if ( 403 !== wp_remote_retrieve_response_code( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return $response;
		}

		$decoded = json_decode( $body );
		if ( ! is_object( $decoded ) || ! property_exists( $decoded, 'code' ) ) {
			return $response;
		}

		if ( self::FEDEX_TOS_CODE !== $decoded->code ) {
			return $response;
		}

		// Store the original code and swap in the UPS DAP code so the API
		// client's TOS passthrough (in classes/) returns a typed WP_Error
		// with the code intact instead of formatting it away.
		if ( ! isset( $decoded->data ) || ! is_object( $decoded->data ) ) {
			$decoded->data = new \stdClass();
		}
		$decoded->data->carrier_tos_code = self::FEDEX_TOS_CODE;
		$decoded->code                   = self::UPSDAP_TOS_CODE;

		$response['body'] = wp_json_encode( $decoded );

		return $response;
	}
}
