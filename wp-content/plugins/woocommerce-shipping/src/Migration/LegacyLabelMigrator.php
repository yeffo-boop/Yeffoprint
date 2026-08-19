<?php

namespace Automattic\WCShipping\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WCSHIPPING_PLUGIN_DIR . '/classes/class-wc-connect-service-settings-store.php';

use Automattic\WCShipping\Connect\WC_Connect_Service_Settings_Store;
use Automattic\WCShipping\Shipments\ShipmentsService;
use Automattic\WooCommerce\Internal\BatchProcessing\BatchProcessingController;
use Automattic\WooCommerce\Internal\BatchProcessing\BatchProcessorInterface;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Utilities\StringUtil;
use Exception;
use WC_Order;
use WC_Order_Factory;

/**
 * Class LegacyLabelMigrator
 *
 * This service will migrate label data from WCS&T to the WC Shipping.
 *
 * @package Automattic\WCShipping\Migration
 */
class LegacyLabelMigrator implements BatchProcessorInterface {

	const LEGACY_LABEL_META_KEY     = 'wc_connect_labels';
	const WCSHIPPING_LABEL_META_KEY = 'wcshipping_labels';

	const DESTINATION_NORMALIZED = array(
		'legacy'     => '_wc_connect_destination_normalized',
		'wcshipping' => WC_Connect_Service_Settings_Store::IS_DESTINATION_NORMALIZED_KEY,
	);

	/**
	 * @var WC_Connect_Service_Settings_Store $settings_store
	 */
	private $settings_store;

	/**
	 * @var int $total_pending_count
	 */
	private $total_pending_count;

	/**
	 * @var int $total_count
	 */
	private $total_count;

	/**
	 * @var int $total_processed_count
	 */
	private $total_processed_count = 0;

	public function __construct( WC_Connect_Service_Settings_Store $settings_store ) {
		$this->settings_store = $settings_store;
	}

	public function get_name(): string {
		return __( 'WooCommerce Shipping label migrator', 'woocommerce-shipping' );
	}

	public function get_description(): string {
		return __( 'Migrates labels from legacy extension to WooCommerce Shipping', 'woocommerce-shipping' );
	}

