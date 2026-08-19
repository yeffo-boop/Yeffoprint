<?php
namespace Automattic\WCShipping\LabelSettings;

use Automattic\WCShipping\WCShippingRESTController;
use Automattic\WCShipping\Connect\WC_Connect_Functions;
use Automattic\WCShipping\Connect\WC_Connect_Logger;
use WP_REST_Server;
use WP_REST_Request;
use WP_Error;

/**
 * REST API to handle all settings on the status page.
 */
class SelfHelpRestController extends WCShippingRESTController {
	protected $rest_base = 'self-help';

	private $logger;

	public function __construct( WC_Connect_Logger $logger ) {
		$this->logger = $logger;
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_self_help_settings' ),
					'permission_callback' => array( WC_Connect_Functions::class, 'user_can_manage_labels' ),
				),
			)
		);
	}

	public function update_self_help_settings( WP_REST_Request $request ) {
		$settings = $request->get_json_params();

		if (
			empty( $settings )
			|| ! array_key_exists( 'wcc_debug_on', $settings )
			|| ! array_key_exists( 'wcc_logging_on', $settings )
		) {
			return new WP_Error( 'bad_form_data', __( 'Unable to update settings. The form data could not be read.', 'woocommerce-shipping' ), array( 'status' => 400 ) );
		}

		if ( 1 == $settings['wcc_logging_on'] ) {
			$this->logger->enable_logging();
		} else {
			$this->logger->disable_logging();
		}

		if ( 1 == $settings['wcc_debug_on'] ) {
			$this->logger->enable_debug();
		} else {
			$this->logger->disable_debug();
		}

		return rest_ensure_response(
			array(
				'success' => true,
			)
		);
	}
}
