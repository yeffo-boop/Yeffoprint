<?php
/**
 * Class LabelRateRESTController
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\LabelRate;

use Automattic\WCShipping\WCShippingRESTController;
use Automattic\WCShipping\Connect\WC_Connect_Functions;
use Automattic\WCShipping\Exceptions\RESTRequestException;
use Automattic\WCShipping\FeatureFlags\FeatureFlags;
use Automattic\WCShipping\Shipment\Address;
use Automattic\WCShipping\Validators;
use WC_Validation;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class to label rate requests.
 */
class LabelRateRESTController extends WCShippingRESTController {

	/**
	 * Maximum number of orders allowed in a single batch rate-quote request.
	 */
	private const BATCH_SIZE_CAP = 25;

	/**
	 * Route
	 *
	 * @var string
	 */
	protected $rest_base = 'label/rate';

	/**
	 * Label rate service
	 *
	 * @var LabelRateService
	 */
	protected $label_rate_service;

	/**
	 * Class constructor.
	 *
	 * @param LabelRateService $label_rate_service Service that has logic for handling label rates.
	 */
	public function __construct( LabelRateService $label_rate_service ) {
		$this->label_rate_service = $label_rate_service;
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
					'callback'            => array( $this, 'quote_rates' ),
					'permission_callback' => array( WC_Connect_Functions::class, 'user_can_manage_labels' ),
					'args'                => $this->get_label_rate_properties(),
				),
			)
		);

		// Batch rate-quote route is only registered when bulk label printing is enabled.
		if ( FeatureFlags::is_bulk_labels_enabled() ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/batch',
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'quote_batch_rates' ),
						'permission_callback' => array( WC_Connect_Functions::class, 'user_can_manage_labels' ),
					),
				)
			);
		}
	}

	/**
	 * Define the schema for the label rate request.
	 *
	 * @return array
	 */
	private function get_label_rate_properties() {
		return array(
			'order_id'         => array(
				'required'    => true,
				'description' => __( 'Order ID for this shipping label.', 'woocommerce-shipping' ),
				'type'        => 'integer',
			),
			'origin'           => array(
				'required'          => true,
				'description'       => __( 'Ship from address', 'woocommerce-shipping' ),
				'type'              => 'object',
				'properties'        => $this->get_shipment_properties(),
				'validate_callback' => array( $this, 'validate_address' ),
				'sanitize_callback' => array( $this, 'sanitize_address' ),
			),
			'destination'      => array(
				'required'          => true,
				'description'       => __( 'Ship to address', 'woocommerce-shipping' ),
				'type'              => 'object',
				'properties'        => $this->get_shipment_properties(),
				'validate_callback' => array( $this, 'validate_address' ),
				'sanitize_callback' => array( $this, 'sanitize_address' ),
			),
			'packages'         => array(
				'required'    => true,
				'description' => __( 'The package object that describe how the shipment is packed.', 'woocommerce-shipping' ),
				'type'        => 'array',
				'items'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => $this->get_package_properties(),
				),
			),
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
				'description' => __( 'Define label as a return label. This will reverse the to and from addresses.', 'woocommerce-shipping' ),
			),
		);
	}

	/**
	 * Define the schema for the shipment object inside label rate request.
	 *
	 * @return array
	 */
	private function get_shipment_properties() {
		return array(
			'company'     => array(
				'description' => __( 'Company name.', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
			),
			'name'        => array(
				'description' => __( 'Name of the shipper.', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'required'    => true,
			),
			'address'     => array(
				'description' => __( 'Address line', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'required'    => true,
			),
			'address_1'   => array(
				'description' => __( 'Address line 1', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'required'    => true,
			),
			'address_2'   => array(
				'description' => __( 'Address line 2', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
			),
			'city'        => array(
				'description' => __( 'City name.', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
			),
			'state'       => array(
				'description' => __( 'ISO code or name of the state, province or district.', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
			),
			'postcode'    => array(
				'description' => __( 'Postal code.', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'required'    => true,
			),
			'country'     => array(
				'description' => __( 'ISO code of the country.', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'required'    => true,
			),
			'phone'       => array(
				'description' => __( 'Phone number.', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'required'    => true,
			),
			'residential' => array(
				'description' => __( 'Whether the address is residential. Optional; when omitted, EasyPost applies its own classification.', 'woocommerce-shipping' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
			),
		);
	}

	/**
	 * Define the schema for the package object inside label rate request.
	 *
	 * @return array
	 */
	private function get_package_properties() {
		return array(
			'id'                  => array(
				'description' => __( 'Package slug (ie. default_box)', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'required'    => true,
			),
			'box_id'              => array(
				'description' => __( 'Box ID (ie. small_flat_box)', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'required'    => true,
			),
			'length'              => array(
				'description'      => __( 'Length of the box. The unit is based on the store setting unit.', 'woocommerce-shipping' ),
				'type'             => 'number',
				'minimum'          => 0,
				'exclusiveMinimum' => true,
				'context'          => array( 'view', 'edit' ),
				'required'         => true,
			),
			'width'               => array(
				'description'      => __( 'Width of the box. The unit is based on the store setting unit.', 'woocommerce-shipping' ),
				'type'             => 'number',
				'minimum'          => 0,
				'exclusiveMinimum' => true,
				'context'          => array( 'view', 'edit' ),
				'required'         => true,
			),
			'height'              => array(
				'description'      => __( 'Height of the box. The unit is based on the store setting unit.', 'woocommerce-shipping' ),
				'type'             => 'number',
				'minimum'          => 0,
				'exclusiveMinimum' => true,
				'context'          => array( 'view', 'edit' ),
				'required'         => true,
			),
			'weight'              => array(
				'description'      => __( 'Weight of the box. The unit is based on the store setting unit.', 'woocommerce-shipping' ),
				'type'             => 'number',
				'minimum'          => 0,
				'exclusiveMinimum' => true,
				'context'          => array( 'view', 'edit' ),
				'required'         => true,
			),
			'is_letter'           => array(
				'description' => __( 'Is this an envelope or package?', 'woocommerce-shipping' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
			),
			'contents_type'       => array(
				'description' => __( 'Customs info contents type', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
			),
			'restriction_type'    => array(
				'description' => __( 'Custom infos restriction type', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
			),
			'non_delivery_option' => array(
				'description' => __( 'Custom infos, what to do if it can not be delivered, abandon? or return?', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
			),
			'itn'                 => array(
				'description' => __( 'Custom infos, internal transaction number', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
			),
			'items'               => array(
				'description' => __( 'List of products being shipped in this package', 'woocommerce-shipping' ),
				'type'        => 'array',
				'items'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => $this->get_product_properties(),
				),
			),
		);
	}

	/**
	 * Define the schema for the product item object inside package.
	 *
	 * @return array
	 */
	private function get_product_properties() {
		return array(
			'description'      => array(
				'description' => __( 'Description of this item', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'required'    => true,
			),
			'quantity'         => array(
				'description' => __( 'Quanity of this item in the shipment', 'woocommerce-shipping' ),
				'type'        => 'integer',
				'context'     => array( 'view', 'edit' ),
				'required'    => true,
			),
			'value'            => array(
				'description' => __( 'The total value of this item', 'woocommerce-shipping' ),
				'type'        => 'number',
				'context'     => array( 'view', 'edit' ),
			),
			'weight'           => array(
				'description' => __( 'The total weight of this item', 'woocommerce-shipping' ),
				'type'        => 'number',
				'context'     => array( 'view', 'edit' ),
			),
			'hs_tariff_number' => array(
				'description' => __( 'HS Tariff number for this item', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
			),
			'origin_country'   => array(
				'description' => __( 'The origin country of this item', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
			),
			'product_id'       => array(
				'description' => __( 'The product ID of this item', 'woocommerce-shipping' ),
				'type'        => 'number',
				'context'     => array( 'view', 'edit' ),
			),
		);
	}

	/**
	 * Validate the address and phone number.
	 *
	 * @param array $param Request payload.
	 *
	 * @return boolean|WP_Error
	 */
	public function validate_address( $param ) {
		$address           = new Address( $param );
		$validation_result = $address->validate();

		if ( is_wp_error( $validation_result ) ) {
			return $validation_result;
		}

		if ( ! WC_Validation::is_phone( $param['phone'] ) ) {
			return new WP_Error(
				'invalid_address',
				__( 'The provided phone number is not valid', 'woocommerce-shipping' )
			);
		}

		return true;
	}

	/**
	 * Sanitize the address.
	 *
	 * @param array $param Request payload.
	 */
	public function sanitize_address( $param ) {
		$original_param = array(
			'company' => sanitize_text_field( $param['company'] ),
			'name'    => sanitize_text_field( $param['name'] ),
			'phone'   => sanitize_text_field( $param['phone'] ),
		);

		if ( isset( $param['residential'] ) ) {
			$original_param['residential'] = rest_sanitize_boolean( $param['residential'] );
		}

		$address = new Address( $param );

		// Overwrite original param with the sanitized values.
		$sanitized_param = array_merge( $original_param, (array) $address );

		return $sanitized_param;
	}

	/**
	 * The method that handles GET request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function quote_rates( WP_REST_Request $request ) {
		try {
			$payload = $request->get_json_params();
			if ( empty( $payload ) ) {
				throw new RESTRequestException( 'Request payload is invalid.' );
			}
		} catch ( RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		// Retrieve shipping rates.
		$response = $this->label_rate_service->get_all_rates( $payload );
		return rest_ensure_response( $response );
	}

	/**
	 * Batch rate-quote handler. Accepts a single root `origin` and an `orders` array, and returns
	 * rates keyed by `order_id`.
	 *
	 * Bulk batches are confined to a single origin per request: UPSDAP terms-of-service acceptance
	 * is per-origin and the FedEx ToS is once per site, so per-order origins would only complicate
	 * ToS gating without serving a real workflow. Each order carries the per-shipment fields:
	 * `order_id`, `destination`, `packages`, and the optional `shipment_options` / `is_return`.
	 *
	 * A per-order failure (for example an invalid destination) is captured as an `error` entry
	 * for that order rather than aborting the whole batch.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function quote_batch_rates( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$origin  = is_array( $payload ) && isset( $payload['origin'] ) ? $payload['origin'] : null;
		$orders  = is_array( $payload ) && isset( $payload['orders'] ) ? $payload['orders'] : null;

		if ( ! is_array( $origin ) || empty( $origin ) ) {
			return new WP_Error(
				'invalid_batch_payload',
				__( 'The batch rate-quote request must include a single root `origin` object shared by all orders.', 'woocommerce-shipping' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_array( $orders ) || empty( $orders ) ) {
			return new WP_Error(
				'invalid_batch_payload',
				__( 'The batch rate-quote request must include a non-empty `orders` array.', 'woocommerce-shipping' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $orders ) > self::BATCH_SIZE_CAP ) {
			return new WP_Error(
				'batch_too_large',
				sprintf(
					/* translators: %d: maximum number of orders per batch rate-quote request */
					__( 'Batch rate-quote requests are limited to %d orders.', 'woocommerce-shipping' ),
					self::BATCH_SIZE_CAP
				),
				array( 'status' => 400 )
			);
		}

		// Reject the whole batch if any order tries to set its own `origin`. Bulk batches share
		// one origin per request, so a per-order origin is almost certainly client confusion and
		// silently ignoring it would lead to surprising rate quotes.
		foreach ( $orders as $order ) {
			if ( is_array( $order ) && array_key_exists( 'origin', $order ) ) {
				return new WP_Error(
					'invalid_batch_payload',
					__( 'Per-order `origin` is not allowed in batch rate-quote requests; provide a single root `origin` shared by all orders.', 'woocommerce-shipping' ),
					array( 'status' => 400 )
				);
			}
		}

		/**
		 * Reject duplicate `order_id`s up front. Results are keyed by `order_id`, so duplicates would
		 * silently overwrite earlier entries and produce a non-deterministic response.
		 */
		$duplicate_order_id = $this->get_duplicate_positive_order_id( $orders );
		if ( null !== $duplicate_order_id ) {
			return new WP_Error(
				'invalid_batch_payload',
				sprintf(
					/* translators: %d: duplicated order_id */
					__( 'Duplicate `order_id` %d in batch rate-quote request; each order must appear at most once.', 'woocommerce-shipping' ),
					$duplicate_order_id
				),
				array( 'status' => 400 )
			);
		}

		// Partition the batch by basic structural shape so malformed orders receive per-order errors
		// while well-formed orders in the same request are still rated. We only check the structural
		// keys the service unconditionally dereferences; deeper field-level validation (country codes,
		// package dimensions, etc.) still happens in the service / Connect Server.
		list( $valid_orders, $shape_errors ) = $this->partition_batch_orders_by_shape( $orders );

		$results = array();
		foreach ( $shape_errors as $key => $shape_error ) {
			$results[ $key ] = array(
				'error' => array(
					'code'    => $shape_error->get_error_code(),
					'message' => $shape_error->get_error_message(),
				),
			);
		}

		if ( ! empty( $valid_orders ) ) {
			$rates_by_order = $this->label_rate_service->get_all_rates_for_batch( $origin, $valid_orders );
			foreach ( $rates_by_order as $order_id => $order_response ) {
				if ( is_wp_error( $order_response ) ) {
					$results[ $order_id ] = array(
						'error' => array(
							'code'    => $order_response->get_error_code(),
							'message' => $order_response->get_error_message(),
						),
					);
					continue;
				}
				$results[ $order_id ] = $order_response;
			}
		}

		return rest_ensure_response( $results );
	}

	/**
	 * Split a batch payload into structurally-valid orders and per-item shape errors.
	 *
	 * Each order must be an associative array with a positive `order_id` and the structural keys
	 * the service dereferences without further checks: `destination`, `packages`. (Origin is
	 * supplied at the batch root and validated separately.) Items failing this contract are
	 * returned as a per-item WP_Error keyed by `order_id` when present, otherwise by a stable
	 * `invalid_order_<index>` placeholder so callers can correlate errors back to their input position.
	 *
	 * @param array $orders Raw `orders` array from the batch request.
	 * @return array{0: array, 1: array<string|int, WP_Error>} Tuple of (valid orders, shape errors).
	 */
	private function partition_batch_orders_by_shape( array $orders ): array {
		$valid        = array();
		$shape_errors = array();

		foreach ( $orders as $index => $order ) {
			$order_id = is_array( $order ) && isset( $order['order_id'] ) ? (int) $order['order_id'] : 0;
			$key      = $order_id > 0 ? $order_id : "invalid_order_{$index}";

			if ( ! is_array( $order ) ) {
				$shape_errors[ $key ] = new WP_Error(
					'invalid_order_shape',
					__( 'Order entry must be an object.', 'woocommerce-shipping' )
				);
				continue;
			}

			$missing = array();
			foreach ( array( 'order_id', 'destination', 'packages' ) as $required ) {
				if ( ! isset( $order[ $required ] ) ) {
					$missing[] = $required;
				}
			}

			if ( ! empty( $missing ) ) {
				$shape_errors[ $key ] = new WP_Error(
					'invalid_order_shape',
					sprintf(
						/* translators: %s: comma-separated list of missing required keys */
						__( 'Order is missing required field(s): %s.', 'woocommerce-shipping' ),
						implode( ', ', $missing )
					)
				);
				continue;
			}

			if ( $order_id <= 0 ) {
				$shape_errors[ $key ] = new WP_Error(
					'invalid_order_shape',
					__( 'Order is missing a valid `order_id` (must be a positive integer).', 'woocommerce-shipping' )
				);
				continue;
			}

			if ( ! is_array( $order['destination'] ) || empty( $order['destination'] )
				|| ! is_array( $order['packages'] ) || empty( $order['packages'] ) ) {
				$shape_errors[ $key ] = new WP_Error(
					'invalid_order_shape',
					__( 'Order destination and packages must be non-empty arrays.', 'woocommerce-shipping' )
				);
				continue;
			}

			$valid[] = $order;
		}

		return array( $valid, $shape_errors );
	}
}
