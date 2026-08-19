<?php

namespace Automattic\WCShipping\LabelPurchase;

use Automattic\WCShipping\Connect\WC_Connect_Logger;
use Automattic\WCShipping\WCShippingRESTController;
use Automattic\WCShipping\Fulfillments\ShippingFulfillmentsDataStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use Automattic\WCShipping\Utils;

class LabelStatusController extends WCShippingRESTController {

	protected $rest_base = 'label/status/(?P<order_id>\d+)/(?P<label_id>\d+)';

	/**
	 * @var LabelPurchaseService
	 */
	protected $label_service;
	/**
	 * @var WC_Connect_Logger
	 */
	protected $logger;

	/**
	 * @var ShippingFulfillmentsDataStore
	 */
	protected $shipping_fulfillments_data_store;

	public function __construct(
		LabelPurchaseService $label_service,
		WC_Connect_Logger $logger,
		ShippingFulfillmentsDataStore $shipping_fulfillments_data_store
	) {
		$this->label_service                    = $label_service;
		$this->logger                           = $logger;
		$this->shipping_fulfillments_data_store = $shipping_fulfillments_data_store;
	}

	/**
	 * Register API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_labels_status' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);
	}

	public function get_labels_status( WP_REST_Request $request ) {
		list( $label_id, $order_id ) = $this->get_and_check_request_params( $request, array( 'label_id', 'order_id' ) );
		$response                    = $this->label_service->get_status( $label_id );
		if ( is_wp_error( $response ) ) {
			$error = new WP_Error(
				$response->get_error_code(),
				$response->get_error_message(),
				array( 'message' => $response->get_error_message() )
			);
			$this->logger->log( $error, __CLASS__ );

			return $error;
		}

		$label = $this->label_service->update_order_label( $order_id, $response->label );

		return array(
			'success' => true,
			'label'   => $label,
		);
	}
}
