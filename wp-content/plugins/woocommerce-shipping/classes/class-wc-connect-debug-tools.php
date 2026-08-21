<?php

namespace Automattic\WCShipping\Connect;

// No direct access please
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Connect_Debug_Tools {
	protected WC_Connect_API_Client $api_client;

	function __construct( WC_Connect_API_Client $api_client ) {
		$this->api_client = $api_client;

		add_filter( 'woocommerce_debug_tools', array( $this, 'woocommerce_debug_tools' ) );
	}

	function woocommerce_debug_tools( $tools ) {
		$tools['test_wcc_connection'] = array(
			'name'     => __( 'Test your WooCommerce Shipping connection', 'woocommerce-shipping' ),
			'button'   => __( 'Test Connection', 'woocommerce-shipping' ),
			'desc'     => __( 'This will test your WooCommerce Shipping connection to ensure everything is working correctly', 'woocommerce-shipping' ),
			'callback' => array( $this, 'test_connection' ),
		);

		return $tools;
	}

	function test_connection() {
		$test_request = $this->api_client->auth_test();
		if ( $test_request && ! is_wp_error( $test_request ) && $test_request->authorized ) {
			echo '<div class="updated inline"><p>' . esc_html__( 'Your site is successfully communicating to the WooCommerce Shipping API.', 'woocommerce-shipping' ) . '</p></div>';
		} else {
			echo '<div class="error inline"><p>'
			. esc_html__( 'ERROR: Your site has a problem connecting to the WooCommerce Shipping API. Please make sure your Jetpack connection is working.', 'woocommerce-shipping' )
			. '</p></div>';
		}
	}
}
