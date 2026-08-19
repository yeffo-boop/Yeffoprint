<?php
namespace Automattic\WCShipping\LegacyAPIControllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use WP_REST_Response;

class WC_REST_Connect_Service_Data_Refresh_Controller extends WC_REST_Connect_Base_Controller {
	protected $rest_base = 'connect/service-data-refresh';

	/**
	 * @var WC_Connect_Service_Schemas_Store
	 */
	protected $services_schemas_store;

	public function set_service_schemas_store( $services_schemas_store ) {
		$this->services_schemas_store = $services_schemas_store;
	}

	public function post() {
		$result = $this->services_schemas_store->fetch_service_schemas_from_connect_server();
		if ( $result === false ) {
			return new WP_REST_Response(
				array(
					'success' => false,
				),
				500
			);
		}

		$schemas = $this->services_schemas_store->get_service_schemas();

		return new WP_REST_Response(
			array(
				'success'             => true,
				'timestamp'           => intval( $this->services_schemas_store->get_last_fetch_timestamp() ),
				'has_service_schemas' => ! is_null( $schemas ),
			),
			200
		);
	}
}
