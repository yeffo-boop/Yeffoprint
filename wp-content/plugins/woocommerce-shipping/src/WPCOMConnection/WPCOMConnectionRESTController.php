<?php
/**
 * Class WPCOMConnectionRESTController.
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\WPCOMConnection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WCShipping\Connect\WC_Connect_Functions;
use Automattic\WCShipping\Connect\WC_Connect_Jetpack;
use Automattic\WCShipping\Connect\WC_Connect_Nux;
use Automattic\WCShipping\Connect\WC_Connect_Options;
use Automattic\WCShipping\WCShippingRESTController;
use WP_Error;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class WPCOMConnectionRESTController.
 */
class WPCOMConnectionRESTController extends WCShippingRESTController {

	/**
	 * The base REST route
	 *
	 * @var string
	 */
	protected $rest_base = 'wpcom-connection';

	/**
	 * Register API routes
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_handler' ),
					'permission_callback' => array( WC_Connect_Functions::class, 'user_can_manage_labels' ),
					'args'                => array(
						'return_url' => array(
							'type'              => 'string',
							'description'       => __( 'Return URL: The location the user should return to after authorizing the site on WordPress.com.', 'woocommerce-shipping' ),
							'required'          => true,
							'sanitize_callback' => 'esc_url_raw',
							'validate_callback' => 'wp_http_validate_url',
						),
						'source'     => array(
							'type'              => 'string',
							'description'       => __( 'A string representative of where the connection was initiated.', 'woocommerce-shipping' ),
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);
	}

	/**
	 * Create WPCOM connection
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_handler( WP_REST_Request $request ) {
		$source = ! empty( $request->get_param( 'source' ) ) ? $request->get_param( 'source' ) : 'WPCOMConnectionRESTController::post_handler';

		try {
			WC_Connect_Nux::accept_tos( $source );

			$return_url = add_query_arg(
				array(
					WC_Connect_Nux::AUTH_SUCCESS_SOURCE_RETURN_PARAM => $source,
					WC_Connect_Nux::AUTH_SUCCESS_NONCE_RETURN_PARAM  => wp_create_nonce( WC_Connect_Nux::AUTH_SUCCESS_NONCE_ACTION ),
				),
				$request->get_param( 'return_url' )
			);

			// Bail early if we already have a connection, which means the store just need to register
			// that they accepted our ToS.
			if ( WC_Connect_Jetpack::is_connected() ) {
				return rest_ensure_response(
					array(
						'redirect_url' => $return_url,
					)
				);
			}

			$auth_url = WC_Connect_Jetpack::connect_site( $return_url, $source, false );

			if ( is_wp_error( $auth_url ) ) {
				return rest_ensure_response( $auth_url );
			}

			return rest_ensure_response(
				array(
					'redirect_url' => $auth_url,
				)
			);
		} catch ( \Exception $e ) {
			return rest_ensure_response(
				new WP_Error(
					'wpcom_connection_error',
					$e->getMessage(),
					array( 'status' => 400 )
				)
			);
		}
	}
}
