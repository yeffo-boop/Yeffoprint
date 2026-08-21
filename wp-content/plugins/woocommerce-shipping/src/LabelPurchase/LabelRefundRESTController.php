<?php
namespace Automattic\WCShipping\LabelPurchase;

use Automattic\WCShipping\Exceptions\RESTRequestException;
use Automattic\WCShipping\WCShippingRESTController;
use WP_REST_Server;

class LabelRefundRESTController extends WCShippingRESTController {
	protected $rest_base = 'label/refund/(?P<order_id>\d+)/(?P<label_id>\d+)';

	/**
	 * @var LabelPurchaseService
	 */
	private $purchase_service;

	public function __construct(
		LabelPurchaseService $purchase_service
	) {
		$this->purchase_service = $purchase_service;
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
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);
	}

	public function post( $request ) {
		try {
			list( $label_id, $order_id ) = $this->get_and_check_request_params( $request, array( 'label_id', 'order_id' ) );
		} catch ( RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		$response = $this->purchase_service->refund_label( $order_id, $label_id );
		if ( is_wp_error( $response ) ) {
			$response->add_data(
				array(
					'message' => $response->get_error_message(),
				),
				$response->get_error_code()
			);
			return $response;
		}

		$status_response = $this->purchase_service->get_status( $label_id );
		if ( is_wp_error( $status_response ) ) {
			$status_response->add_data(
				array(
					'message' => sprintf(
						'Successful refund, but there was an error getting label status: %s',
						$status_response->get_error_message()
					),
				),
				$status_response->get_error_code()
			);
			return $status_response;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'refund'  => $response->refund,
				'label'   => $status_response->label,
			)
		);
	}
}
