<?php
namespace Automattic\WCShipping\Shipments\Models;

use Automattic\WCShipping\Utilities\BaseModel;

/**
 * Represents the collection of all shipments for an order
 * This is the top-level model that contains multiple Shipment objects
 */
class Shipments extends BaseModel {
	/**
	 * Map of shipment ID to Shipment objects
	 *
	 * @var array<string, Shipment>
	 */
	public array $shipments = array();

	/**
	 * Constructor
	 *
	 * @param array $data Raw shipments data from database
	 */
	public function __construct( array $data = array() ) {
		foreach ( $data as $shipment_id => $items ) {
			// Ensure shipment_id is a string
			$shipment_id = (string) $shipment_id;

			// Create Shipment object for each shipment
			if ( is_array( $items ) ) {
				$this->shipments[ $shipment_id ] = new Shipment( $shipment_id, $items );
			}
		}
	}

	/**
	 * Convert to array - overridden to return storage-compatible format
	 *
	 * Raw data structure:
	 * [
	 *   '0' => [
	 *     ['id' => 1, 'subItems' => []],
	 *     ['id' => 2, 'subItems' => ['sub1']]
	 *   ],
	 *   '1' => [
	 *     ['id' => 3, 'subItems' => ['sub2', 'sub3']]
	 *   ]
	 * ]
	 *
	 * Default BaseModel output would be:
	 * [
	 *   'shipments' => [
	 *     '0' => ['id' => '0', 'items' => [...]],
	 *     '1' => ['id' => '1', 'items' => [...]]
	 *   ]
	 * ]
	 *
	 * This override returns storage format (same as raw data):
	 * [
	 *   '0' => [
	 *     ['id' => 1, 'subItems' => []],
	 *     ['id' => 2, 'subItems' => ['sub1']]
	 *   ],
	 *   '1' => [
	 *     ['id' => 3, 'subItems' => ['sub2', 'sub3']]
	 *   ]
	 * ]
	 *
	 * @param string|null $key Optional property key to serialize only that property
	 * @return mixed The shipments data in storage format
	 */
	public function to_array( ?string $key = null ) {
		$result = array();
		foreach ( $this->shipments as $shipment ) {
			$result[ $shipment->id ] = $shipment->to_array( 'items' );
		}
		return $result;
	}
}
