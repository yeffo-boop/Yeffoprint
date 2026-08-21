<?php
/**
 * REST API Addresses controller
 *
 * Handles route registration, permissions, CRUD operations, and schema definition.
 *
 * @package Automattic\WCShipping
 */

declare( strict_types=1 );

namespace Automattic\WCShipping\RestApi\Routes\V2\Addresses;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_Error;
use Automattic\WCShipping\OriginAddresses\OriginAddressService;
use Automattic\WCShipping\RestApi\Routes\V2\AbstractController;

/**
 * Addresses Controller.
 */
class Controller extends AbstractController {
	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'addresses';

	/**
	 * Origin address service instance.
	 *
	 * @var OriginAddressService
	 */
	protected $origin_address_service;

	/**
	 * Schema class for this route.
	 *
	 * @var AddressSchema
	 */
	protected $item_schema;

	/**
	 * Controller constructor.
	 *
	 * @param OriginAddressService $origin_address_service Origin address service.
	 */
	public function __construct(
		OriginAddressService $origin_address_service
	) {
		$this->origin_address_service = $origin_address_service;
		$this->item_schema            = new AddressSchema();
	}

	/**
	 * Get the item schema.
	 *
	 * @return array
	 */
	protected function get_schema(): array {
		return $this->item_schema->get_item_schema();
	}

	/**
	 * Get the item response.
	 *
	 * @param mixed           $item    Item data.
	 * @param WP_REST_Request $request Request object.
	 * @return array
	 */
	protected function get_item_response( $item, WP_REST_Request $request ): array {
		return $this->item_schema->get_item_response( $item, $request, $this->get_fields_for_response( $request ) );
	}

	/**
	 * Register the routes for addresses.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/store-address-sync',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_store_address_sync' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\w]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);
	}

	/**
	 * Get a collection of addresses.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		$addresses = array();

		foreach ( $this->origin_address_service->get_origin_addresses() as $address ) {
			$addresses[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $address, $request ) );
		}

		return rest_ensure_response( $addresses );
	}

	/**
	 * Create a single item.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function create_item( $request ) {
		$address = $this->item_schema->get_item_response( $request->get_params(), $request );
		// Origin addresses created through this endpoint are always approved by the merchant.
		$address['is_approved'] = true;

		return $this->prepare_item_for_response( $this->origin_address_service->update_origin_addresses( $address ), $request );
	}

	/**
	 * Update a single item.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function update_item( $request ) {
		$addresses = $this->origin_address_service->get_origin_addresses();
		$index     = array_search( $request['id'], array_column( $addresses, 'id' ), true );

		if ( false === $index ) {
			return $this->get_route_error_by_code( self::INVALID_ID );
		}

		/**
		 * Always set is_approved to true when updating an address.
		 * This ensures that any address update results in an approved address.
		 * Note: is_verified is preserved from the existing address as it indicates
		 * whether the verified address (from address validation) is being used.
		 */
		$merged_address = array_merge(
			$addresses[ $index ],
			$request->get_params(),
			array( 'is_approved' => true )
		);

		$address = $this->item_schema->get_item_response( $merged_address, $request );

		return $this->prepare_item_for_response( $this->origin_address_service->update_origin_addresses( $address ), $request );
	}

	/**
	 * Current store-address vs main sender sync state for the address Redux store.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_store_address_sync( $request ) {
		return rest_ensure_response(
			array(
				'is_main_origin_in_sync_with_store' => $this->origin_address_service->is_main_origin_address_in_sync_with_store(),
				'formatted_store_address'           => $this->origin_address_service->get_formatted_store_address(),
				'store_address_draft'               => $this->origin_address_service->get_store_details_origin_address_draft(),
			)
		);
	}

	/**
	 * Delete a single item.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function delete_item( $request ) {
		$addresses = $this->origin_address_service->get_origin_addresses();
		$index     = array_search( $request['id'], array_column( $addresses, 'id' ), true );

		if ( false === $index ) {
			return $this->get_route_error_by_code( self::INVALID_ID );
		}

		$this->origin_address_service->delete_origin_address( $request['id'] );

		return $this->prepare_item_for_response( $addresses[ $index ], $request );
	}
}
