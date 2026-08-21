<?php

declare( strict_types=1 );

namespace Automattic\WCShipping\Fulfillments;

use WC_Order;

defined( 'ABSPATH' ) || exit;

class FulfillmentsService {

	/**
	 * @var ShippingFulfillmentsDataStore
	 */
	private $data_store;

	/**
	 * @param ShippingFulfillmentsDataStore $shipping_fulfillments_data_store
	 */
	public function __construct( ShippingFulfillmentsDataStore $data_store ) {
		$this->data_store = $data_store;
	}

	/**
	 * Get a fulfillment by ID.
	 *
	 * @param int $fulfillment_id Fulfillment entity ID.
	 * @return ShippingFulfillment|null Fulfillment object if found, null otherwise.
	 */
	public function get_fulfillment_by_id( int $fulfillment_id ): ?ShippingFulfillment {
		if ( $fulfillment_id <= 0 || ! method_exists( $this->data_store, 'read' ) ) {
			return null;
		}

		$fulfillment = new ShippingFulfillment();
		$fulfillment->set_id( $fulfillment_id );

		try {
			$this->data_store->read( $fulfillment );
		} catch ( \Throwable $error ) {
			return null;
		}

		return $fulfillment;
	}

	/**
	 * Get the fulfillments for an order in shipments format.
	 *
	 * This method converts fulfillments back to shipments format for compatibility
	 * with the UI, which expects the shipment structure with id, subItems, and fulfillment_id.
	 *
	 * @param int $order_id The ID of the order.
	 * @return array The fulfillments converted to shipments format.
	 */
	public function get_order_fulfillments_as_shipments( int $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			throw new \Exception( 'Order not found' );
		}
		$fulfillments = $this->data_store->read_fulfillments( WC_Order::class, "$order_id" );

