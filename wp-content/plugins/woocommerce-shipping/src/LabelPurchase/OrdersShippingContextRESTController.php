<?php
/**
 * OrdersShippingContextRESTController.
 *
 * @package Automattic\WCShipping\LabelPurchase
 */

namespace Automattic\WCShipping\LabelPurchase;

use Automattic\WCShipping\Connect\WC_Connect_Functions;
use Automattic\WCShipping\Connect\WC_Connect_Service_Settings_Store;
use Automattic\WCShipping\Fulfillments\ShippingFulfillment;
use Automattic\WCShipping\Fulfillments\ShippingFulfillmentsDataStore;
use Automattic\WCShipping\FeatureFlags\FeatureFlags;
use Automattic\WCShipping\Utils;
use Automattic\WCShipping\WCShippingRESTController;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Returns shipping context for a list of orders so the bulk-purchase modal
 * can render one row per order without having the merchant assemble the
 * destination, weight, and package details client-side.
 */
class OrdersShippingContextRESTController extends WCShippingRESTController {

	/**
	 * Maximum number of orders allowed in a single shipping-context request.
	 *
	 * Mirrors the cap on the batch rate-quote and batch purchase routes so
	 * the modal can't be opened for more orders than the downstream batch
	 * endpoints will accept.
	 */
	public const BATCH_SIZE_CAP = 25;

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'orders/shipping-context';

	/**
	 * Settings store. Used to recover legacy malformed JSON in package
	 * meta, the same way the single-order label-purchase view does.
	 *
	 * @var WC_Connect_Service_Settings_Store|null
	 */
	private $settings_store;

	/**
	 * Fulfillment data store. Used to prevent duplicate labels for orders
	 * that already have purchased labels.
	 *
	 * @var ShippingFulfillmentsDataStore
	 */
	private $shipping_fulfillments_data_store;

	/**
	 * Constructor.
	 *
	 * @param WC_Connect_Service_Settings_Store|null $settings_store                  Optional settings store used for the legacy invalid-JSON recovery branch in the package-meta parser.
	 * @param ShippingFulfillmentsDataStore|null     $shipping_fulfillments_data_store Optional fulfillment data store.
	 */
	public function __construct( ?WC_Connect_Service_Settings_Store $settings_store = null, ?ShippingFulfillmentsDataStore $shipping_fulfillments_data_store = null ) {
		$this->settings_store                   = $settings_store;
		$this->shipping_fulfillments_data_store = $shipping_fulfillments_data_store ?? new ShippingFulfillmentsDataStore();
	}