	public function get_total_count(): int {
		if ( $this->total_count ) {
			return $this->total_count;
		}

		global $wpdb;
		$table_name = OrderUtil::get_table_for_order_meta();

		$this->total_count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE meta_key=%s',
				$table_name,
				self::LEGACY_LABEL_META_KEY
			)
		);

		return $this->total_count;
	}

	/**
	 * Gets the total number of orders that still need their labels migrated.
	 *
	 * This method counts orders in two categories:
	 * 1. Orders that only have legacy labels and no WC Shipping labels
	 * 2. Orders that have both legacy and WC Shipping labels, but where some legacy labels
	 *    haven't been migrated yet
	 *
	 * For orders with both types of labels, it checks each legacy label to see if it exists
	 * in the WC Shipping labels. If any legacy label is missing, that order is counted.
	 *
	 * @return int The total number of orders that still need label migration
	 */
	public function get_total_pending_count(): int {
		if ( $this->total_pending_count ) {
			return $this->total_pending_count;
		}

		global $wpdb;
		$table_name  = OrderUtil::get_table_for_order_meta();
		$column_name = OrderUtil::custom_orders_table_usage_is_enabled() ? 'order_id' : 'post_id';

		// Prepare SQL for orders without new labels
		// Get count of orders with only legacy labels
		$orders_without_new_labels = $this->total_pending_count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE meta_key=%s AND %i NOT IN ( SELECT %i FROM %i WHERE meta_key=%s )',
				$table_name,
				self::LEGACY_LABEL_META_KEY,
				$column_name,
				$column_name,
				$table_name,
				self::WCSHIPPING_LABEL_META_KEY
			)
		);

		// Get orders that have both legacy and WC Shipping labels
		$orders_with_both_meta = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT %i, meta_value as legacy_value
				FROM %i l
				WHERE meta_key = %s
				AND %i IN (
					SELECT %i
					FROM %i
					WHERE meta_key = %s
				)',
				$column_name,
				$table_name,
				self::LEGACY_LABEL_META_KEY,
				$column_name,
				$column_name,
				$table_name,
				self::WCSHIPPING_LABEL_META_KEY
			)
		);

		$count_of_orders_needing_migration = 0;
		// Get all WC Shipping labels for orders with both types in one query
		$order_ids = array_map(
			function ( $order_meta ) use ( $column_name ) {
				return $order_meta->{$column_name};
			},
			$orders_with_both_meta
		);

		if ( empty( $order_ids ) ) {
			return (int) $orders_without_new_labels;
		}

		$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

		$wc_labels_by_order = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, there is spread operator
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, as $placeholders is a placeholder
				'SELECT %i, meta_value FROM %i WHERE meta_key = %s AND %i IN (' . $placeholders . ')',
				$column_name,
				$table_name,
				self::WCSHIPPING_LABEL_META_KEY,
				$column_name,
				...array_values( $order_ids )
			),
			OBJECT_K
		);

		foreach ( $orders_with_both_meta as $order_meta ) {
			$legacy_labels = maybe_unserialize( $order_meta->legacy_value );

			if ( ! is_array( $legacy_labels ) ) {
				continue;
			}

			$order_id = $order_meta->{$column_name};
			if ( ! isset( $wc_labels_by_order[ $order_id ] ) ) {
				continue;
			}

			$wc_labels = maybe_unserialize( $wc_labels_by_order[ $order_id ]->meta_value );
			if ( ! is_array( $wc_labels ) ) {
				continue;
			}

			// Check if any legacy label_id is missing from WC labels
			foreach ( $legacy_labels as $legacy_label ) {
				if ( ! isset( $legacy_label['label_id'] ) ) {
					continue;
				}

				$found = false;
				foreach ( $wc_labels as $wc_label ) {
					if ( isset( $wc_label['label_id'] ) && $wc_label['label_id'] === $legacy_label['label_id'] ) {
						$found = true;
						break;
					}
				}

				if ( ! $found ) {
					++$count_of_orders_needing_migration;
					break; // Count the order once and move to next order
				}
			}
		}

		$this->total_pending_count = (int) $orders_without_new_labels + $count_of_orders_needing_migration;

		return $this->total_pending_count;
	}

	/**
	 * Gets the next batch of orders that need their labels migrated.
	 *
	 * This method retrieves orders in two steps:
	 * 1. Gets orders that only have legacy labels (up to batch size)
	 * 2. If more orders are needed to fill the batch, gets orders with both legacy and WC Shipping labels
	 *    where some legacy labels haven't been migrated yet
	 *
	 * When the final batch is processed, it triggers the 'wcshipping_labels_migration_completed' action.
	 *
	 * @param int $size The maximum number of orders to return in this batch
	 *
	 * @return array Array of order IDs that need label migration
	 */
	public function get_next_batch_to_process( int $size ): array {
		global $wpdb;
		$table_name  = OrderUtil::get_table_for_order_meta();
		$column_name = OrderUtil::custom_orders_table_usage_is_enabled() ? 'order_id' : 'post_id';

		// Get orders with only legacy labels
		$orders_with_legacy_only = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT l.`' . esc_sql( $column_name ) . '`
				FROM `' . esc_sql( $table_name ) . '` l
				LEFT JOIN `' . esc_sql( $table_name ) . '` w ON l.`' . esc_sql( $column_name ) . '` = w.`' . esc_sql( $column_name ) . '` AND w.meta_key = %s
				WHERE l.meta_key = %s AND w.`' . esc_sql( $column_name ) . '` IS NULL
				LIMIT %d',
				self::WCSHIPPING_LABEL_META_KEY,
				self::LEGACY_LABEL_META_KEY,
				$size
			)
		);

		if ( count( $orders_with_legacy_only ) >= $size ) {
			return $orders_with_legacy_only;
		}

		// If we need more orders, get orders with both types of labels that need migration
		$remaining_size   = $size - count( $orders_with_legacy_only );
		$orders_with_both = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.`' . esc_sql( $column_name ) . '`, l.meta_value as legacy_value, w.meta_value as wc_value
				FROM `' . esc_sql( $table_name ) . '` l
				INNER JOIN `' . esc_sql( $table_name ) . '` w ON l.`' . esc_sql( $column_name ) . '` = w.`' . esc_sql( $column_name ) . '`
				WHERE l.meta_key = %s AND w.meta_key = %s
				LIMIT %d',
				self::LEGACY_LABEL_META_KEY,
				self::WCSHIPPING_LABEL_META_KEY,
				$remaining_size
			)
		);

		$additional_orders = array();
		foreach ( $orders_with_both as $order ) {
			$legacy_labels = maybe_unserialize( $order->legacy_value );
			$wc_labels     = maybe_unserialize( $order->wc_value );

			if ( ! is_array( $legacy_labels ) || ! is_array( $wc_labels ) ) {
				continue;
			}

			// Check if any legacy label_id is missing from WC labels
			foreach ( $legacy_labels as $legacy_label ) {
				if ( ! isset( $legacy_label['label_id'] ) ) {
					continue;
				}

				$found = false;
				foreach ( $wc_labels as $wc_label ) {
					if ( isset( $wc_label['label_id'] ) && $wc_label['label_id'] === $legacy_label['label_id'] ) {
						$found = true;
						break;
					}
				}

				if ( ! $found ) {
					$additional_orders[] = $order->{$column_name};
					break; // Add the order once and move to next order
				}
			}
		}

		$next_batch = array_merge( $orders_with_legacy_only, $additional_orders );

		if ( empty( $next_batch ) ) {
			do_action( 'wcshipping_labels_migration_completed', array( 'orders_migrated' => $this->total_processed_count ) );
		}

		return $next_batch;
	}

	public function process_batch( array $order_ids ): void {
		/** @var WC_Order[] $orders */
		$orders = WC_Order_Factory::get_orders( $order_ids, true );
		foreach ( $orders as $order ) {
			try {
				$this->convert_item_and_copy_label_data( $order );
				++$this->total_processed_count;
				--$this->total_pending_count;
			} catch ( Exception $ex ) {
				wc_get_logger()->error(
					sprintf(
						'%s: when migrating meta row with id %d: %s',
						StringUtil::class_name_without_namespace( self::class ),
						$order->get_id(),
						$ex->getMessage()
					)
				);
			}
		}
	}

	public function get_default_batch_size(): int {
		return 100;
	}

	public function start(): void {
		do_action( 'wcshipping_labels_migration_started', array( 'orders_to_migrate' => $this->get_total_pending_count() ) );
		$controller = wc_get_container()->get( BatchProcessingController::class );

		if ( ! $controller->is_enqueued( self::class ) ) {
			$controller->enqueue_processor( self::class );
		}
	}

	public function stop(): void {
		$controller = wc_get_container()->get( BatchProcessingController::class );
		if ( $controller->is_enqueued( self::class ) ) {
			$controller->remove_processor( self::class );
		}
	}

	/**
	 * Check if there are any orders that need to be migrated.
	 *
	 * @return bool
	 */
	public function needs_migration(): bool {
		return $this->get_total_pending_count() > 0;
	}

	/**
	 * Check if the migration is currently queued.
	 *
	 * @return bool
	 */
	public function migration_queued(): bool {
		$controller = wc_get_container()->get( BatchProcessingController::class );
		return $controller->is_enqueued( self::class );
	}

	/**
	 * Add internal shipment id which is the index of the WCS shipment
	 * Internal ids as representation of shipment id see LabelPurchaseService::get_labels_meta_from_response
	 */
	private function add_internal_shipment_id( array $label, int $label_index ): array {
			return array_merge(
				$label,
				array(
					'id' => $label_index,
				)
			);
	}

	/**
	 * @param int[]           $product_ids
	 * @param array<int, int> $product_id_to_item_map A map of product id to item id
	 *
	 * @return array
	 */
	private function get_order_shipments( array $product_ids, array $product_id_to_item_map ): array {
		$shipments = array();
		foreach ( $product_ids as $key => $product_id ) {
			$found_index = null;
			foreach ( $shipments as $shipment_index => $shipment ) {
				if ( $shipment['id'] === $product_id_to_item_map[ $product_id ] ) {
					$found_index = $shipment_index;
					break;
				}
			}

			if ( $found_index !== null ) {
				$shipments[ $found_index ]['id'] = $product_id_to_item_map[ $product_id ];
				if ( empty( $shipments[ $found_index ]['subItems'] ) ) {
					$shipments[ $found_index ]['subItems'] = array(
						sprintf(
							'%s-sub-%s',
							$product_id_to_item_map[ $product_id ],
							0
						),
					);
				}

				$shipments[ $found_index ]['subItems'] = array_merge(
					$shipments[ $found_index ]['subItems'],
					array(
						sprintf(
							'%s-sub-%s',
							$product_id_to_item_map[ $product_id ],
							count( $shipments[ $found_index ]['subItems'] )
						),
					)
				);
			} else {
				$shipments[ $key ] = array(
					'id'       => $product_id_to_item_map[ $product_id ],
					'subItems' => array(),
				);
			}
		}

		return array_values( $shipments ); // reset array keys;
	}

	/**
	 * Calculate the next available internal ID for a new shipping label.
	 *
	 * This function examines existing WC Shipping labels and finds the highest internal ID
	 * among purchased labels, then returns that value plus 1. This ensures new labels get
	 * unique, sequential internal IDs that don't conflict with existing ones.
	 *
	 * @param array $existing_wcshipping_labels Array of existing WC Shipping label data
	 * @return int The next available internal ID to use
	 */
	private function get_internal_id_offset( array $existing_wcshipping_labels ): int {
		return array_reduce(
			$existing_wcshipping_labels,
			function ( int $max_id, array $label ): int {
				if ( isset( $label['status'], $label['id'] ) && 'PURCHASED' === $label['status'] ) {
					return max( $max_id, (int) $label['id'] + 1 );
				}
				return $max_id;
			},
			0
		);
	}

	/**
	 * Creates a mapping between product IDs and order item IDs.
	 * For variable products, uses the variation ID as the key if it exists.
	 *
	 * @param array $order_items Array of WC_Order_Item objects.
	 * @return array Map of product/variation IDs to order item IDs.
	 */
	private function get_product_id_to_item_map( array $order_items ): array {
		$product_id_to_item_map = array();

		foreach ( $order_items as $item ) {
			// ViewService::get_order_data expects variant_id as `product_id` if variant_id is not falsy.
			$key                            = method_exists( $item, 'get_variation_id' ) && $item->get_variation_id( 'edit' )
				? $item->get_variation_id( 'edit' )
				: $item->get_product_id( 'edit' );
			$product_id_to_item_map[ $key ] = $item->get_id();
		}

		return $product_id_to_item_map;
	}

	/**
	 * Initialize shipments data by either creating new shipments from the first purchased label's product IDs,
	 * or using existing shipments if available.
	 *
	 * @param array $existing_shipments Array of existing shipments data.
	 * @param array $existing_wcshipping_labels Array of existing WC Shipping labels.
	 * @param array $product_id_to_item_map Map of product IDs to order item IDs.
	 * @return array Array containing initialized shipments and next shipment index.
	 */
	private function initialize_shipments_data( array $existing_shipments, array $existing_wcshipping_labels, array $product_id_to_item_map ): array {
		$shipments           = array();
		$next_shipment_index = 0;

		if ( empty( $existing_shipments ) && ! empty( $existing_wcshipping_labels ) &&
			isset( $existing_wcshipping_labels[0], $existing_wcshipping_labels[0]['product_ids'], $existing_wcshipping_labels[0]['status'] ) &&
			'PURCHASED' === $existing_wcshipping_labels[0]['status']
		) {
			// If no existing shipments, create new ones from first label's product IDs
			$shipments[0]        = $this->get_order_shipments(
				$existing_wcshipping_labels[0]['product_ids'],
				$product_id_to_item_map
			);
			$next_shipment_index = 1;
		} elseif ( ! empty( $existing_shipments ) ) {
			$shipments           = $existing_shipments;
			$next_shipment_index = count( $existing_shipments );
		}

		return array(
			'shipments'           => $shipments,
			'next_shipment_index' => $next_shipment_index,
		);
	}

	/**
	 * @internal For internal use only.
	 */
	public function convert_item_and_copy_label_data( WC_Order $order ): void {
		$labels_data                = $this->settings_store->get_label_order_meta_data( $order->get_id(), true );
		$existing_wcshipping_labels = $order->get_meta( self::WCSHIPPING_LABEL_META_KEY, true );
		$existing_wcshipping_labels = is_array( $existing_wcshipping_labels ) ? $existing_wcshipping_labels : array();
		$converted_label_data       = array();
		$order_items                = $order->get_items();
		$product_id_to_item_map     = $this->get_product_id_to_item_map( $order_items );

		// Get existing shipments if any, orders with only one purchased label don't have shipments saved to DB
		$existing_shipments = $order->get_meta( ShipmentsService::META_KEY, true );
		$existing_shipments = is_array( $existing_shipments ) ? $existing_shipments : array();
		['shipments' => $shipments, 'next_shipment_index' => $next_shipment_index] = $this->initialize_shipments_data(
			$existing_shipments,
			$existing_wcshipping_labels,
			$product_id_to_item_map
		);

		// Calculate next internal shipment ID from existing purchased labels
		$internal_id_offset = $this->get_internal_id_offset( $existing_wcshipping_labels );

		foreach ( $labels_data as $index => $label ) {
			// Skip if this label already exists in WC Shipping labels
			if ( isset( $label['label_id'] ) && is_array( $existing_wcshipping_labels ) && $existing_wcshipping_labels !== array() ) {
				$found = false;
				foreach ( $existing_wcshipping_labels as $existing_label ) {
					if ( isset( $existing_label['label_id'] ) && $existing_label['label_id'] === $label['label_id'] ) {
						$found = true;
						break;
					}
				}
				if ( $found ) {
					continue;
				}
			}

			$converted_label_data[] = array_merge(
				$this->add_internal_shipment_id( $label, $index + $internal_id_offset ),
				array(
					'is_legacy' => true,
				)
			);

			if ( isset( $label['status'] ) && 'PURCHASED' === $label['status'] ) {
				$new_shipments = ! empty( $label['product_ids'] ) && is_array( $label['product_ids'] ) ?
					$this->get_order_shipments( $label['product_ids'], $product_id_to_item_map )
					: array();

				$shipments[ $next_shipment_index ] = $new_shipments;
				++$next_shipment_index;
			}
		}

		$order->update_meta_data( ShipmentsService::META_KEY, $shipments );

		// Merge existing and converted labels
		$final_labels = array_merge( $existing_wcshipping_labels, $converted_label_data );
		$order->update_meta_data( self::WCSHIPPING_LABEL_META_KEY, $final_labels );

		$legacy_destination_normalized = $order->get_meta( self::DESTINATION_NORMALIZED['legacy'], true );
		$order->add_meta_data( self::DESTINATION_NORMALIZED['wcshipping'], $legacy_destination_normalized, true );

		$order->save();
	}
}
