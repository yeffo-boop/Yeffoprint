<?php
/**
 * UPS DAP carrier strategy service.
 *
 * @package Automattic\WCShipping\Carrier\UPSDAP
 */

namespace Automattic\WCShipping\Carrier\UPSDAP;

use Automattic\WCShipping\Connect\WC_Connect_API_Client;

/**
 * Handles UPS DAP Terms of Service acceptance via the Connect Server.
 */
class UPSDAPCarrierStrategyService {

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
	public function __construct(
		WC_Connect_API_Client $api_client
	) {
		$this->api_client = $api_client;
	}

	/**
	 * Sends TOS acceptance for the given origin address to the Connect Server.
	 *
	 * @param array $origin  Origin address data.
	 * @param array $options Additional options for the update.
	 * @return mixed|\WP_Error A success indicator.
	 */
	public function update_strategies( $origin, $options = array() ) {
		$current_user = wp_get_current_user();

		if ( empty( $current_user->user_email ) || ! is_email( $current_user->user_email ) ) {
			return new \WP_Error(
				'invalid_user_email',
				__( 'A valid email address is required to accept UPS Terms of Service. Please update your account email.', 'woocommerce-shipping' )
			);
		}

		$data = array(
			'origin' => array_merge(
				$origin,
				array( 'email' => $current_user->user_email )
			),
		);

		if ( isset( $options['tos'] ) ) {
			$data['carriers'] = array(
				'upsdap' => array(
					'tos' => $options['tos'],
				),
			);
		}

		return $this->api_client->send_tos_acceptance_for_origin_address( $data );
	}
}
