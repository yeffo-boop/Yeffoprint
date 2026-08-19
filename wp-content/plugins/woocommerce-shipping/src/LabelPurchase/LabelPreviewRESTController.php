<?php
/**
 * Class LabelRateRESTController
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\LabelPurchase;

use Automattic\WCShipping\Connect\WC_Connect_Logger;
use Automattic\WCShipping\WCShippingRESTController;
use WP_REST_Request;
use WP_REST_Server;

/**
 * REST controller for label preview.
 */
class LabelPreviewRESTController extends WCShippingRESTController {
	/**
	 * Route
	 *
	 * @var string
	 */
	protected $rest_base = 'label/preview';

	/**
	 * LabelPrintService class.
	 *
	 * @var LabelPrintService
	 */
	protected $label_print_service;

	/**
	 * Logger for the connect server.
	 *
	 * @var WC_Connect_Logger
	 */
	protected $logger;

	/**
	 * Class constructor.
	 *
	 * @param LabelPrintService $label_print_service Service that has logic to print labels.
	 * @param WC_Connect_Logger $logger Logger class.
	 */
	public function __construct( LabelPrintService $label_print_service, WC_Connect_Logger $logger ) {
		$this->logger              = $logger;
		$this->label_print_service = $label_print_service;
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
					'callback'            => array( $this, 'label_preview' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
					'args'                => array(
						'paper_size' => array(
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => function ( $value, $request, $param ) {
								$is_valid_string = rest_validate_request_arg( $value, $request, $param );

								if ( is_wp_error( $is_valid_string ) ) {
									return $is_valid_string;
								}

								return ! empty( trim( $value ) );
							},
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Retrieve the test label and returns it as a base64 encoded content.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function label_preview( WP_REST_Request $request ) {
		$paper_size = $request->get_param( 'paper_size' );
		$pdf_b64    = $this->label_print_service->get_label_preview_content( $paper_size );

		if ( is_wp_error( $pdf_b64 ) ) {
			$this->logger->log( $pdf_b64, __CLASS__ );
			return $pdf_b64;
		}

		return array(
			'mimeType'   => 'application/pdf',
			'b64Content' => $pdf_b64,
			'success'    => true,
		);
	}
}