		return $this->convert_fulfillments_to_shipments_format( $fulfillments );
	}

	/**
	 * Update the fulfillments for an order.
	 *
	 * Note: "Shipments" from UI are actually fulfillment data since fulfillments
	 * are now the single source of truth.
	 *
	 * @param int   $order_id The ID of the order.
	 * @param array $shipments The fulfillment data from UI (called "shipments").
	 * @return array The updated fulfillments.
	 */
	public function update_order_fulfillments( int $order_id, array $shipments ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			throw new \Exception( 'Order not found' );
		}

		$existing_fulfillments = $this->data_store->read_fulfillments( WC_Order::class, "$order_id" );

		// Create a map of existing fulfillments by their ID for efficient lookup
		$fulfillment_map = array();
		foreach ( $existing_fulfillments as $fulfillment ) {
			$fulfillment_map[ $fulfillment->get_id() ] = $fulfillment;
		}

		$updated_fulfillments             = array();
		$shipments_with_fulfillment_id    = array();
		$shipments_without_fulfillment_id = array();

		// Separate shipments with and without fulfillment_id
		foreach ( $shipments as $shipment_data ) {
			$fulfillment_id = $this->extract_fulfillment_id_from_shipment( $shipment_data );
			if ( $fulfillment_id && isset( $fulfillment_map[ $fulfillment_id ] ) ) {
				$shipments_with_fulfillment_id[ $fulfillment_id ] = $shipment_data;
			} else {
				$shipments_without_fulfillment_id[] = $shipment_data;
			}
		}

		// Update existing fulfillments that have matching fulfillment_id
		foreach ( $shipments_with_fulfillment_id as $fulfillment_id => $shipment_data ) {
			$fulfillment = $fulfillment_map[ $fulfillment_id ];

			// Skip updating fulfillments that are already fulfilled
			if ( $fulfillment->get_is_fulfilled() ) {
				$updated_fulfillments[] = $fulfillment;
				continue;
			}

			$items = $this->convert_shipment_items_to_fulfillment_items( $shipment_data );
			$fulfillment->set_items( $items );
			$fulfillment->save();
			$updated_fulfillments[] = $fulfillment;
		}

		// Create new fulfillments for shipments without fulfillment_id
		foreach ( $shipments_without_fulfillment_id as $shipment_data ) {
			$items                  = $this->convert_shipment_items_to_fulfillment_items( $shipment_data );
			$fulfillment            = $this->create_order_fulfillment( $order_id, $items );
			$updated_fulfillments[] = $fulfillment;
		}

		// Delete fulfillments that are no longer present in the shipments
		// But never delete fulfillments that are already fulfilled
		$remaining_fulfillment_ids = array_keys( $shipments_with_fulfillment_id );
		foreach ( $fulfillment_map as $fulfillment_id => $fulfillment ) {
			if ( ! in_array( $fulfillment_id, $remaining_fulfillment_ids, true ) ) {
				// Never delete fulfilled fulfillments
				if ( ! $fulfillment->get_is_fulfilled() ) {
					$fulfillment->delete();
				} else {
					// Keep fulfilled fulfillments even if not in the shipments
					$updated_fulfillments[] = $fulfillment;
				}
			}
		}

		return $this->convert_fulfillments_to_shipments_format( $updated_fulfillments );
	}


	/**
	 * Create a new fulfillment for an order.
	 *
	 * @param int   $order_id The ID of the order.
	 * @param array $items The items to fulfill.
	 * @return Fulfillment The created fulfillment.
	 */
	private function create_order_fulfillment( int $order_id, array $items ) {
		$fulfillment = new ShippingFulfillment();

		$fulfillment->set_entity_type( WC_Order::class );
		$fulfillment->set_entity_id( "$order_id" );
		$fulfillment->set_status( 'unfulfilled' );
		$fulfillment->set_items( $items );

		$fulfillment->save();

		return $fulfillment;
	}

	/**
	 * Extract fulfillment_id from shipment data.
	 * The fulfillment_id can be found in any of the items in the shipment.
	 *
	 * @param array $shipment_data The shipment data array.
	 * @return int|null The fulfillment ID or null if not found.
	 */
	private function extract_fulfillment_id_from_shipment( array $shipment_data ) {
		foreach ( $shipment_data as $item ) {
			if ( is_array( $item ) && isset( $item['fulfillment_id'] ) ) {
				return intval( $item['fulfillment_id'] );
			}
		}
		return null;
	}

	/**
	 * Convert shipment items to fulfillment items format.
	 *
	 * Transforms the nested shipment structure with id and subItems
	 * into the flat fulfillment items format with item_id and qty.
	 * Note: fulfillment_id is used for mapping but doesn't need to be stored in the items.
	 *
	 * @param array $shipment_items The shipment items array
	 * @return array Array of fulfillment items in the format: [['item_id' => int, 'qty' => int], ...]
	 */
	public function convert_shipment_items_to_fulfillment_items( array $shipment_items ): array {
		$fulfillment_items = array();

		foreach ( $shipment_items as $item ) {
			if ( isset( $item['id'] ) ) {
				$item_id   = intval( $item['id'] );
				$sub_items = isset( $item['subItems'] ) && is_array( $item['subItems'] ) ? $item['subItems'] : array();

				// If subItems is not empty, qty is the count of subItems, otherwise qty is 1
				$quantity = count( $sub_items ) > 0 ? count( $sub_items ) : 1;

				$fulfillment_items[] = array(
					'item_id' => $item_id,
					'qty'     => $quantity,
				);
			}
		}

		return $fulfillment_items;
	}

	/**
	 * Convert fulfillments to shipments format.
	 *
	 * This method reverses the conversion process to provide the shipments format
	 * that the UI expects, with each shipment containing items with id, subItems, and fulfillment_id.
	 *
	 * @param array $fulfillments Array of Fulfillment objects
	 * @return array Array in shipments format organized by shipment index
	 */
	public function convert_fulfillments_to_shipments_format( array $fulfillments ): array {
		$shipments      = array();
		$shipment_index = 0;

		foreach ( $fulfillments as $fulfillment ) {
			$fulfillment_id = $fulfillment->get_id();
			$items          = $fulfillment->get_items();
			$shipment_items = array();

			foreach ( $items as $item ) {
				$item_id = $item['item_id'];
				$qty     = $item['qty'];

				// Generate subItems based on quantity
				// If qty = 1, empty subItems array (represents single item)
				// If qty > 1, generate subItems with pattern {item_id}-sub-{index}
				$sub_items = array();
				if ( $qty > 1 ) {
					for ( $i = 0; $i < $qty; $i++ ) {
						$sub_items[] = $item_id . '-sub-' . $i;
					}
				}

				$shipment_items[] = array(
					'id'             => $item_id,
					'subItems'       => $sub_items,
					'fulfillment_id' => $fulfillment_id,
				);
			}

			// Add this fulfillment as a shipment
			if ( ! empty( $shipment_items ) ) {
				$shipments[ (string) $shipment_index ] = $shipment_items;
				++$shipment_index;
			}
		}

		return $shipments;
	}

	/**
	 * Ensure the order has at least one fulfillment. If not, create one from shippable order items.
	 *
	 * @param int $order_id Order ID.
	 * @return Fulfillment | Fulfillment[] | null If a new fulfillment is created it's returned, if fulfillments already exist it's returned and null otherwise
	 */
	public function ensure_order_has_fulfillment( int $order_id ) {
		// Check if order already has fulfillments
		$existing_fulfillments = $this->data_store->read_fulfillments( WC_Order::class, "$order_id" );

		// If order already has fulfillments, don't create a new one
		if ( ! empty( $existing_fulfillments ) ) {
			return $existing_fulfillments;
		}

		// Create a fulfillment from all order items
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$fulfillment_items = $this->build_fulfillment_items_from_shippable_order_items( $order );

		// Only create fulfillment if there are items that need shipping
		if ( ! empty( $fulfillment_items ) ) {
			return $this->create_order_fulfillment( $order_id, $fulfillment_items );
		}
		return;
	}

	/**
	 * Build fulfillment items directly from order items.
	 *
	 * @param WC_Order $order Order object.
	 * @return array Array of fulfillment items in the format: [['item_id' => int, 'qty' => int], ...]
	 */
	private function build_fulfillment_items_from_shippable_order_items( WC_Order $order ) {
		$fulfillment_items = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			if ( ! $product->needs_shipping() ) {
				continue;
			}

			$fulfillment_items[] = array(
				'item_id' => $item_id,
				'qty'     => $item->get_quantity(),
			);
		}

		return $fulfillment_items;
	}
}
