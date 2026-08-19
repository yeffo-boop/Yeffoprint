<?php
/**
 * Service Status REST Controller.
 *
 * Proxies the Connect Server's /service-status endpoint to the frontend.
 *
 * @package Automattic\WCShipping\ServiceData
 */

namespace Automattic\WCShipping\ServiceData;

use Automattic\WCShipping\Connect\WC_Connect_API_Client;
use Automattic\WCShipping\WCShippingRESTController;
use WP_Error;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for fetching service status notices from Connect Server.
 */
class ServiceStatusRESTController extends WCShippingRESTController {

	/**
	 * API endpoint path.
	 *
	 * @var string
	 */
	protected $rest_base = 'service-status';

	/**
	 * API client instance.
	 *
	 * @var WC_Connect_API_Client
	 */
	private $api_client;

	/**
	 * Constructor.
	 *
	 * @param WC_Connect_API_Client $api_client API client instance.
	 */
	public function __construct( WC_Connect_API_Client $api_client ) {
		$this->api_client = $api_client;
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_service_status' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);
	}

	/**
	 * Get service status from Connect Server.
	 *
	 * @return \WP_REST_Response|WP_Error Service status response or error.
	 */
	public function get_service_status() {
		$response = $this->api_client->proxy_request(
			'/service-status',
			array( 'method' => 'GET' )
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'service_status_error',
				__( 'Unable to fetch service status.', 'woocommerce-shipping' ),
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error(
				'service_status_error',
				__( 'Unable to fetch service status.', 'woocommerce-shipping' ),
				array( 'status' => $status_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), false );

		if ( ! $body || ! isset( $body->notices ) ) {
			return rest_ensure_response(
				array(
					'notices' => array(),
				)
			);
		}

		return rest_ensure_response(
			array(
				'notices' => $this->sanitize_notices( $body->notices ),
			)
		);
	}

	/**
	 * Sanitize notice action URLs to allow only http/https schemes.
	 *
	 * @param array $notices Raw notices from Connect Server.
	 * @return array Sanitized notices.
	 */
	private function sanitize_notices( array $notices ): array {
		foreach ( $notices as $notice ) {
			if ( isset( $notice->action ) ) {
				$notice->action = esc_url( $notice->action, array( 'http', 'https' ) );
				if ( empty( $notice->action ) ) {
					unset( $notice->action );
				}
			}
		}

		return $notices;
	}
}
