<?php

namespace Automattic\WCShipping\Carrier\UPSDAP;

use Automattic\WCShipping\Exceptions\RESTRequestException;
use Automattic\WCShipping\WCShippingRESTController;
use WP_REST_Server;

class UPSDAPCarrierStrategyRESTController extends WCShippingRESTController {

	protected $rest_base = 'carrier-strategy/upsdap';

	public function __construct( UPSDAPCarrierStrategyService $upsdap_carrier_service ) {
		$this->upsdap_carrier_service = $upsdap_carrier_service;
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);
	}

	/**
	 * Error codes that indicate client validation errors (HTTP 400).
	 * All other error codes are treated as server errors (HTTP 500).
	 */
	private const CLIENT_ERROR_CODES = array(
		'invalid_user_email',
	);

	public function update( $request ) {
		try {
			[
				$origin,
				$confirmed,
			] = $this->get_and_check_request_params( $request, array( 'origin', 'confirmed' ) );
		} catch ( RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		$response = $this->upsdap_carrier_service->update_strategies( $origin, array( 'tos' => $confirmed ) );

		if ( is_wp_error( $response ) ) {
			$error_code = $response->get_error_code();

			// Use 400 for client validation errors, 500 for server errors.
			$status_code = in_array( $error_code, self::CLIENT_ERROR_CODES, true ) ? 400 : 500;

			return new \WP_REST_Response(
				array(
					'success' => false,
					'code'    => $error_code,
					'message' => $response->get_error_message(),
				),
				$status_code
			);
		}

		return rest_ensure_response(
			array(
				'success'   => true,
				'confirmed' => (bool) $confirmed,
			)
		);
	}
}