	/**
	 * Register routes. Only registered when bulk_labels is enabled.
	 *
	 * @return void
	 */
	public function register_routes() {
		if ( ! FeatureFlags::is_bulk_labels_enabled() || ! Utils::should_use_fulfillment_api() ) {
			return;
		}

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_shipping_context' ),
					'permission_callback' => array( WC_Connect_Functions::class, 'user_can_manage_labels' ),
					'args'                => array(
						'ids' => array(
							'required'    => true,
							'description' => __( 'Order IDs to fetch shipping context for.', 'woocommerce-shipping' ),
							'type'        => 'array',
							'items'       => array(
								'type' => 'integer',
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Build per-order shipping context for the bulk-purchase modal.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_shipping_context( WP_REST_Request $request ) {
		$ids = array_values( array_unique( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );
		$ids = array_filter(
			$ids,
			static function ( $id ) {
				return $id > 0;
			}
		);

		if ( empty( $ids ) ) {
			return new WP_REST_Response( array(), 200 );
		}

		if ( count( $ids ) > self::BATCH_SIZE_CAP ) {
			return new WP_Error(
				'wcshipping_bulk_labels_too_many_orders',
				sprintf(
					/* translators: %d: maximum number of orders that can be processed in a single bulk-labels request. */
					__( 'Up to %d orders can be processed at a time.', 'woocommerce-shipping' ),
					self::BATCH_SIZE_CAP
				),
				array( 'status' => 400 )
			);
		}

		$records = array();

		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				$records[] = array(
					'order_id' => $order_id,
					'error'    => array(
						'code'    => 'order_not_found',
						'message' => __( 'Order not found.', 'woocommerce-shipping' ),
					),
				);
				continue;
			}

			$fulfillments = $this->get_order_fulfillments( $order );

			if ( $this->order_already_has_shipping_label( $fulfillments ) || $this->order_is_fully_fulfilled( $fulfillments ) ) {
				$records[] = array(
					'order_id' => $order_id,
					'error'    => array(
						'code'    => 'order_already_fulfilled',
						'message' => __( 'This order already has a shipping label or is fulfilled.', 'woocommerce-shipping' ),
					),
				);
				continue;
			}

			$records[] = array_merge(
				$this->build_order_context( $order ),
				array( 'error' => null )
			);
		}

		return new WP_REST_Response( $records, 200 );
	}

	/**
	 * Check if an order already has a purchased shipping label.
	 *
	 * @param ShippingFulfillment[] $fulfillments Fulfillment records for the order.
	 * @return bool
	 */
	private function order_already_has_shipping_label( array $fulfillments ): bool {
		foreach ( $fulfillments as $fulfillment ) {
			if ( ! $fulfillment instanceof ShippingFulfillment ) {
				continue;
			}

			if ( $this->labels_contain_active_purchase( $fulfillment->get_labels() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Load fulfillment records for an order once per request row.
	 *
	 * @param WC_Order $order Order object.
	 * @return ShippingFulfillment[]
	 */
	private function get_order_fulfillments( WC_Order $order ): array {
		try {
			return $this->shipping_fulfillments_data_store->read_fulfillments( WC_Order::class, (string) $order->get_id() );
		} catch ( \Exception $e ) {
			return array();
		}
	}

	/**
	 * Check if an order is already fully fulfilled.
	 *
	 * @param ShippingFulfillment[] $fulfillments Fulfillment records for the order.
	 * @return bool
	 */
	private function order_is_fully_fulfilled( array $fulfillments ): bool {
		if ( empty( $fulfillments ) ) {
			return false;
		}

		foreach ( $fulfillments as $fulfillment ) {
			if ( ! $fulfillment instanceof ShippingFulfillment || ! $fulfillment->get_is_fulfilled() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if labels contain a non-refunded purchased label.
	 *
	 * @param array $labels Label records.
	 * @return bool
	 */
	private function labels_contain_active_purchase( array $labels ): bool {
		foreach ( $labels as $label ) {
			if ( ! is_array( $label ) ) {
				continue;
			}

			$is_refunded = ! empty( $label['refund'] );
			if ( $is_refunded ) {
				continue;
			}

			$status = $label['status'] ?? null;
			if ( in_array( $status, array( 'PURCHASED', 'PURCHASE_IN_PROGRESS' ), true ) || ! empty( $label['tracking'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the shipping-context payload for a single order.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function build_order_context( WC_Order $order ): array {
		return array(
			'order_id'      => $order->get_id(),
			'order_number'  => $order->get_order_number(),
			'customer_name' => $this->get_customer_name( $order ),
			'destination'   => $this->get_destination( $order ),
			'item_count'    => $this->get_item_count( $order ),
			'total_weight'  => $this->get_total_weight( $order ),
			'weight_unit'   => get_option( 'woocommerce_weight_unit', 'kg' ),
			'package'       => $this->get_selected_package( $order ),
		);
	}

	/**
	 * Total quantity of shippable items in the order.
	 *
	 * @param WC_Order $order Order object.
	 * @return int
	 */
	private function get_item_count( WC_Order $order ): int {
		$count = 0;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || ! $product->needs_shipping() ) {
				continue;
			}
			$count += (int) $item->get_quantity();
		}

		return $count;
	}

	/**
	 * Customer display name. Prefer shipping name, fall back to billing.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	private function get_customer_name( WC_Order $order ): string {
		$shipping = trim( $order->get_formatted_shipping_full_name() );
		if ( '' !== $shipping ) {
			return $shipping;
		}

		return trim( $order->get_formatted_billing_full_name() );
	}

	/**
	 * Build the destination address payload. Empty fields are dropped so
	 * the client can render the address compactly.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<string,string>
	 */
	private function get_destination( WC_Order $order ): array {
		$address = $order->get_address( 'shipping' );

		// Drop legacy first/last name keys; the customer_name field already
		// surfaces the recipient.
		unset( $address['first_name'], $address['last_name'] );

		return array_filter(
			array_map(
				static function ( $value ) {
					return is_string( $value ) ? trim( $value ) : $value;
				},
				$address
			),
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);
	}

	/**
	 * Sum of (item weight × quantity) for shippable items in the order.
	 *
	 * @param WC_Order $order Order object.
	 * @return float
	 */
	private function get_total_weight( WC_Order $order ): float {
		$total = 0.0;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || ! $product->needs_shipping() ) {
				continue;
			}

			$weight = (float) $product->get_weight();
			if ( $weight <= 0 ) {
				continue;
			}

			$total += $weight * (int) $item->get_quantity();
		}

		return round( $total, 4 );
	}

	/**
	 * The package selected for the order, or null when nothing is on file.
	 *
	 * The "selected" package lives on the order's shipping method as the
	 * `wcshipping_packages` meta. We surface the first one for the modal
	 * row; multi-package orders aren't represented in the shell yet.
	 *
	 * @param WC_Order $order Order object.
	 * @return array|null
	 */
	private function get_selected_package( WC_Order $order ): ?array {
		$shipping_methods = $order->get_shipping_methods();
		$shipping_method  = reset( $shipping_methods );

		$packages = $this->parse_packages_meta( $shipping_method );
		if ( empty( $packages ) ) {
			return null;
		}

		$first = (array) reset( $packages );
		if ( empty( $first ) ) {
			return null;
		}

		return array(
			'id'     => isset( $first['id'] ) ? (string) $first['id'] : '',
			'box_id' => isset( $first['box_id'] ) ? (string) $first['box_id'] : '',
			'name'   => isset( $first['name'] ) ? (string) $first['name'] : '',
			'length' => isset( $first['length'] ) ? (float) $first['length'] : 0.0,
			'width'  => isset( $first['width'] ) ? (float) $first['width'] : 0.0,
			'height' => isset( $first['height'] ) ? (float) $first['height'] : 0.0,
			'weight' => isset( $first['weight'] ) ? (float) $first['weight'] : 0.0,
		);
	}

	/**
	 * Parse the `wcshipping_packages` meta off a shipping method, handling
	 * the same value shapes the single-order label-purchase view does:
	 * native arrays (WC3+), serialized strings (WC2.6), legacy JSON, and
	 * the invalid-JSON recovery branch.
	 *
	 * Mirrors `View::get_packaging_from_shipping_method()` so the modal
	 * sees the chosen package for orders where the meta was stored in any
	 * of those formats.
	 *
	 * @param mixed $shipping_method WC_Order_Item_Shipping or false.
	 * @return array
	 */
	private function parse_packages_meta( $shipping_method ): array {
		if ( ! $shipping_method || ! isset( $shipping_method['wcshipping_packages'] ) ) {
			return array();
		}

		$packages_data = $shipping_method['wcshipping_packages'];
		if ( ! $packages_data ) {
			return array();
		}

		// WC3 retrieves metadata as non-scalar values.
		if ( is_array( $packages_data ) ) {
			return $packages_data;
		}

		// WC2.6 stores non-scalar values as string, but doesn't deserialize it on retrieval.
		$packages = maybe_unserialize( $packages_data );
		if ( is_array( $packages ) ) {
			return $packages;
		}

		// Legacy WCS stored the labels as JSON.
		$packages = json_decode( $packages_data, true );
		if ( $packages ) {
			return $packages;
		}

		// One last attempt: ask the settings store to recover malformed
		// JSON we've seen in the wild.
		if ( $this->settings_store ) {
			$recovered = $this->settings_store->try_recover_invalid_json_string( 'box_id', $packages_data );
			$packages  = json_decode( $recovered, true );
			if ( $packages ) {
				return $packages;
			}
		}

		return array();
	}
}
