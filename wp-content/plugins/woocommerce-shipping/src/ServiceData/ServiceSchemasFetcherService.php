<?php
/**
 * Service Schemas Fetcher Service
 *
 * Wraps the service schemas fetch logic to handle errors without modifying legacy classes.
 *
 * @package Automattic\WCShipping\ServiceData
 */

namespace Automattic\WCShipping\ServiceData;

use Automattic\WCShipping\Connect\WC_Connect_API_Client;
use Automattic\WCShipping\Connect\WC_Connect_Service_Schemas_Store;

/**
 * Handles fetching service schemas with error monitoring.
 *
 * This service wraps the schemas store fetch operation to monitor for internal
 * plugin errors and display appropriate admin notices.
 */
class ServiceSchemasFetcherService {

	/**
	 * API client instance.
	 *
	 * @var WC_Connect_API_Client
	 */
	private $api_client;

	/**
	 * Services error notice handler.
	 *
	 * @var ServicesErrorNotice|null
	 */
	private $error_notice;

	/**
	 * Service schemas store.
	 *
	 * @var WC_Connect_Service_Schemas_Store
	 */
	private $schemas_store;

	/**
	 * Constructor.
	 *
	 * @param WC_Connect_API_Client            $api_client    API client instance.
	 * @param WC_Connect_Service_Schemas_Store $schemas_store Service schemas store.
	 * @param ServicesErrorNotice|null         $error_notice  Error notice handler.
	 */
	public function __construct(
		WC_Connect_API_Client $api_client,
		WC_Connect_Service_Schemas_Store $schemas_store,
		?ServicesErrorNotice $error_notice = null
	) {
		$this->api_client    = $api_client;
		$this->schemas_store = $schemas_store;
		$this->error_notice  = $error_notice;
	}

	/**
	 * Fetch service schemas with error handling.
	 *
	 * This method delegates to the schemas store for the actual fetch, then
	 * checks for errors. If an error occurred, it makes an additional API call
	 * to get the error details and show the appropriate notice.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function fetch() {
		// Let the schemas store do the actual fetch.
		$result = $this->schemas_store->fetch_service_schemas_from_connect_server();

		if ( false === $result ) {
			// Fetch failed - make an additional call to get the error details.
			$this->handle_fetch_error();
		} elseif ( $this->error_notice ) {
			// Success - clear any existing error notice.
			$this->error_notice->disable_notice();
		}

		return $result;
	}

	/**
	 * Handle a fetch error by checking for internal plugin errors.
	 *
	 * Makes an additional API call to get the actual error details since
	 * the schemas store doesn't expose them.
	 *
	 * @return void
	 */
	private function handle_fetch_error() {
		if ( ! $this->error_notice ) {
			return;
		}

		// Call the API client to get the actual error.
		$response = $this->api_client->get_service_schemas();

		if ( is_wp_error( $response ) ) {
			$error_code    = $response->get_error_code();
			$error_message = $response->get_error_message();

			// Only show notice for internal plugin errors, not server errors.
			if ( ServicesErrorNotice::is_internal_error( $error_code ) ) {
				$this->error_notice->enable_notice( $error_code, $error_message );
			}
		}
	}

	/**
	 * Get the underlying schemas store.
	 *
	 * @return WC_Connect_Service_Schemas_Store
	 */
	public function get_schemas_store() {
		return $this->schemas_store;
	}
}
