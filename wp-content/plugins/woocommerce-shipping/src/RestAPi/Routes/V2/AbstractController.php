<?php
/**
 * Abstract REST Controller for WooCommerce Shipping REST API V2.
 * Implements functionality that applies to all route controllers.
 *
 * @todo Extend WooCommerce V4 AbstractController.
 *
 * @package Automattic\WCShipping
 */

declare( strict_types=1 );

namespace Automattic\WCShipping\RestApi\Routes\V2;

use WP_REST_Request;
use WP_Error;
use WP_Http;
use Automattic\WCShipping\WCShippingRESTController;

defined( 'ABSPATH' ) || exit;

/**
 * AbstractController class.
 */
abstract class AbstractController extends WCShippingRESTController {
	/**
	 * Shared error codes.
	 */
	const INVALID_ID          = 'invalid_id';
	const RESOURCE_EXISTS     = 'resource_exists';
	const CANNOT_CREATE       = 'cannot_create';
	const CANNOT_DELETE       = 'cannot_delete';
	const CANNOT_TRASH        = 'cannot_trash';
	const TRASH_NOT_SUPPORTED = 'trash_not_supported';

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcshipping/v2';

	/**
	 * Get the item response.
	 *
	 * @param mixed           $item    WooCommerce representation of the item.
	 * @param WP_REST_Request $request Request object.
	 * @return array The item response.
	 */
	abstract protected function get_item_response( $item, WP_REST_Request $request ): array;

	/**
	 * Prepares the item for the REST response. Controllers do not need to override this method as they can define a
	 * get_item_response method to prepare items. This method will take care of filter hooks.
	 *
	 * @param mixed           $item    WordPress representation of the item.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function prepare_item_for_response( $item, $request ) {
		$response_data = $this->get_item_response( $item, $request );
		$response_data = $this->add_additional_fields_to_object( $response_data, $request );
		$response_data = $this->filter_response_by_context( $response_data, $request['context'] ?? 'view' );

		return rest_ensure_response( $response_data );
	}

	/**
	 * Get the error prefix for errors.
	 *
	 * Example: woocommerce_rest_api_v4_orders_
	 *
	 * @return string The error prefix.
	 * @since 10.2.0
	 */
	protected function get_error_prefix(): string {
		return 'woocommerce_rest_api_v4_' . str_replace( '-', '_', $this->rest_base ) . '_';
	}

	/**
	 * Get route response when something went wrong.
	 *
	 * @param string $error_code String based error code.
	 * @param string $error_message User facing error message.
	 * @param int    $http_status_code HTTP status. Defaults to 400.
	 * @param array  $additional_data Extra data (key value pairs) to expose in the error response.
	 * @return WP_Error WP Error object.
	 * @since 10.2.0
	 */
	protected function get_route_error_response( string $error_code, string $error_message, int $http_status_code = WP_Http::BAD_REQUEST, array $additional_data = array() ): WP_Error {
		if ( empty( $error_code ) ) {
			$error_code = 'invalid_request';
		}

		if ( empty( $error_message ) ) {
			$error_message = __( 'An error occurred while processing your request.', 'woocommerce-shipping' );
		}

		return new WP_Error(
			$error_code,
			$error_message,
			array_merge(
				$additional_data,
				array( 'status' => $http_status_code )
			)
		);
	}

	/**
	 * Get route response when something went wrong and the supplied error is a WP_Error.
	 *
	 * @param WP_Error $error_object The WP_Error object containing the error.
	 * @param int      $http_status_code HTTP status. Defaults to 400.
	 * @param array    $additional_data Extra data (key value pairs) to expose in the error response.
	 * @return WP_Error WP Error object.
	 * @since 10.2.0
	 */
	protected function get_route_error_response_from_object( WP_Error $error_object, int $http_status_code = WP_Http::BAD_REQUEST, array $additional_data = array() ): WP_Error {
		if ( ! $error_object instanceof WP_Error ) {
			return $this->get_route_error_response( 'invalid_error_object', __( 'Invalid error object provided.', 'woocommerce-shipping' ), $http_status_code, $additional_data );
		}

		$error_object->add_data( array_merge( $additional_data, array( 'status' => $http_status_code ) ) );
		return $error_object;
	}

	/**
	 * Returns an authentication error for a given HTTP verb.
	 *
	 * @param string $method HTTP method.
	 * @return WP_Error|false WP Error object or false if no error is found.
	 */
	protected function get_authentication_error_by_method( string $method ) {
		$errors = array(
			'GET'    => array(
				'code'    => $this->get_error_prefix() . 'cannot_view',
				'message' => __( 'Sorry, you cannot view resources.', 'woocommerce-shipping' ),
			),
			'POST'   => array(
				'code'    => $this->get_error_prefix() . 'cannot_create',
				'message' => __( 'Sorry, you cannot create resources.', 'woocommerce-shipping' ),
			),
			'PUT'    => array(
				'code'    => $this->get_error_prefix() . 'cannot_update',
				'message' => __( 'Sorry, you cannot update resources.', 'woocommerce-shipping' ),
			),
			'PATCH'  => array(
				'code'    => $this->get_error_prefix() . 'cannot_update',
				'message' => __( 'Sorry, you cannot update resources.', 'woocommerce-shipping' ),
			),
			'DELETE' => array(
				'code'    => $this->get_error_prefix() . 'cannot_delete',
				'message' => __( 'Sorry, you cannot delete resources.', 'woocommerce-shipping' ),
			),
		);

		if ( ! isset( $errors[ $method ] ) ) {
			return false;
		}

		return new WP_Error(
			$errors[ $method ]['code'],
			$errors[ $method ]['message'],
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Get an error response for a given error code.
	 *
	 * @param string $error_code The error code.
	 * @return WP_Error WP Error object.
	 */
	protected function get_route_error_by_code( string $error_code ): WP_Error {
		$error_messages    = array(
			self::INVALID_ID          => __( 'Invalid ID.', 'woocommerce-shipping' ),
			self::RESOURCE_EXISTS     => __( 'Resource already exists.', 'woocommerce-shipping' ),
			self::CANNOT_CREATE       => __( 'Cannot create resource.', 'woocommerce-shipping' ),
			self::CANNOT_DELETE       => __( 'Cannot delete resource.', 'woocommerce-shipping' ),
			self::CANNOT_TRASH        => __( 'Cannot trash resource.', 'woocommerce-shipping' ),
			self::TRASH_NOT_SUPPORTED => __( 'Trash not supported.', 'woocommerce-shipping' ),
		);
		$http_status_codes = array(
			self::INVALID_ID          => WP_Http::NOT_FOUND,
			self::RESOURCE_EXISTS     => WP_Http::BAD_REQUEST,
			self::CANNOT_CREATE       => WP_Http::INTERNAL_SERVER_ERROR,
			self::CANNOT_DELETE       => WP_Http::INTERNAL_SERVER_ERROR,
			self::CANNOT_TRASH        => WP_Http::GONE,
			self::TRASH_NOT_SUPPORTED => WP_Http::NOT_IMPLEMENTED,
		);
		return $this->get_route_error_response(
			$this->get_error_prefix() . $error_code,
			$error_messages[ $error_code ] ?? __( 'An error occurred while processing your request.', 'woocommerce-shipping' ),
			$http_status_codes[ $error_code ] ?? WP_Http::BAD_REQUEST
		);
	}
}
