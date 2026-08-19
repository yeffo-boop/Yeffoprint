<?php
namespace Automattic\WCShipping\LegacyAPIControllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WCShipping\Loader;
use WP_REST_Response;

/**
 * @deprecated since 1.1.0, see src/Integrations/AssetsRESTController.php
 */
class WC_REST_Connect_Assets_Controller extends WC_REST_Connect_Base_Controller {

	protected $rest_base = 'connect/assets';

	public function get() {

		return new WP_REST_Response(
			array(
				'success' => true,
				'assets'  => array(
					'wcshipping_admin_script' => Loader::get_wcs_admin_script_url(),
					'wcshipping_admin_style'  => Loader::get_wcs_admin_style_url(),
				),
			),
			200
		);
	}
}
