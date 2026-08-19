<?php
namespace Automattic\WCShipping\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WCShipping\Loader;
use Automattic\WCShipping\WCShippingRESTController;
use WP_REST_Server;

class AssetsRESTController extends WCShippingRESTController {

	protected $rest_base = 'assets';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			$this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);
	}

	public function get() {
		return rest_ensure_response(
			array(
				'success' => true,
				'assets'  => array(
					'wcshipping_create_label_script'      => Loader::get_wcs_admin_script_url(),
					'wcshipping_create_label_style'       => Loader::get_wcs_admin_style_url(),
					'wcshipping_shipment_tracking_script' => Loader::get_wcs_shipment_tracking_script_url(),
					'wcshipping_shipment_tracking_style'  => Loader::get_wcs_shipment_tracking_style_url(),
				),
			),
		);
	}
}
