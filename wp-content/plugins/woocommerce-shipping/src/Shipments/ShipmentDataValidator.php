<?php
/**
 * ShipmentDataValidator class.
 *
 * Validates and repairs corrupted shipment data, particularly null item IDs
 * that may exist in legacy orders migrated from WooCommerce Shipping & Tax.
 *
 * @package Automattic/WCShipping
 */

namespace Automattic\WCShipping\Shipments;

defined( 'ABSPATH' ) || exit;

use Automattic\WCShipping\Logger;
use WC_Order;

/**
 * Validates and repairs shipment data before model creation.
 */
class ShipmentDataValidator {

	/**
	 * Validate and repair shipment data.
	 *
	 * Detects and repairs corrupted shipment data where item IDs are null.
	 * This can occur in legacy orders migrated from WooCommerce Shipping & Tax.
	 *
	 * @param array    $raw_shipments Raw shipments data from database.
	 * @param WC_Order $order         The WooCommerce order object.
	 * @return array Validated and repaired shipments data.
	 */
	public function validate_and_repair( array $raw_shipments, WC_Order $order ): array {
		$order_id           = $order->get_id();
		$available_item_ids = array_map( 'intval', array_keys( $order->get_items() ) );
		$repaired_shipments = array();

		foreach ( $raw_shipments as $shipment_id => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}

			$repaired_items = $this->repair_null_ids(
				$items,
				$available_item_ids,
				$order_id
			);

			if ( ! empty( $repaired_items ) ) {
				$repaired_shipments[ $shipment_id ] = $repaired_items;
			}
		}

		return $repaired_shipments;
	}

	/**
	 * Repair null IDs in shipment items.
	 *
	 * Maps null IDs to available order line item IDs. If no IDs are available,
	 * the item is skipped entirely.
	 *
	 * @param array $shipment_items   Items in a single shipment.
	 * @param array $available_item_ids Available order line item IDs.
	 * @param int   $order_id         Order ID for logging.
	 * @return array Repaired shipment items.
	 */
	private function repair_null_ids( array $shipment_items, array $available_item_ids, int $order_id ): array {
		$repaired_items = array();
		$used_ids       = array();

		// First pass: collect non-null IDs that are already in use.
		foreach ( $shipment_items as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) && null !== $item['id'] ) {
				$used_ids[] = (int) $item['id'];
			}
		}

		// Get available IDs for mapping (unused order line item IDs).
		$available_for_mapping = array_values( array_diff( $available_item_ids, $used_ids ) );

		// Second pass: repair null IDs.
		foreach ( $shipment_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( ! isset( $item['id'] ) || null === $item['id'] ) {
				// Try to map to an available ID.
				if ( ! empty( $available_for_mapping ) ) {
					$item['id'] = (int) array_shift( $available_for_mapping );

					$this->log_corruption_warning(
						$order_id,
						array(
							'action'    => 'repaired',
							'mapped_to' => $item['id'],
						)
					);
				} else {
					// No IDs available - skip this item entirely.
					$this->log_corruption_warning(
						$order_id,
						array(
							'action' => 'skipped',
							'reason' => 'no_available_ids',
						)
					);
					continue;
				}
			}

			$repaired_items[] = $item;
		}

		return $repaired_items;
	}

	/**
	 * Log a warning about corrupted shipment data.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $context  Additional context about the corruption.
	 */
	private function log_corruption_warning( int $order_id, array $context ): void {
		Logger::warning(
			sprintf(
				'WooCommerce Shipping: Corrupted shipment data detected for order %d',
				$order_id
			),
			array_merge(
				array(
					'order_id' => $order_id,
					'source'   => 'wcshipping-shipments',
				),
				$context
			)
		);
	}
}
