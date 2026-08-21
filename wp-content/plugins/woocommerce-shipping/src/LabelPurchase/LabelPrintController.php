<?php
namespace Automattic\WCShipping\LabelPurchase;

use Automattic\WCShipping\Connect\WC_Connect_API_Client;
use Automattic\WCShipping\Connect\WC_Connect_Logger;
use Automattic\WCShipping\Connect\WC_Connect_Service_Settings_Store;
use Automattic\WCShipping\FeatureFlags\FeatureFlags;
use Automattic\WCShipping\Fulfillments\FulfillmentsService;
use Automattic\WCShipping\Fulfillments\ShippingFulfillment;
use Automattic\WCShipping\Fulfillments\ShippingFulfillmentsDataStore;
use Automattic\WCShipping\Utils;
use Automattic\WCShipping\WCShippingRESTController;
use WP_Error;
use WP_REST_Server;

class LabelPrintController extends WCShippingRESTController {
	protected $rest_base = 'label/print';

	/**
	 * @var WC_Connect_Service_Settings_Store
	 */
	protected $settings_store;

	/**
	 * @var WC_Connect_API_Client
	 */
	protected $api_client;

	/**
	 * @var WC_Connect_Logger
	 */
	protected $logger;

	/**
	 * @var WC_Label_Print_Service
	 */
	protected $label_print_service;

	/**
	 * Fulfillment data store used to verify bulk print requests before forwarding label IDs.
	 *
	 * @var ShippingFulfillmentsDataStore
	 */
	protected $shipping_fulfillments_data_store;

	/**
	 * Fulfillment service used to resolve fulfillment entities.
	 *
	 * @var FulfillmentsService
	 */
	protected $fulfillments_service;

