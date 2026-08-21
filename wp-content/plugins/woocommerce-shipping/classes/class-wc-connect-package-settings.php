<?php

namespace Automattic\WCShipping\Connect;

use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WC_Connect_Package_Settings {
	/**
	 * @var WC_Connect_Service_Settings_Store
	 */
	protected $settings_store;

	/**
	 * @var WC_Connect_Service_Schemas_Store
	 */
	protected $service_schemas_store;

	public function __construct(
		WC_Connect_Service_Settings_Store $settings_store,
		WC_Connect_Service_Schemas_Store $service_schemas_store
	) {
		$this->settings_store        = $settings_store;
		$this->service_schemas_store = $service_schemas_store;
	}

	/**
	 * Get store options, package schemas, saved custom packages, and starred predefined packages.
	 *
	 * @param array|null $features_supported_by_client Features supported by the client.
	 *
	 * @return array Package settings.
	 */
	public function get( ?array $features_supported_by_client ) {
		return array(
			'storeOptions' => $this->settings_store->get_store_options(),
			'formSchema'   => array(
				'custom'     => $this->service_schemas_store->get_packages_schema(),
				'predefined' => $this->get_predefined_packages_schema( $features_supported_by_client ),
			),
			'formData'     => array(
				'custom'     => $this->settings_store->get_packages(),
				'predefined' => $this->settings_store->get_predefined_packages(),
			),
		);
	}

	/**
	 * Filters out UPS DAP and FedEx predefined packages schema if it is not supported by the client.
	 * This is only necessary to ensure the filtered out packages are not shown in the UI even if they exist in the local cache.
	 *
	 * @param array|null $features_supported_by_client Features supported by the client.
	 *
	 * @return array|null Predefined packages schema.
	 */
	private function get_predefined_packages_schema( ?array $features_supported_by_client ) {
		$schema = $this->service_schemas_store->get_predefined_packages_schema();

		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		/*
		 * `$features_supported_by_client` can be null.
		 *
		 * It is intentionally not given a default value so every piece of consuming code
		 * has to be intentional about what it expects from the call to `$this::get()`.
		 */
		$features_supported_by_client = $features_supported_by_client ?? array();

		if (
			! in_array( 'upsdap', $features_supported_by_client, true ) &&
			isset( $schema['upsdap'] )
		) {
			unset( $schema['upsdap'] );
		}

		if (
			! in_array( 'fedex', $features_supported_by_client, true ) &&
			isset( $schema['fedex'] )
		) {
			unset( $schema['fedex'] );
		}

		return $schema;
	}
}
