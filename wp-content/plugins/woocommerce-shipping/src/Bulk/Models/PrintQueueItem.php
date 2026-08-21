<?php
/**
 * Print queue item data model.
 *
 * @package Automattic\WCShipping\Bulk\Models
 */

namespace Automattic\WCShipping\Bulk\Models;

use Automattic\WCShipping\Bulk\PrintQueueRepository;
use Automattic\WCShipping\Utilities\BaseModel;
use WC_Order;

/**
 * Value object describing one entry in the bulk-label print queue.
 *
 * Persistence lives in {@see PrintQueueRepository}; this class only models the data shape.
 */
class PrintQueueItem extends BaseModel {

	/**
	 * WooCommerce order ID this queue entry belongs to.
	 *
	 * @var int
	 */
	public int $order_id;

	/**
	 * Identifier of the batch the order failed in.
	 *
	 * @var string
	 */
	public string $batch_id;

	/**
	 * Human-readable reason for the failure.
	 *
	 * @var string
	 */
	public string $error_reason;

	/**
	 * Structured error code from the originating WP_Error, when available.
	 *
	 * @var string|null
	 */
	public ?string $error_code;

	/**
	 * Number of retry attempts that have run for this queued order.
	 *
	 * @var int
	 */
	public int $retry_count;

	/**
	 * Unix timestamp at which the order was first queued.
	 *
	 * @var int
	 */
	public int $added_at;

	/**
	 * Unix timestamp of the most recent mutation.
	 *
	 * @var int
	 */
	public int $updated_at;

	/**
	 * Constructor.
	 *
	 * @param int         $order_id     Order ID.
	 * @param string      $batch_id     Source batch ID.
	 * @param string      $error_reason Human-readable failure reason.
	 * @param string|null $error_code   Structured error code, when available.
	 * @param int         $retry_count  Retry counter (>= 0).
	 * @param int         $added_at     Initial-add timestamp.
	 * @param int         $updated_at   Last-update timestamp.
	 */
	public function __construct(
		int $order_id,
		string $batch_id,
		string $error_reason,
		?string $error_code,
		int $retry_count,
		int $added_at,
		int $updated_at
	) {
		$this->order_id     = $order_id;
		$this->batch_id     = $batch_id;
		$this->error_reason = $error_reason;
		$this->error_code   = $error_code;
		$this->retry_count  = $retry_count;
		$this->added_at     = $added_at;
		$this->updated_at   = $updated_at;
	}

	/**
	 * Build an item from a {@see WC_Order} by reading its print-queue meta.
	 *
	 * Returns null when the order is not currently in the queue.
	 *
	 * @param WC_Order $order Order to hydrate from.
	 * @return self|null
	 */
	public static function from_order( WC_Order $order ): ?self {
		$batch_id = $order->get_meta( PrintQueueRepository::META_BATCH_ID, true );
		if ( ! is_string( $batch_id ) || '' === $batch_id ) {
			return null;
		}

		$raw = $order->get_meta( PrintQueueRepository::META_ITEM, true );
		if ( ! is_array( $raw ) || ! isset( $raw['error_reason'], $raw['added_at'], $raw['updated_at'] ) ) {
			return null;
		}

		// Defend against corrupted meta where a key is the wrong shape (e.g. an
		// array stored where a string is expected). Casting `(string) array(...)`
		// emits "Array to string conversion" warnings on PHP 8+ and yields a
		// confusing entry; bail to null instead so the order is treated as
		// "not in the queue" and callers can re-`add()` cleanly.
		if (
			! is_scalar( $raw['error_reason'] ) ||
			! is_numeric( $raw['added_at'] ) ||
			! is_numeric( $raw['updated_at'] ) ||
			( isset( $raw['retry_count'] ) && ! is_numeric( $raw['retry_count'] ) ) ||
			( isset( $raw['error_code'] ) && null !== $raw['error_code'] && ! is_scalar( $raw['error_code'] ) )
		) {
			return null;
		}

		return new self(
			$order->get_id(),
			$batch_id,
			(string) $raw['error_reason'],
			isset( $raw['error_code'] ) && '' !== $raw['error_code'] ? (string) $raw['error_code'] : null,
			isset( $raw['retry_count'] ) ? (int) $raw['retry_count'] : 0,
			(int) $raw['added_at'],
			(int) $raw['updated_at']
		);
	}
}
