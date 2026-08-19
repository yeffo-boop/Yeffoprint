<?php
/**
 * REST controller exposing the bulk PackageAssignmentService.
 *
 * @package Automattic\WCShipping\PackageAssignment
 */

namespace Automattic\WCShipping\PackageAssignment;

use Automattic\WCShipping\Connect\WC_Connect_Functions;
use Automattic\WCShipping\FeatureFlags\FeatureFlags;
use Automattic\WCShipping\WCShippingRESTController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller that suggests a single shipping package per order for a
 * batch of orders, gated by the bulk_labels feature flag.
 */
class PackageAssignmentRESTController extends WCShippingRESTController {

	/**
	 * Maximum number of orders allowed in a single auto-assign request.
	 */
	private const BATCH_SIZE_CAP = 25;

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'label/auto-assign-packages';

	/**
	 * Service that resolves the package suggestion for each order.
	 *
	 * @var PackageAssignmentService
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param PackageAssignmentService $service Package assignment service.
	 */
	public function __construct( PackageAssignmentService $service ) {
		$this->service = $service;
	}

	/**
	 * Register API routes. Only registers when the bulk_labels feature flag is on.
	 *
	 * @return void
	 */
	public function register_routes() {
		if ( ! FeatureFlags::is_bulk_labels_enabled() ) {
			return;
		}

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'auto_assign_packages' ),
					'permission_callback' => array( WC_Connect_Functions::class, 'user_can_manage_labels' ),
				),
			)
		);
	}

	/**
	 * Handle POST /wcshipping/v1/label/auto-assign-packages.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|WP_Error REST response with per-order assignment results, or a validation error.
	 */
	public function auto_assign_packages( WP_REST_Request $request ) {
		$params    = $request->get_json_params();
		$order_ids = $params['order_ids'] ?? null;

		if ( ! is_array( $order_ids ) || empty( $order_ids ) ) {
			return $this->invalid_batch_payload(
				__( 'order_ids must be a non-empty array of positive integers.', 'woocommerce-shipping' )
			);
		}

		if ( count( $order_ids ) > self::BATCH_SIZE_CAP ) {
			return new WP_Error(
				'batch_too_large',
				sprintf(
					/* translators: %d: maximum number of orders allowed in a single batch. */
					__( 'Batch exceeds the maximum of %d orders.', 'woocommerce-shipping' ),
					self::BATCH_SIZE_CAP
				),
				array( 'status' => 400 )
			);
		}

		$sanitized = array();
		foreach ( $order_ids as $candidate ) {
			if ( ! is_int( $candidate ) && ! ( is_string( $candidate ) && ctype_digit( $candidate ) ) ) {
				return $this->invalid_batch_payload(
					__( 'order_ids must be a non-empty array of positive integers.', 'woocommerce-shipping' )
				);
			}

			$id = (int) $candidate;
			if ( $id <= 0 ) {
				return $this->invalid_batch_payload(
					__( 'order_ids must be a non-empty array of positive integers.', 'woocommerce-shipping' )
				);
			}

			$sanitized[] = $id;
		}

		// The result map is keyed by order_id, so duplicate ids would collapse
		// into a single entry and break request->response cardinality. Reject
		// them up front rather than silently accepting and returning fewer
		// rows than the caller submitted.
		if ( count( $sanitized ) !== count( array_unique( $sanitized, SORT_NUMERIC ) ) ) {
			return $this->invalid_batch_payload(
				__( 'order_ids must contain unique positive integers.', 'woocommerce-shipping' )
			);
		}

		return rest_ensure_response( $this->service->assign_for_orders( $sanitized ) );
	}

	/**
	 * Build a 400 response for an invalid batch payload.
	 *
	 * The four input-validation branches in `auto_assign_packages()` all
	 * surface the same `invalid_batch_payload` error code at HTTP 400; this
	 * helper keeps the construction in one place so the code, status, and
	 * shape stay aligned even if the per-branch message text drifts.
	 *
	 * @param string $message Translated, branch-specific error message.
	 *
	 * @return WP_Error
	 */
	private function invalid_batch_payload( string $message ): WP_Error {
		return new WP_Error(
			'invalid_batch_payload',
			$message,
			array( 'status' => 400 )
		);
	}
}
