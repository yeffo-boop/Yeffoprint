<?php
/**
 * Class LabelPurchaseRESTController
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\LabelPurchase;

use Automattic\WCShipping\Connect\WC_Connect_Functions;
use Automattic\WCShipping\FeatureFlags\FeatureFlags;
use Automattic\WCShipping\WCShippingRESTController;
use Automattic\WCShipping\Exceptions\RESTRequestException;
use Automattic\WCShipping\Validators;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * REST controller for purchasing labels for order.
 */
class LabelPurchaseRESTController extends WCShippingRESTController {

	/**
	 * Maximum number of shipments allowed in a single batch purchase request.
	 */
	private const BATCH_SIZE_CAP = 25;

	/**
	 * API endpoint path.
	 *
	 * @var string
	 */
	protected $rest_base = 'label/purchase';

	/**
	 * Label purchase service.
	 *
	 * @var LabelPurchaseService
	 */
	private $label_service;

	/**
	 * REST controller constructor.
	 *
	 * @param AddressNormalizationService $normalization_service Service to manage address normalization.
	 */
	public function __construct( LabelPurchaseService $label_service ) {
		$this->label_service = $label_service;
	}

	/**
	 * Register API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<order_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_labels' ),
					'permission_callback' => array( WC_Connect_Functions::class, 'user_can_manage_labels' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<order_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'purchase_labels' ),
					'permission_callback' => array( WC_Connect_Functions::class, 'user_can_manage_labels' ),
					'args'                => array(
						'shipment_options' => array(
							'required'    => false, // Provide backward compatibility for clients ( mobile app ) not setting this field.
							'description' => __( 'Extra options for the shipment', 'woocommerce-shipping' ),
							'type'        => 'object',
							'properties'  => array(
								'label_date' => array(
									'type'        => 'string',
									'description' => __( 'ISO 8601 formatted date string for the shipping label', 'woocommerce-shipping' ),
									'format'      => 'date-time',
									'pattern'     => Validators::ISO8601_PATTERN,
								),
							),
						),
						'is_return'        => array(
							'type'        => 'boolean',
							'description' => __( 'Whether this is a return shipment', 'woocommerce-shipping' ),
							'required'    => false,
						),
					),
				),
			)
		);

		// Batch label-purchase route is only registered when bulk label printing is enabled.
		if ( FeatureFlags::is_bulk_labels_enabled() ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/batch',
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'purchase_labels_batch' ),
						'permission_callback' => array( WC_Connect_Functions::class, 'user_can_manage_labels' ),
					),
				)
			);
		}
	}

	/**
	 * Get labels for order.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error REST response or error.
	 */
	public function get_labels( WP_REST_Request $request ) {
		try {
			list( $order_id ) = $this->get_and_check_request_params( $request, array( 'order_id' ) );
		} catch ( RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		return rest_ensure_response( $this->label_service->get_labels( $order_id ) );
	}

	/**
	 * Purchase labels.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error REST response or error.
	 */
	public function purchase_labels( WP_REST_Request $request ) {
		try {
			// TODO: Validate JSON request schema.
			list(
				$origin,
				$destination,
				$packages,
				$selected_rate,
				$selected_rate_options,
				$hazmat,
				$customs,
				$features_supported_by_client,
				$shipment_options,
				$is_return,
				$parent_shipment_id,
			)                 = $this->get_and_check_body_params(
				$request,
				array(
					'origin',
					'destination',
					'packages',
					'selected_rate',
					'selected_rate_options',
					'hazmat',
					'customs',
					'?features_supported_by_client', // Optional parameter.
					'?shipment_options', // Optional parameter.
					'?is_return', // Optional parameter.
					'?parent_shipment_id', // Optional parameter.
				)
			);
			list( $order_id ) = $this->get_and_check_request_params( $request, array( 'order_id' ) );
		} catch ( RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		// Optional parameter for user meta.
		$user_meta = $request->get_json_params()['user_meta'] ?? array();

		return rest_ensure_response(
			$this->label_service->purchase_labels(
				$origin,
				$destination,
				$packages,
				$order_id,
				$selected_rate,
				$selected_rate_options,
				$hazmat,
				$customs,
				$user_meta,
				$features_supported_by_client,
				$shipment_options,
				$is_return,
				$parent_shipment_id,
			)
		);
	}

	/**
	 * Batch purchase handler. Accepts a shared `origin` plus a `shipments` array and returns
	 * results keyed by `order_<id>` (or `invalid_order_<index>` for entries with a missing or
	 * non-positive `order_id`). The string prefix keeps the response object-shaped in JSON for
	 * web and mobile clients regardless of the underlying numeric `order_id`.
	 *
	 * Per-shipment failures are captured as `{ error: { code, message } }` entries rather than
	 * aborting the whole batch. Used by the bulk label printing flow.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function purchase_labels_batch( WP_REST_Request $request ) {
		$payload   = $request->get_json_params();
		$origin    = is_array( $payload ) && isset( $payload['origin'] ) ? $payload['origin'] : null;
		$shipments = is_array( $payload ) && isset( $payload['shipments'] ) ? $payload['shipments'] : null;

		if ( ! is_array( $origin ) || empty( $origin ) ) {
			return new WP_Error(
				'invalid_batch_payload',
				__( 'The batch label-purchase request must include a batch-level `origin` object. Per-shipment origin is not supported in bulk mode.', 'woocommerce-shipping' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_array( $shipments ) || empty( $shipments ) ) {
			return new WP_Error(
				'invalid_batch_payload',
				__( 'The batch label-purchase request must include a non-empty `shipments` array.', 'woocommerce-shipping' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $shipments ) > self::BATCH_SIZE_CAP ) {
			return new WP_Error(
				'batch_too_large',
				sprintf(
					/* translators: %d: maximum number of shipments per batch label-purchase request */
					__( 'Batch label-purchase requests are limited to %d shipments.', 'woocommerce-shipping' ),
					self::BATCH_SIZE_CAP
				),
				array( 'status' => 400 )
			);
		}

		// Reject per-shipment origin: bulk batches are confined to one origin per WOOSHIP-2128.
		// Use array_key_exists so we also reject explicitly-null values, matching the rate-quote
		// controller's behavior and the documented contract ("any shipments[].origin set → 400").
		foreach ( $shipments as $shipment ) {
			if ( is_array( $shipment ) && array_key_exists( 'origin', $shipment ) ) {
				return new WP_Error(
					'invalid_batch_payload',
					__( 'Per-shipment `origin` is not allowed in bulk mode. Move `origin` to the batch root.', 'woocommerce-shipping' ),
					array( 'status' => 400 )
				);
			}
		}

		/**
		 * Reject duplicate `order_id`s before any label purchase call. Results are keyed by
		 * `order_<id>`, and repeated orders would overwrite earlier entries and scalar fulfillment
		 * shipping metadata after the customer had already been charged for both labels.
		 */
		$duplicate_order_id = $this->get_duplicate_positive_order_id( $shipments );
		if ( null !== $duplicate_order_id ) {
			return new WP_Error(
				'invalid_batch_payload',
				sprintf(
					/* translators: %d: duplicated order_id */
					__( 'Duplicate `order_id` %d in batch label-purchase request; each order must appear at most once.', 'woocommerce-shipping' ),
					$duplicate_order_id
				),
				array( 'status' => 400 )
			);
		}

		$results_by_id = $this->label_service->purchase_labels_batch( $origin, $shipments );

		// Service may return a top-level WP_Error (e.g. fulfillment_api_required) instead of a
		// per-order map. Forward it so REST clients see the carried status, not an empty 200.
		if ( is_wp_error( $results_by_id ) ) {
			return $results_by_id;
		}

		$response = array();
		foreach ( $results_by_id as $result_id => $result ) {
			if ( is_wp_error( $result ) ) {
				$response[ $result_id ] = array(
					'error' => array(
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					),
				);
				continue;
			}

			// Normalize successful batch results to the single-order response shape
			// (`{ labels: [...], success: true }`). The service may already return that shape;
			// otherwise it returns a bare labels-meta list which we wrap here.
			$is_normalized          = is_array( $result ) && (
				array_key_exists( 'labels', $result ) || array_key_exists( 'success', $result )
			);
			$response[ $result_id ] = $is_normalized
				? $result
				: array(
					'labels'  => $result,
					'success' => true,
				);
		}

		return rest_ensure_response( $response );
	}
}
