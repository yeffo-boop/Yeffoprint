<?php

namespace Automattic\WCShipping;

use Automattic\WCShipping\Exceptions\RESTRequestException;
use WC_REST_Controller;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCShippingRESTController class.
 */
abstract class WCShippingRESTController extends WC_REST_Controller {

	/**
	 * WooCommerce Shipping namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcshipping/v1';

	/**
	 * Receives array of required parameter keys,
	 * returns array of request parameters.
	 *
	 * @param WP_REST_Request $request    Incoming WP REST request.
	 * @param array           $param_keys Array of required body parameters. Optional parameters are marked with prefix '?'.
	 *
	 * @throws RESTRequestException If parameter is missing.
	 * @return array Array of parsed body parameters.
	 */
	protected function get_and_check_body_params( $request, $param_keys ) {
		$data        = $request->get_json_params();
		$params_list = array();
		foreach ( $param_keys as $param_key ) {
			$key         = str_replace( '?', '', $param_key );
			$is_optional = strpos( $param_key, '?' ) === 0;
			if ( ! isset( $data[ $key ] ) && ! $is_optional ) {
				$message = sprintf(
					// translators: %s: The missing query parameter.
					__( 'Required body parameter is missing: %s', 'woocommerce-shipping' ),
					$key
				);

				/*
				 * We have to escape an exceptions output in case it's not caught internally.
				 *
				 * @link https://github.com/WordPress/WordPress-Coding-Standards/issues/884
				 */
				throw new RESTRequestException( esc_html( $message ) );
			}

			/**
			 * If the parameter is optional, default to null if it's not set, else use the value from the request.
			 * The exception is already thrown above if the parameter is required and not set.
			 */
			$params_list[] = $is_optional ? $data[ $key ] ?? null : $data[ $key ];
		}
		return $params_list;
	}

	/**
	 * Receives array of required parameter keys,
	 * returns array of request parameters.
	 *
	 * @param WP_REST_Request $request    Incoming WP REST request.
	 * @param array           $param_keys Array of required request parameters.
	 *
	 * @throws RESTRequestException If parameter is missing.
	 * @return array Array of parsed request parameters.
	 */
	protected function get_and_check_request_params( $request, $param_keys ) {
		$params_list = array();
		foreach ( $param_keys as $key ) {
			$param = $request->get_param( $key );
			if ( is_null( $param ) ) {
				$message = sprintf(
					// translators: %s: The missing query parameter.
					__( 'Required parameter is missing: %s', 'woocommerce-shipping' ),
					$key
				);

				/*
				 * We have to escape an exceptions output in case it's not caught internally.
				 *
				 * @link https://github.com/WordPress/WordPress-Coding-Standards/issues/884
				 */
				throw new RESTRequestException( esc_html( $message ) );
			}
			$params_list[] = $param;
		}
		return $params_list;
	}

	/**
	 * Find the first duplicated positive `order_id` in a batch payload.
	 *
	 * Malformed items are ignored here so endpoint-specific shape validation can report them using
	 * the response contract for that batch route.
	 *
	 * @param array $items Batch items that may contain `order_id`.
	 * @return int|null Duplicated positive order ID, or null when the batch has no duplicates.
	 */
	protected function get_duplicate_positive_order_id( array $items ): ?int {
		$seen_order_ids = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['order_id'] ) ) {
				continue;
			}

			$candidate = (int) $item['order_id'];
			if ( $candidate <= 0 ) {
				continue;
			}

			if ( isset( $seen_order_ids[ $candidate ] ) ) {
				return $candidate;
			}

			$seen_order_ids[ $candidate ] = true;
		}

		return null;
	}

	/**
	 * Get the route for the controller.
	 *
	 * @return string
	 */
	public function get_rest_base() {
		return $this->rest_base;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function ensure_rest_permission( $request ) {
		return apply_filters(
			'wcshipping_user_can_manage_labels',
			current_user_can( 'manage_woocommerce' ) || current_user_can( 'wcshipping_manage_labels' )
		);
	}

	/**
	 * Attach a hook to prevent API from being cached.
	 */
	public static function prevent_route_caching() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP-Super-Cache constant.
				define( 'DONOTCACHEPAGE', true );
		}

		// Prevent our REST API endpoint responses from being added to browser cache
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'exclude_namespace_from_cache' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Send nocache_headers when any of the namespace endpoints are being accessed.
	 *
	 * This method is used as a callback for the 'rest_post_dispatch' filter.
	 * It checks if the requested route falls under our namespace and, if so,
	 * sends no-cache headers to prevent caching of the API response.
	 *
	 * @param mixed           $result  The result that will be sent to the client.
	 * @param WP_REST_Server  $server  The REST server instance.
	 * @param WP_REST_Request $request The request used to generate the response.
	 *
	 * @return mixed The unmodified $result.
	 */
	public static function exclude_namespace_from_cache( $result, $server, $request ) {
		$namespace = '/wcshipping/';

		// Check if the requested route falls under our namespace
		if ( strpos( $request->get_route(), $namespace ) === 0 ) {
			// Get no-cache headers
			$nocache_headers = wp_get_nocache_headers();

			// Set each no-cache header
			foreach ( $nocache_headers as $header => $value ) {
				$server->send_header( $header, $value );
			}
			$server->send_header( 'Pragma', 'no-cache' );
		}

		return $result;
	}
}
