<?php
/**
 * E2E API client mock for Connect Server requests.
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\Testing;

use Automattic\WCShipping\Connect\WC_Connect_API_Client;
use Automattic\WCShipping\Testing\WCConnectE2EConnectionShim;
use stdClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-api-client.php';

/**
 * Provides deterministic Connect Server responses for e2e environments.
 */
class WCConnectE2EAPIClientMock extends WC_Connect_API_Client {
	/**
	 * Default connect-server scenario for the current label purchase e2e flow.
	 *
	 * This represents a domestic shipment where both origin and destination
	 * addresses are treated as verified and Connect Server returns successful
	 * rates and label purchase responses.
	 */
	public const SCENARIO_LABEL_PURCHASE_DOMESTIC_VERIFIED_ADDRESSES_SUCCESS = 'label_purchase_domestic_verified_addresses_success';
	private const CONNECT_SERVER_SCENARIO_OPTION                             = 'wcshipping_e2e_connect_server_scenario';

	private const PAYMENT_METHOD_ID = 4242;

	private const DEFAULT_RATE = 9.95;

	private const DEFAULT_RETAIL_RATE = 10.95;

	/**
	 * Convert an array payload into an object graph.
	 *
	 * @param array $data Payload to convert.
	 *
	 * @return object
	 */
	private function as_object( array $data ) {
		$encoded = wp_json_encode( $data );

		return false === $encoded ? new stdClass() : json_decode( $encoded );
	}

	/**
	 * Normalize API client paths so request matching is consistent.
	 *
	 * @param string $path Request path.
	 *
	 * @return string
	 */
	private function get_normalized_path( $path ) {
		$path_without_query = strtok( (string) $path, '?' );

		return '/' . ltrim( (string) $path_without_query, '/' );
	}

	/**
	 * Return the active e2e connect-server scenario.
	 *
	 * @return string
	 */
	public static function get_active_connect_server_scenario() {
		$scenario = get_option(
			self::CONNECT_SERVER_SCENARIO_OPTION,
			self::SCENARIO_LABEL_PURCHASE_DOMESTIC_VERIFIED_ADDRESSES_SUCCESS
		);

		if ( ! is_string( $scenario ) || '' === $scenario ) {
			return self::SCENARIO_LABEL_PURCHASE_DOMESTIC_VERIFIED_ADDRESSES_SUCCESS;
		}

		return $scenario;
	}

	/**
	 * Update the active connect-server mock scenario for test-mode requests.
	 *
	 * @return void
	 */
	public static function set_connect_server_scenario() {
		if ( ! WCConnectE2EConnectionShim::is_enabled() ) {
			wp_send_json_error(
				array(
					'message' => 'E2E connect-server scenarios are only available in test mode.',
				),
				403
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => 'Insufficient permissions for e2e connect-server scenarios.',
				),
				403
			);
		}

		$scenario = isset( $_POST['scenario'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['scenario'] ) )
			: '';

		if ( '' === $scenario ) {
			delete_option( self::CONNECT_SERVER_SCENARIO_OPTION );
			wp_send_json_success(
				array(
					'scenario' => self::SCENARIO_LABEL_PURCHASE_DOMESTIC_VERIFIED_ADDRESSES_SUCCESS,
				)
			);
		}

		update_option( self::CONNECT_SERVER_SCENARIO_OPTION, $scenario, false );

