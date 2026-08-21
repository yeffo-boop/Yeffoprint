<?php
namespace Automattic\WCShipping\LegacyAPIControllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}
use WP_REST_Response;

/**
 * Retrieve a list of carrier WooCommerce Shipping supports, along with the
 * fields needed for each carrier in order to do carrier account registration.
 */
class WC_REST_Connect_Shipping_Carrier_Types_Controller extends WC_REST_Connect_Base_Controller {
	/**
	 * Carrier-types end point
	 *
	 * @var string
	 */
	protected $rest_base = 'connect/shipping/carrier-types';

	/**
	 * GET request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get() {
		$response = $this->api_client->get_carrier_types();
		if ( is_wp_error( $response ) ) {
			$this->logger->log( $response, __CLASS__ );
			return $response;
		}
		return new WP_REST_Response(
			array(
				'success'  => true,
				'carriers' => $response->carriers,
			)
		);
	}
}
