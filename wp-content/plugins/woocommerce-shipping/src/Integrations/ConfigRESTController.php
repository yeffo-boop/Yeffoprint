<?php
/**
 * Class ConfigRESTController
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WCShipping\Connect\WC_Connect_Account_Settings;
use Automattic\WCShipping\Connect\WC_Connect_Continents;
use Automattic\WCShipping\Exceptions\RESTRequestException;
use Automattic\WCShipping\LabelPurchase\View;
use Automattic\WCShipping\OriginAddresses\OriginAddressService;
use Automattic\WCShipping\Utils;
use Automattic\WCShipping\WCShippingRESTController;
use Exception;
use WP_Error;
use WP_REST_Server;

/**
 * REST controller for config loading.
 */
class ConfigRESTController extends WCShippingRESTController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'config';

	/**
	 * Shipping label view instance.
	 *
	 * @var View
	 */
	private $shipping_label_view;

	/**
	 * Account settings instance.
	 *
	 * @var WC_Connect_Account_Settings
	 */
	protected $account_settings;

	/**
	 * Origin address service instance.
	 *
	 * @var OriginAddressService
	 */
	private $origin_address_service;

	/**
	 * Continents instance.
	 *
	 * @var WC_Connect_Continents
	 */
	private $continents;

	/**
	 * Constructor.
	 *
	 * @param View                        $shipping_label_view Shipping label view instance.
	 * @param WC_Connect_Account_Settings $account_settings Account settings instance.
	 * @param OriginAddressService        $origin_address_service Origin address service instance.
	 */
	public function __construct( View $shipping_label_view, WC_Connect_Account_Settings $account_settings, OriginAddressService $origin_address_service ) {
		$this->shipping_label_view    = $shipping_label_view;
		$this->account_settings       = $account_settings;
		$this->origin_address_service = $origin_address_service;

		$this->continents = new WC_Connect_Continents();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/label-purchase/(?P<order_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);
	}

	/**
	 * Returns shipping label config for the order.
	 *
	 * @param WP_REST_Request $request Incoming WP REST request.
	 *
	 * @return WP_Error|mixed
	 */
	public function get( $request ) {
		try {
			[ $order_id ] = $this->get_and_check_request_params( $request, array( 'order_id' ) );
		} catch ( RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$message = __( 'Order not found', 'woocommerce-shipping' );
			return new WP_Error(
				'order_not_found',
				$message,
				array(
					'success' => false,
					'message' => $message,
				),
			);
		}

		try {
			$config = $this->shipping_label_view->get_meta_boxes_payload( $order, array() );
			return rest_ensure_response(
				array(
					'success'   => true,
					'config'    => $config,
					'scriptUrl' => Utils::get_enqueue_base_url() . 'woocommerce-shipping-plugin.js',
					'id'        => $order_id, // Temporary ID to satisfy the JS side (which expects an object with an ID).
				),
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'error',
				$e->getMessage(),
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
			);
		}
	}

	/**
	 * Returns settings config.
	 *
	 * @return WP_Error|mixed
	 */
	public function get_settings() {
		try {
			$config = array(
				'accountSettings'                   => $this->account_settings->get( true ),
				'carrier_strategies'                => array(
					'upsdap' => array(
						'origin_address' => array(),
					),
				),
				'continents'                        => $this->continents->get(),
				'origin_addresses'                  => $this->origin_address_service->get_origin_addresses(),
				'is_main_origin_in_sync_with_store' => $this->origin_address_service->is_main_origin_address_in_sync_with_store(),
				'formatted_store_address'           => $this->origin_address_service->get_formatted_store_address(),
				'store_address_draft'               => $this->origin_address_service->get_store_details_origin_address_draft(),
			);

			return rest_ensure_response(
				$config
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'error',
				$e->getMessage(),
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
			);
		}
	}
}