	public function __construct(
		WC_Connect_Service_Settings_Store $settings_store,
		WC_Connect_API_Client $api_client,
		WC_Connect_Logger $logger,
		LabelPrintService $label_print_service,
		ShippingFulfillmentsDataStore $shipping_fulfillments_data_store,
		?FulfillmentsService $fulfillments_service = null
	) {
		$this->settings_store                   = $settings_store;
		$this->api_client                       = $api_client;
		$this->logger                           = $logger;
		$this->label_print_service              = $label_print_service;
		$this->shipping_fulfillments_data_store = $shipping_fulfillments_data_store;
		$this->fulfillments_service             = $fulfillments_service ?? new FulfillmentsService( $shipping_fulfillments_data_store );
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
					'callback'            => array( $this, 'print_label' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/packing-list/(?P<label_id>\d+)/(?P<order_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_packing_list' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
					'args'                => array(
						'label_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'order_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/packing-slip',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_packing_list_without_label' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
					'args'                => array(
						'order_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
						'format'   => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'html',
							'enum'              => array( 'html', 'pdf' ),
							'validate_callback' => function ( $param ) {
								return in_array( $param, array( 'html', 'pdf' ), true );
							},
						),
					),
				),
			)
		);
	}

	public function print_label( $request ) {
		list( $label_id_csv, $paper_size ) = $this->get_and_check_request_params( $request, array( 'label_id_csv', 'paper_size' ) );
		$fulfillment_id_csv                = $request->get_param( 'fulfillment_id_csv' );

		if ( ! $label_id_csv ) {
			return $this->invalid_pdf_request_error();
		}

		if ( null !== $fulfillment_id_csv ) {
			if (
				! is_scalar( $fulfillment_id_csv ) ||
				'' === (string) $fulfillment_id_csv
			) {
				return $this->invalid_pdf_request_error();
			}

			$labels = $this->parse_fulfillment_label_ids( $label_id_csv, $fulfillment_id_csv );
		} else {
			$labels = $this->parse_label_ids( $label_id_csv );
		}

		if ( is_wp_error( $labels ) ) {
			return $labels;
		}

		if ( empty( $labels ) ) {
			return $this->invalid_pdf_request_error();
		}

		$request_params = array(
			'paper_size' => $paper_size,
			'labels'     => $labels,
		);

		$raw_response = $this->api_client->get_labels_print_pdf( $request_params );
		if ( is_wp_error( $raw_response ) ) {
			$this->logger->log( $raw_response, __CLASS__ );
			return $raw_response;
		}

		return array(
			'mimeType'   => $raw_response['headers']['content-type'],
			'b64Content' => base64_encode( $raw_response['body'] ),
			'success'    => true,
		);
	}

	/**
	 * Build the `labels` payload from the raw `label_id_csv` parameter.
	 *
	 * Current behavior (flag off) forwards a single label to preserve existing mobile and web
	 * single-label callers. When `bulk_labels` is enabled, the CSV is parsed into multiple
	 * labels so a merged PDF can be produced server-side.
	 *
	 * The route does not declare a schema for `label_id_csv`, so `get_param` may return a
	 * non-string (for example an array from `label_id_csv[]=1&label_id_csv[]=2`). Non-scalar
	 * inputs are treated as invalid and return an empty payload, which the caller turns into
	 * a standard `invalid_pdf_request` error rather than a 500.
	 *
	 * @param mixed $label_id_csv Raw value from the `label_id_csv` query parameter.
	 * @return array[] Array of `['label_id' => int]` entries for the Connect Server `labels` payload.
	 */
	private function parse_label_ids( $label_id_csv ): array {
		if ( ! is_scalar( $label_id_csv ) ) {
			return array();
		}

		$label_id_csv = (string) $label_id_csv;

		if ( ! FeatureFlags::is_bulk_labels_enabled() ) {
			return array( array( 'label_id' => (int) $label_id_csv ) );
		}

		$ids = array_map( 'intval', array_map( 'trim', explode( ',', $label_id_csv ) ) );
		$ids = array_values( array_filter( $ids, static fn ( int $id ) => $id > 0 ) );

		return array_map( static fn ( int $id ) => array( 'label_id' => $id ), $ids );
	}

	/**
	 * Resolve a bulk print request through the fulfillment entity before forwarding label IDs.
	 *
	 * The Connect Server PDF endpoint still accepts label IDs, but bulk purchases are stored on
	 * fulfillment records. The paired `fulfillment_id_csv` makes the plugin prove every requested
	 * label belongs to the expected fulfillment entity before the remote print request is made.
	 *
	 * @param mixed $label_id_csv       Raw CSV value from the `label_id_csv` query parameter.
	 * @param mixed $fulfillment_id_csv Raw CSV value from the `fulfillment_id_csv` query parameter.
	 * @return array[]|WP_Error Array of `['label_id' => int]` entries or an error.
	 */
	private function parse_fulfillment_label_ids( $label_id_csv, $fulfillment_id_csv ) {
		if ( ! FeatureFlags::is_bulk_labels_enabled() ) {
			return $this->invalid_pdf_request_error();
		}

		if ( ! Utils::should_use_fulfillment_api() ) {
			return $this->fulfillment_api_required_error();
		}

		$label_ids       = $this->parse_positive_int_csv_strict( $label_id_csv );
		$fulfillment_ids = $this->parse_positive_int_csv_strict( $fulfillment_id_csv );

		if (
			! is_array( $label_ids ) ||
			! is_array( $fulfillment_ids ) ||
			empty( $label_ids ) ||
			count( $label_ids ) !== count( $fulfillment_ids )
		) {
			return $this->invalid_pdf_request_error();
		}

		$labels = array();
		foreach ( $label_ids as $index => $label_id ) {
			$fulfillment = $this->shipping_fulfillments_data_store->get_by_label_id( (string) $label_id );
			if ( ! $fulfillment instanceof ShippingFulfillment || (int) $fulfillment->get_id() !== (int) $fulfillment_ids[ $index ] ) {
				$fulfillment = $this->fulfillments_service->get_fulfillment_by_id( (int) $fulfillment_ids[ $index ] );
			}

			if (
				! $fulfillment instanceof ShippingFulfillment ||
				(int) $fulfillment->get_id() !== (int) $fulfillment_ids[ $index ] ||
				! $this->fulfillment_contains_label( $fulfillment, $label_id )
			) {
				return $this->invalid_pdf_request_error();
			}

			$labels[] = array( 'label_id' => $label_id );
		}

		return $labels;
	}

	/**
	 * Parse a comma-separated list of positive integers.
	 *
	 * Unlike `parse_label_ids`, this is strict because label IDs and fulfillment IDs are paired
	 * positionally. Dropping a bad token could silently pair a label with the wrong fulfillment.
	 *
	 * @param mixed $csv Raw CSV parameter.
	 * @return int[]|null Parsed IDs, or null when the CSV is malformed.
	 */
	private function parse_positive_int_csv_strict( $csv ): ?array {
		if ( ! is_scalar( $csv ) ) {
			return null;
		}

		$tokens = array_map( 'trim', explode( ',', (string) $csv ) );
		if ( empty( $tokens ) ) {
			return null;
		}

		$ids = array();
		foreach ( $tokens as $token ) {
			if ( '' === $token || ! ctype_digit( $token ) ) {
				return null;
			}

			$id = (int) $token;
			if ( $id <= 0 ) {
				return null;
			}

			$ids[] = $id;
		}

		return $ids;
	}

	/**
	 * Check that the resolved fulfillment still contains the requested label.
	 *
	 * @param ShippingFulfillment $fulfillment Fulfillment entity resolved by label ID.
	 * @param int                 $label_id    Requested label ID.
	 * @return bool True when the label is stored on the fulfillment entity.
	 */
	private function fulfillment_contains_label( ShippingFulfillment $fulfillment, int $label_id ): bool {
		foreach ( $fulfillment->get_labels() as $label ) {
			if ( isset( $label['label_id'] ) && (int) $label['label_id'] === $label_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build and log the standard invalid-PDF-request error.
	 *
	 * @return WP_Error
	 */
	private function invalid_pdf_request_error(): WP_Error {
		$message = __( 'Invalid PDF request.', 'woocommerce-shipping' );
		$error   = new WP_Error(
			'invalid_pdf_request',
			$message,
			array(
				'message' => $message,
				'status'  => 400,
			)
		);
		$this->logger->log( $error, __CLASS__ );
		return $error;
	}

	/**
	 * Build and log the fulfillment-API-required error.
	 *
	 * @return WP_Error
	 */
	private function fulfillment_api_required_error(): WP_Error {
		$message = __( 'Bulk label printing requires the fulfillment API.', 'woocommerce-shipping' );
		$error   = new WP_Error(
			'fulfillment_api_required',
			$message,
			array(
				'message' => $message,
				'status'  => 400,
			)
		);
		$this->logger->log( $error, __CLASS__ );
		return $error;
	}

	/**
	 * Generate packing list for a specific label.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error REST response or error.
	 */
	public function get_packing_list( \WP_REST_Request $request ) {
		try {
			list( $order_id, $label_id ) = $this->get_and_check_request_params( $request, array( 'order_id', 'label_id' ) );
		} catch ( \RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		return rest_ensure_response( $this->label_print_service->get_packing_list( $order_id, $label_id ) );
	}

	/**
	 * Generate packing list without requiring a shipping label.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error REST response or error.
	 */
	public function get_packing_list_without_label( \WP_REST_Request $request ) {
		try {
			list( $order_id ) = $this->get_and_check_request_params( $request, array( 'order_id' ) );
		} catch ( \RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		$format = $request->get_param( 'format' ) ?? 'html';
		$this->logger->log( "Packing slip request - Order ID: $order_id, Format: $format", __CLASS__ );

		return rest_ensure_response( $this->label_print_service->get_packing_list_without_label( $order_id, $format ) );
	}
}
