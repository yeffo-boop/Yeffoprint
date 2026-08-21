<?php
/**
 * Shipment data model
 */

namespace Automattic\WCShipping\Shipments\Models;

use Automattic\WCShipping\Utilities\BaseModel;

/**
 * Represents a single shipment containing multiple items
 * A shipment is identified by its ID (usually "0", "1", "2" etc.)
 */
class Shipment extends BaseModel {
	/**
	 * Shipment identifier (e.g., "0", "1", "2")
	 *
	 * @var string
	 */
	public string $id;

	/**
	 * Array of items in this shipment
	 *
	 * @var ShipmentItem[]
	 */
	public array $items = array();

	/**
	 * Constructor
	 *
	 * @param string $id      Shipment identifier
	 * @param array  $items   Array of items (can be ShipmentItem objects or arrays)
	 */
	public function __construct( string $id, array $items = array() ) {
		$this->id = $id;

		foreach ( $items as $item_data ) {
			if ( $item_data instanceof ShipmentItem ) {
				$this->items[] = $item_data;
			} else {
				$this->items[] = new ShipmentItem( $item_data );
			}
		}
	}
}