		wp_send_json_success(
			array(
				'scenario' => $scenario,
			)
		);
	}

	/**
	 * Return the active e2e connect-server scenario.
	 *
	 * @return string
	 */
	private function get_active_connect_server_scenario_for_request() {
		return self::get_active_connect_server_scenario();
	}

	/**
	 * Extract package ids from a request payload.
	 *
	 * @param array $body Request payload.
	 *
	 * @return array
	 */
	private function get_package_ids( $body ) {
		if ( empty( $body['packages'] ) || ! is_array( $body['packages'] ) ) {
			return array( '0' );
		}

		$ids = array();
		foreach ( $body['packages'] as $package ) {
			$ids[] = isset( $package['id'] ) ? (string) $package['id'] : '0';
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Build a single mock label rate payload.
	 *
	 * @param string $package_id Package id.
	 * @param int    $index      Package index.
	 *
	 * @return array
	 */
	private function get_label_rate_payload( $package_id, $index = 0 ) {
		return array(
			'rate_id'     => 'e2e-rate-' . $index,
			'carrier_id'  => 'usps',
			'service_id'  => 'priority',
			'title'       => 'USPS Priority Mail',
			'rate'        => self::DEFAULT_RATE,
			'retail_rate' => self::DEFAULT_RETAIL_RATE,
			'tracking'    => true,
			'insurance'   => 100,
			'free_pickup' => true,
			'currency'    => get_woocommerce_currency(),
			'package_id'  => (string) $package_id,
		);
	}

	/**
	 * Return service schema payload compatible with current validators/consumers.
	 *
	 * @return object
	 */
	private function get_mock_service_schemas() {
		return $this->as_object(
			array(
				'shipping' => array(
					array(
						'id'                 => 'usps',
						'method_id'          => 'usps',
						'method_title'       => 'USPS',
						'method_description' => 'USPS Shipping',
						'packages'           => array(
							'pri_flat_boxes' => array(
								'title'       => 'USPS Priority Mail Flat Rate Boxes',
								'definitions' => array(
									array(
										'id'               => 'small_flat_box',
										'name'             => 'Small Flat Rate Box',
										'inner_dimensions' => '8.63 x 5.38 x 1.63',
										'outer_dimensions' => '8.63 x 5.38 x 1.63',
										'dimensions'       => '8.63 x 5.38 x 1.63',
										'box_weight'       => 0,
										'max_weight'       => 70,
										'is_letter'        => false,
										'is_flat_rate'     => true,
										'group_id'         => 'pri_flat_boxes',
										'can_ship_international' => true,
									),
									array(
										'id'               => 'medium_flat_box_top',
										'name'             => 'Medium Flat Rate Box 1, Top Loading',
										'inner_dimensions' => '11 x 8.5 x 5.5',
										'outer_dimensions' => '11.25 x 8.75 x 6',
										'dimensions'       => '11.25 x 8.75 x 6',
										'box_weight'       => 0,
										'max_weight'       => 70,
										'is_letter'        => false,
										'is_flat_rate'     => true,
										'group_id'         => 'pri_flat_boxes',
										'can_ship_international' => true,
									),
								),
							),
						),
						'service_settings'   => array(
							'type'       => 'object',
							'required'   => array(),
							'properties' => array(
								'title' => array(
									'type'  => 'string',
									'title' => 'Title',
								),
							),
						),
						'form_layout'        => array(),
					),
				),
				'boxes'    => array(
					'custom' => array(
						'type'        => 'array',
						'title'       => 'Box Sizes',
						'description' => 'Items will be packed into these boxes.',
						'default'     => array(),
						'items'       => array(
							'type'       => 'object',
							'title'      => 'Box',
							'required'   => array(
								'name',
								'inner_dimensions',
								'box_weight',
								'max_weight',
							),
							'properties' => array(
								'name'             => array(
									'type'  => 'string',
									'title' => 'Name',
								),
								'inner_dimensions' => array(
									'type'  => 'string',
									'title' => 'Inner Dimensions (L x W x H)',
								),
								'outer_dimensions' => array(
									'type'  => 'string',
									'title' => 'Outer Dimensions (L x W x H)',
								),
								'box_weight'       => array(
									'type'  => 'number',
									'title' => 'Weight of Box',
								),
								'max_weight'       => array(
									'type'  => 'number',
									'title' => 'Max Weight',
								),
								'is_letter'        => array(
									'type'  => 'boolean',
									'title' => 'Letter',
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Return payment methods payload used by account settings consumers.
	 *
	 * @return object
	 */
	private function get_mock_payment_methods() {
		return $this->as_object(
			array(
				'payment_methods'        => array(
					array(
						'payment_method_id' => self::PAYMENT_METHOD_ID,
						'name'              => 'E2E Visa',
						'card_type'         => 'visa',
						'card_digits'       => '4242',
						'expiry'            => '2029-10-31',
					),
				),
				'add_payment_method_url' => 'https://wordpress.com/me/purchases/add-credit-card',
			)
		);
	}

	/**
	 * Return label rates for each requested package id.
	 *
	 * @param array $body Request payload.
	 *
	 * @return object
	 */
	private function get_mock_label_rates( $body ) {
		$rates = array();

		foreach ( $this->get_package_ids( $body ) as $index => $package_id ) {
			$rates[ $package_id ] = array(
				'rates' => array(
					$this->get_label_rate_payload( $package_id, $index ),
				),
			);
		}

		return $this->as_object(
			array(
				'rates' => $rates,
			)
		);
	}

	/**
	 * Return a successful label purchase payload.
	 *
	 * @param array $body Request payload.
	 *
	 * @return object
	 */
	private function get_mock_label_purchase( $body ) {
		$labels = array();
		$rates  = array();

		foreach ( $this->get_package_ids( $body ) as $index => $package_id ) {
			$labels[] = array(
				'label' => array(
					'label_id'          => 999999 + $index,
					'tracking_id'       => '940000000000000000000' . $index,
					'refundable_amount' => 0,
					'created'           => gmdate( 'Y-m-d H:i:s' ),
					'carrier_id'        => 'usps',
					'status'            => 'PURCHASED',
					'is_return'         => ! empty( $body['is_return'] ),
				),
			);
			$rates[]  = $this->get_label_rate_payload( $package_id, $index );
		}

		return $this->as_object(
			array(
				'labels' => $labels,
				'rates'  => $rates,
			)
		);
	}

	/**
	 * Return a successful grouped batch label purchase payload.
	 *
	 * @param array $body Request payload.
	 *
	 * @return object
	 */
	private function get_mock_label_batch_purchase( $body ) {
		$results = array();

		if ( empty( $body['shipments'] ) || ! is_array( $body['shipments'] ) ) {
			return $this->as_object( $results );
		}

		foreach ( $body['shipments'] as $shipment ) {
			if ( ! is_array( $shipment ) || empty( $shipment['order_id'] ) ) {
				continue;
			}

			$results[ 'order_' . (int) $shipment['order_id'] ] = $this->get_mock_label_purchase( $shipment );
		}

		return $this->as_object( $results );
	}

	/**
	 * Send a grouped batch label-purchase request to the e2e Connect Server mock.
	 *
	 * @param array $body Multi-shipment payload.
	 *
	 * @return object
	 */
	public function send_grouped_label_batch_request( array $body ) {
		return $this->request( 'POST', '/shipping/labels/batch', $body );
	}

	/**
	 * Return a trivial address-normalization response.
	 *
	 * @param array $body Request payload.
	 *
	 * @return object
	 */
	private function get_mock_address_normalization( $body ) {
		$destination = isset( $body['destination'] ) && is_array( $body['destination'] )
			? $body['destination']
			: array();

		$destination = array_merge(
			array(
				'address'   => '',
				'address_2' => '',
				'city'      => '',
				'state'     => '',
				'postcode'  => '',
				'country'   => 'US',
				'name'      => '',
				'phone'     => '',
			),
			$destination
		);

		return $this->as_object(
			array(
				'normalized'               => $destination,
				'is_trivial_normalization' => true,
				'warnings'                 => array(),
			)
		);
	}

	/**
	 * Return a label-status payload for an existing mock label.
	 *
	 * @param string $path Normalized request path.
	 *
	 * @return object
	 */
	private function get_mock_label_status( $path ) {
		$label_id = preg_replace( '#^/shipping/label/(\d+).*$#', '$1', $path );

		return $this->as_object(
			array(
				'label' => array(
					'id'                => (int) $label_id,
					'tracking_id'       => '9400000000000000000000',
					'carrier_id'        => 'usps',
					'status'            => 'PURCHASED',
					'refundable_amount' => 0,
				),
			)
		);
	}

	/**
	 * Return Connect Server responses for known endpoints.
	 *
	 * @param string $method Request method.
	 * @param string $path   Request path.
	 * @param array  $body   Request payload.
	 *
	 * @return object
	 */
	protected function request( $method, $path, $body = array() ) {
		unset( $method );

		$normalized_path = $this->get_normalized_path( $path );
		$scenario        = $this->get_active_connect_server_scenario_for_request();

		if ( self::SCENARIO_LABEL_PURCHASE_DOMESTIC_VERIFIED_ADDRESSES_SUCCESS !== $scenario ) {
			return new stdClass();
		}

		if ( '/services' === $normalized_path ) {
			return $this->get_mock_service_schemas();
		}

		if ( 0 === strpos( $normalized_path, '/services/' ) && false !== strpos( $normalized_path, '/settings' ) ) {
			return $this->as_object( array( 'success' => true ) );
		}

		if ( '/payment/methods' === $normalized_path ) {
			return $this->get_mock_payment_methods();
		}

		if ( '/shipping/address/normalize' === $normalized_path ) {
			return $this->get_mock_address_normalization( is_array( $body ) ? $body : array() );
		}

		if ( '/shipping/rates' === $normalized_path || '/shipping/label/rates' === $normalized_path ) {
			return $this->get_mock_label_rates( is_array( $body ) ? $body : array() );
		}

		if ( '/shipping/label' === $normalized_path ) {
			return $this->get_mock_label_purchase( is_array( $body ) ? $body : array() );
		}

		if ( '/shipping/labels/batch' === $normalized_path ) {
			return $this->get_mock_label_batch_purchase( is_array( $body ) ? $body : array() );
		}

		if ( 1 === preg_match( '#^/shipping/label/\d+$#', $normalized_path ) ) {
			return $this->get_mock_label_status( $normalized_path );
		}

		if ( 1 === preg_match( '#^/shipping/label/\d+/refund$#', $normalized_path ) ) {
			return $this->as_object(
				array(
					'label' => array(
						'id' => (int) preg_replace( '#^/shipping/label/(\d+)/refund$#', '$1', $normalized_path ),
					),
				)
			);
		}

		if ( '/shipping/origin-addresses' === $normalized_path ) {
			return $this->as_object( array( 'success' => true ) );
		}

		return new stdClass();
	}
}
