<?php
/**
 * Print queue persistence layer.
 *
 * @package Automattic\WCShipping\Bulk
 */

namespace Automattic\WCShipping\Bulk;

use Automattic\WCShipping\Bulk\Models\PrintQueueItem;
use WC_Order;

/**
 * CRUD helpers for the bulk-label print queue.
 *
 * A valid queue entry requires BOTH meta entries to be present and well-formed:
 *   - {@see self::META_BATCH_ID}: a non-empty string holding the source batch ID.
 *   - {@see self::META_ITEM}: an array carrying `error_reason`, `added_at`, `updated_at`
 *     (plus optional `error_code` / `retry_count`).
 *
 * `get()` and `list()` hide entries that are missing or malformed on either side,
 * so an orphaned `META_BATCH_ID` (or a corrupted `META_ITEM`) is treated as
 * "not in the queue". `remove()` is best-effort and clears both meta keys when
 * either is present, so stale half-state still gets cleaned up.
 *
 * Both keys are written through HPOS-compatible WC_Order APIs.
 */
class PrintQueueRepository {

	/**
	 * Meta key holding the source batch ID. Required half of a valid queue entry;
	 * its presence alone (without a valid {@see self::META_ITEM}) is treated as
	 * orphaned / corrupt state.
	 */
	public const META_BATCH_ID = '_wcshipping_print_queue_batch_id';

	/**
	 * Meta key holding the rest of the queue-item state (array).
	 */
	public const META_ITEM = '_wcshipping_print_queue_item';

	/**
	 * Add an order to the print queue, or overwrite its entry if already present.
	 *
	 * Idempotent on order ID: re-adding resets retry_count to 0 and updates updated_at,
	 * while added_at is preserved from the original add. This matches the "merchant
	 * kicked off a fresh batch and it failed again" flow.
	 *
	 * @param int         $order_id     WooCommerce order ID.
	 * @param string      $batch_id     Source batch ID. Must be non-empty; an empty value is
	 *                                  treated as "not queued" by {@see PrintQueueItem::from_order()}
	 *                                  and would leave orphaned meta.
	 * @param string      $error_reason Human-readable failure reason.
	 * @param string|null $error_code   Structured error code (e.g. WP_Error code).
	 * @return PrintQueueItem|null Null when the order does not exist or $batch_id is empty.
	 */
	public function add( int $order_id, string $batch_id, string $error_reason, ?string $error_code = null ): ?PrintQueueItem {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		if ( '' === $batch_id ) {
			return null;
		}

		$now      = time();
		$existing = PrintQueueItem::from_order( $order );
		$added_at = $existing instanceof PrintQueueItem ? $existing->added_at : $now;

		$item_data = array(
			'error_reason' => $error_reason,
			'error_code'   => $error_code,
			'retry_count'  => 0,
			'added_at'     => $added_at,
			'updated_at'   => $now,
		);

		$order->update_meta_data( self::META_BATCH_ID, $batch_id );
		$order->update_meta_data( self::META_ITEM, $item_data );
		$order->save();

		return PrintQueueItem::from_order( $order );
	}

	/**
	 * Remove an order from the print queue.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return bool True if anything was removed; false if the order was not queued or does not exist.
	 */
	public function remove( int $order_id ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$batch_id = $order->get_meta( self::META_BATCH_ID, true );
		$item     = $order->get_meta( self::META_ITEM, true );

		// WC returns '' for absent single-value meta. Anything else (scalar,
		// non-empty array, even an empty array stored deliberately) counts as
		// "present", so corrupted/legacy state still gets cleaned up.
		$has_batch_id = '' !== $batch_id && null !== $batch_id;
		$has_item     = '' !== $item && null !== $item;

		if ( ! $has_batch_id && ! $has_item ) {
			return false;
		}

		$order->delete_meta_data( self::META_BATCH_ID );
		$order->delete_meta_data( self::META_ITEM );
		$order->save();

		return true;
	}

	/**
	 * Fetch the queue entry for a single order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return PrintQueueItem|null
	 */
	public function get( int $order_id ): ?PrintQueueItem {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		return PrintQueueItem::from_order( $order );
	}

	/**
	 * List queue entries, optionally restricted to a single batch.
	 *
	 * @param string|null $batch_id Source batch ID, or null for the full queue.
	 * @return PrintQueueItem[]
	 */
	public function list( ?string $batch_id = null ): array {
		$meta_query = array();
		if ( null === $batch_id ) {
			$meta_query[] = array(
				'key'     => self::META_BATCH_ID,
				'compare' => 'EXISTS',
			);
		} else {
			$meta_query[] = array(
				'key'     => self::META_BATCH_ID,
				'value'   => $batch_id,
				'compare' => '=',
			);
		}

		$orders = wc_get_orders(
			array(
				'limit'      => -1,
				'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'return'     => 'objects',
				// WP_Query's "any" status excludes trash/auto-draft. Query the
				// registered WC statuses plus those special statuses so list()
				// stays symmetric with get()/remove() for any order that still
				// carries queue meta.
				'status'     => array_merge(
					array_keys( wc_get_order_statuses() ),
					array( 'trash', 'auto-draft' )
				),
			)
		);

		$items = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$item = PrintQueueItem::from_order( $order );
			if ( $item instanceof PrintQueueItem ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Bump the retry counter for an existing queue entry.
	 *
	 * This is a read-modify-write update for a single order's queue meta. Per-order
	 * meta avoids one shared queue value across different orders, but concurrent
	 * mutations for the same order can still overwrite each other.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return PrintQueueItem|null Null when the order is not queued or does not exist.
	 */
	public function increment_retry( int $order_id ): ?PrintQueueItem {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		$existing = PrintQueueItem::from_order( $order );
		if ( ! $existing instanceof PrintQueueItem ) {
			return null;
		}

		$item_data = array(
			'error_reason' => $existing->error_reason,
			'error_code'   => $existing->error_code,
			'retry_count'  => $existing->retry_count + 1,
			'added_at'     => $existing->added_at,
			'updated_at'   => time(),
		);
		$order->update_meta_data( self::META_ITEM, $item_data );
		$order->save();

		return PrintQueueItem::from_order( $order );
	}

	/**
	 * Replace the error reason/code on an existing queue entry without touching retry_count.
	 *
	 * @param int         $order_id     WooCommerce order ID.
	 * @param string      $error_reason New human-readable reason.
	 * @param string|null $error_code   New structured code.
	 * @return PrintQueueItem|null Null when the order is not queued or does not exist.
	 */
	public function update_error( int $order_id, string $error_reason, ?string $error_code = null ): ?PrintQueueItem {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		$existing = PrintQueueItem::from_order( $order );
		if ( ! $existing instanceof PrintQueueItem ) {
			return null;
		}

		$item_data = array(
			'error_reason' => $error_reason,
			'error_code'   => $error_code,
			'retry_count'  => $existing->retry_count,
			'added_at'     => $existing->added_at,
			'updated_at'   => time(),
		);
		$order->update_meta_data( self::META_ITEM, $item_data );
		$order->save();

		return PrintQueueItem::from_order( $order );
	}
}
