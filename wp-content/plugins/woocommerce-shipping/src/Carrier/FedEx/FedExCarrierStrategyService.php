<?php
/**
 * FedEx carrier strategy service.
 *
 * @package Automattic\WCShipping\Carrier\FedEx
 */

namespace Automattic\WCShipping\Carrier\FedEx;

use Automattic\WCShipping\Connect\WC_Connect_API_Client;

/**
 * Handles FedEx Terms of Service acceptance via the Connect Server.
 */
class FedExCarrierStrategyService {

	/**
	 * API client.
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
	 * Sends FedEx TOS acceptance to the Connect Server.
	 *
	 * @return array|\WP_Error Response array or error.
	 */
	public function accept_tos() {
		$current_user = wp_get_current_user();

		if ( empty( $current_user->user_email ) || ! is_email( $current_user->user_email ) ) {
			return new \WP_Error(
				'invalid_user_email',
				__( 'A valid email address is required to accept FedEx Terms of Service. Please update your account email.', 'woocommerce-shipping' )
			);
		}

		return $this->api_client->send_fedex_tos_acceptance( array( 'email' => $current_user->user_email ) );
	}
}
