<?php

namespace Automattic\WCShipping\Connect;

use WP_Error;

// No direct access please
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WOOCOMMERCE_CONNECT_SERVER_URL' ) ) {
	define( 'WOOCOMMERCE_CONNECT_SERVER_URL', 'https://api.woocommerce.com/' );
}

require_once plugin_basename( 'class-wc-connect-api-client.php' );
class WC_Connect_API_Client_Live extends WC_Connect_API_Client {

	protected function request( $method, $path, $body = array() ) {

		// TODO - incorporate caching for repeated identical requests
		if ( ! class_exists( '\Automattic\Jetpack\Connection\Manager' ) && ! class_exists( '\Automattic\Jetpack\Connection\Tokens' ) ) {
			return new WP_Error(
				'jetpack_data_class_not_found',
				__( 'Unable to send request to WooCommerce Shipping server. Jetpack_Data was not found.', 'woocommerce-shipping' )
			);
		}

		if ( ! method_exists( '\Automattic\Jetpack\Connection\Manager', 'get_access_token' ) && ! method_exists( '\Automattic\Jetpack\Connection\Tokens', 'get_access_token' ) ) {
			return new WP_Error(
				'jetpack_data_get_access_token_not_found',
				__( 'Unable to send request to WooCommerce Shipping server. Jetpack connection does not implement get_access_token.', 'woocommerce-shipping' )
			);
		}

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'request_body_should_be_array',
				__( 'Unable to send request to WooCommerce Shipping server. Body must be an array.', 'woocommerce-shipping' )
			);
		}

		$url = trailingslashit( WOOCOMMERCE_CONNECT_SERVER_URL );
		$url = apply_filters( 'wcshipping_server_url', $url );
		$url = trailingslashit( $url ) . ltrim( $path, '/' );

		// Add useful system information to requests that contain bodies
		if ( in_array( $method, array( 'POST', 'PUT' ) ) ) {
			$body = $this->request_body( $body );
			$body = wp_json_encode( apply_filters( 'wcshipping_api_client_body', $body ) );

			if ( ! $body ) {
				return new WP_Error(
					'unable_to_json_encode_body',
					__( 'Unable to encode body for request to WooCommerce Shipping server.', 'woocommerce-shipping' )
				);
			}
		}

		$headers = $this->request_headers();
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$http_timeout = 60; // 1 minute
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( $http_timeout + 10 );
		}
		$args = array(
			'headers'     => $headers,
			'method'      => $method,
			'body'        => $body,
			'redirection' => 0,
			'compress'    => true,
			'timeout'     => $http_timeout,
		);
		$args = apply_filters( 'wcshipping_request_args', $args );

		$response      = wp_remote_request( $url, $args );
		$response_code = wp_remote_retrieve_response_code( $response );

		// If the received response is not JSON, return the raw response.
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( false === strpos( $content_type, 'application/json' ) ) {
			if ( 200 != $response_code ) {
				return new WP_Error(
					'wcc_server_error',
					sprintf(
						// translators: %d: HTTP response code
						__( 'Error: The WooCommerce Shipping server returned HTTP code: %d', 'woocommerce-shipping' ),
						$response_code
					),
					array(
						'response_status_code' => $response_code,
					)
				);
			}
			return $response;
		}

		$response_body = wp_remote_retrieve_body( $response );
		if ( ! empty( $response_body ) ) {
			$response_body = json_decode( $response_body );
		}

		if ( 200 != $response_code ) {
			if ( empty( $response_body ) ) {
				return new WP_Error(
					'wcc_server_empty_response',
					sprintf(
						// translators: %d: HTTP response code
						__( 'Error: The WooCommerce Shipping server returned ( %d ) and an empty response body.', 'woocommerce-shipping' ),
						$response_code
					),
					array(
						'response_status_code' => $response_code,
					)
				);
			}

			$error   = property_exists( $response_body, 'error' ) ? $response_body->error : '';
			$code    = property_exists( $response_body, 'code' ) ? $response_body->code : '';
			$message = property_exists( $response_body, 'message' ) ? $response_body->message : '';
			$data    = property_exists( $response_body, 'data' ) ? (array) $response_body->data : array();

			$data['response_status_code'] = $response_code;

			// Prevent formatting of the ToS error so we can react to it in React.
			if ( 'missing_upsdap_terms_of_service_acceptance' === $code ) {
				$data['status'] = $response_code;

				return new WP_Error(
					$code,
					$message,
					$data
				);
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

		return $response_body;
	}
}
