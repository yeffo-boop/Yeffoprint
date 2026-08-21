<?php

namespace Automattic\WCShipping\LabelSettings;

use Automattic\WCShipping\WCShippingRESTController;
use Automattic\WCShipping\Connect\WC_Connect_Service_Schemas_Store;
use Automattic\WCShipping\Connect\WC_Connect_Functions;
use WP_REST_Server;
use WP_REST_Response;

class ServiceDataRefreshRestController extends WCShippingRESTController {
	protected $rest_base = 'service-data-refresh';
	private WC_Connect_Service_Schemas_Store $services_schemas_store;

	public function __construct( WC_Connect_Service_Schemas_Store $services_schemas_store ) {
		$this->services_schemas_store = $services_schemas_store;
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'fetch_service_schemas_from_connect_server' ),
					'permission_callback' => array( WC_Connect_Functions::class, 'user_can_manage_labels' ),
				),
			)
		);
	}

	public function fetch_service_schemas_from_connect_server() {
		$result = $this->services_schemas_store->fetch_service_schemas_from_connect_server();
		if ( $result === false ) {
			return rest_ensure_response(
				new WP_REST_Response(
					array(
						'success' => false,
					),
					500
				)
			);
		}

		$schemas = $this->services_schemas_store->get_service_schemas();

		return rest_ensure_response(
			array(
				'success'             => true,
				'timestamp'           => intval( $this->services_schemas_store->get_last_fetch_timestamp() ),
				'has_service_schemas' => ! is_null( $schemas ),
			)
		);
	}
}
