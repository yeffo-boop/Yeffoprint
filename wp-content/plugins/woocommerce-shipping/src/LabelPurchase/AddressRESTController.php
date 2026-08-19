<?php
/**
 * Class AddressRESTController
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\LabelPurchase;

use Automattic\WCShipping\Exceptions\RESTRequestException;
use Automattic\WCShipping\OriginAddresses\OriginAddressService;
use Automattic\WCShipping\WCShippingRESTController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * REST controller for origin and destination address verification.
 */
class AddressRESTController extends WCShippingRESTController {

	/**
	 * API endpoint path.
	 *
	 * @var string
	 */
	protected $rest_base = 'address';

	/**
	 * Address normalization service.
	 *
	 * @var AddressNormalizationService
	 */
	private $normalization_service;

	/**
	 * Origin address service.
	 *
	 * @var OriginAddressService
	 */
	private $origin_address_service;


	/**
	 * REST controller constructor.
	 *
	 * @param AddressNormalizationService $normalization_service Service to manage address normalization.
	 */
	public function __construct(
		AddressNormalizationService $normalization_service,
		OriginAddressService $origin_address_service
	) {
		$this->normalization_service  = $normalization_service;
		$this->origin_address_service = $origin_address_service;
	}

	/**
	 * Register API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/update_origin',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_origin' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<order_id>\d+)/update_destination',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_destination' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<order_id>\d+)/verify_order',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'verify_order_shipping_address' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/normalize',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'normalize_address' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\w]+)', // IDs are generated using uniqid() which returns a 13 character long string
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/origins',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_origin_addresses' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);
	}

	/**
	 * Confirm and update origin address.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error REST response or error.
	 */
	public function update_origin( WP_REST_Request $request ) {
		try {
			[ $origin ] = $this->get_and_check_body_params( $request, array( 'address', 'isVerified' ) );
		} catch ( RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		$clear_store_address_drift = (bool) $request->get_param( 'clearStoreAddressDrift' );

		/**
		 * Always set is_approved to true when updating an origin address.
		 * Note: is_verified indicates whether the verified address is being used.
		 */
		$origin = array_merge(
			$origin,
			array( 'is_approved' => true )
		);

		$response = $this->normalization_service->update_origin_address( $origin );

		if ( $clear_store_address_drift && ! is_wp_error( $response ) && ! empty( $response['success'] ) ) {
			$this->origin_address_service->mark_main_origin_store_address_in_sync();
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Confirm and update destination address.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error REST response or error.
	 */
	public function update_destination( WP_REST_Request $request ) {
		try {
			[ $destination, $is_verified ] = $this->get_and_check_body_params( $request, array( 'address', 'isVerified' ) );
			[ $order_id ]                  = $this->get_and_check_request_params( $request, array( 'order_id' ) );
		} catch ( RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		return rest_ensure_response( $this->normalization_service->update_destination_address( $order_id, $destination, $is_verified ) );
	}

	/**
	 * Verify if shipping destination is normalized,
	 * intended to be called on order summary details page.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error REST response or error.
	 */
	public function verify_order_shipping_address( WP_REST_Request $request ) {
		try {
			[ $order_id ] = $this->get_and_check_request_params( $request, array( 'order_id' ) );
		} catch ( RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		return rest_ensure_response( $this->normalization_service->is_destination_address_verified( $order_id ) );
	}

	/**
	 * Submits address to normalization service and returns response.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error REST response or error.
	 */
	public function normalize_address( WP_REST_Request $request ) {
		try {
			[ $address ] = $this->get_and_check_body_params( $request, array( 'address' ) );
		} catch ( RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		/*
		 * Optional — older clients didn't send it. Default to `destination`
		 * so the request still works for them. The connect server has
		 * accepted `destination` since forever and now also accepts
		 * `origin` per WOOSHIP-2230.
		 */
		$address_type = $request->get_param( 'addressType' );
		$address_type = 'origin' === $address_type ? 'origin' : 'destination';

		return rest_ensure_response(
			$this->normalization_service->get_normalization_response( $address, $address_type )
		);
	}


	/**
	 * Delete an origin address.
	 *
	 * @param  WP_REST_Request $request The request body contains the origin address to delete.
	 * @return WP_Error|WP_REST_Response
	 */
	public function delete( $request ) {
		try {
			[ $id ] = $this->get_and_check_request_params( $request, array( 'id' ) );
		} catch ( RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		$this->origin_address_service->delete_origin_address( wc_clean( $id ) );
		return rest_ensure_response(
			array(
				'success'    => true,
				'deleted_id' => $id,
			)
		);
	}

	/**
	 * Get all origin addresses.
	 *
	 * @return WP_REST_Response|WP_Error REST response or error.
	 */
	public function get_origin_addresses() {
		return rest_ensure_response( $this->origin_address_service->get_origin_addresses() );
	}
}
