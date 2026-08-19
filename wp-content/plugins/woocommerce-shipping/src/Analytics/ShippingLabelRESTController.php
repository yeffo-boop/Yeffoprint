<?php
namespace Automattic\WCShipping\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WCShipping\WCShippingRESTController;
use Automattic\WCShipping\Analytics\LabelsService;

use WP_REST_Server;
use WP_REST_Request;

class ShippingLabelRESTController extends WCShippingRESTController {

	protected $rest_base = 'reports/labels';

	/**
	 * @var LabelsService
	 */
	protected $labels_service;

	public function __construct( LabelsService $labels_service ) {
		$this->labels_service = $labels_service;
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			$this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
					'args'                => array(
						'before'   => array(
							// Validate that the date is a valid ISO date string
							'validate_callback' => function ( $param, $request, $key ) {
								return strtotime( urldecode( $param ) ) !== false;
							},
							'sanitize_callback' => 'sanitize_text_field',
						),
						'after'    => array(
							// Validate that the date is a valid ISO date string
							'validate_callback' => function ( $param, $request, $key ) {
								return strtotime( urldecode( $param ) ) !== false;
							},
							'sanitize_callback' => 'sanitize_text_field',
						),
						'offset'   => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'validate_callback' => function ( $param, $request, $key ) {
								return is_numeric( $param );
							},
							// If the value is not a positive integer, return null for none positive integer
							'sanitize_callback' => function ( $param ) {
								$int_val = intval( $param );
								// None positive integer should be converted to -1 to indicate no limit
								return $int_val > 0 ? $int_val : -1;
							},
						),
						'fields'   => array(
							// Validate that the fields array contains only strings
							'validate_callback' => function ( $param ) {
								return is_array( $param ) && array_reduce(
									$param,
									function ( $carry, $item ) {
										return $carry && is_string( $item );
									},
									true
								);
							},
							'sanitize_callback' => 'wc_clean',
						),
					),
				),
			)
		);
	}

	public function get( WP_REST_Request $request ) {
		try {
			[ $before, $after, $offset, $per_page, $fields ] = $this->get_and_check_request_params(
				$request,
				array(
					'before',
					'after',
					'offset',
					'per_page',
					'fields',
				)
			);
		} catch ( RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		$fields_to_return = ! is_array( $fields )
			? array(
				'created_date',
				'order_id',
				'rate',
				'service_name',
				'refund',
			)
			: $fields;

		return rest_ensure_response(
			$this->labels_service->get_labels_for_period(
				array(
					'before'   => $before,
					'after'    => $after,
					'offset'   => $offset,
					'per_page' => $per_page,
				),
				$fields_to_return
			)
		);
	}
}
