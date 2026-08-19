<?php
namespace Automattic\WCShipping\Shipments\Models;

use Automattic\WCShipping\Utilities\BaseModel;

/**
 * Represents a single item within a shipment
 * Only stores minimal data: id and subItems (as string array)
 */
class ShipmentItem extends BaseModel {
	/**
	 * Item identifier
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * Array of sub-item IDs in the shape: [12-sub-0, 12-sub-1, ...]
	 *
	 * @var string[]
	 */
	public array $subItems = array();

	public function __construct( array $data ) {
		// Required field - id
		if ( ! isset( $data['id'] ) ) {
			throw new \InvalidArgumentException( 'ShipmentItem requires an id' );
		}

		$this->id = (int) $data['id'];

		// Optional field - subItems (MUST be normalized to strings)
		if ( isset( $data['subItems'] ) && is_array( $data['subItems'] ) ) {
			$this->subItems = $this->normalize_sub_items( $data['subItems'] );
		}
	}

	/**
	 * Normalize subItems to string array - fixes the bug!
	 * Converts any objects/arrays to just their IDs
	 *
	 * @param array $sub_items Array that may contain strings, objects, or arrays
	 * @return string[] Array of string IDs only
	 */
	private function normalize_sub_items( array $sub_items ): array {
		$normalized = array();

		foreach ( $sub_items as $item ) {
			if ( is_string( $item ) ) {
				// Already correct format
				$normalized[] = $item;
			} elseif ( is_array( $item ) && isset( $item['id'] ) ) {
				// Object as array - extract ID only (FIX THE BUG)
				$normalized[] = (string) $item['id'];
			} elseif ( is_object( $item ) && isset( $item->id ) ) {
				// Object - extract ID only (FIX THE BUG)
				$normalized[] = (string) $item->id;
			}
			// Skip invalid items silently
		}

		return $normalized;
	}


	/**
	 * Validate that subItems are strings only (for debugging)
	 *
	 * @return bool True if all subItems are strings
	 */
	public function has_valid_sub_items(): bool {
		foreach ( $this->subItems as $item ) {
			if ( ! is_string( $item ) ) {
				return false;
			}
		}
		return true;
	}
}
