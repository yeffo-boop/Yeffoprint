<?php
namespace Automattic\WCShipping\LegacyAPIControllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}
use WP_REST_Response;
use WP_Error;

class WC_REST_Connect_Subscription_Activate_Controller extends WC_REST_Connect_Base_Controller {
	protected $rest_base = 'connect/subscription/(?P<subscription_key>.+)/activate';

	public function post( $request ) {
		$subscription_key = $request['subscription_key'];

		$response = $this->api_client->activate_subscription( $subscription_key );
		if ( is_wp_error( $response ) ) {
			$this->logger->log( $response, __CLASS__ );
			return $response;
		}

		$activated = wp_remote_retrieve_response_code( $activation_response ) === 200;
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ( ! $activated && ! empty( $body['code'] ) && 'already_connected' === $body['code'] ) ) {
			return new WP_Error(
				'already_active',
				__( 'The subscription is already active.', 'woocommerce-shipping' )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
			)
		);
	}
}
