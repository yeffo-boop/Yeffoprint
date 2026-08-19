<?php

namespace WooCommerce\BoxPacker\DVDoug;

use DVDoug\BoxPacker\InfalliblePacker;
use DVDoug\BoxPacker\ItemList;
use DVDoug\BoxPacker\PackedBoxList;
use Exception;
use WooCommerce\BoxPacker\Abstract_Packer;
use WooCommerce\BoxPacker\Package;

class Packer extends Abstract_Packer {

	/**
	 * @param string $dimension_unit
	 * @param string $weight_unit
	 * @param array $options
	 */
	public function __construct( string $dimension_unit, string $weight_unit, array $options = array() ) {
		parent::__construct( $dimension_unit, $weight_unit, $options );
	}

	/**
	 * Add Item to items property.
	 *
	 * @param int|float|string $length Item Length.
	 * @param int|float|string $width Item width.
	 * @param int|float|string $height Item height.
	 * @param int|float|string $weight Item weight.
	 * @param int|float|string $value Item price.
	 * @param array            $meta Item metadata.
	 * @param int              $qty Item quantity.
	 *
	 * @return void
	 */
	public function add_item( $length, $width, $height, $weight, $value = '', $meta = array(), $qty = 1 ) {
		$this->items[] = array(
			'data' => new Item( $this->dimension_unit, $this->weight_unit, $length, $width, $height, $weight, $value, $meta ),
			'qty'  => $qty,
		);
	}

	/**
	 * @param $length
	 * @param $width
	 * @param $height
	 * @param int $weight
	 * @param float $max_weight
	 * @param string $type
	 *
	 * @return \WooCommerce\BoxPacker\DVDoug\Box
	 */
	public function add_box( $length, $width, $height, $weight = 0, $max_weight = 0.0, $type = '' ) {
		$box           = new Box( $this->dimension_unit, $this->weight_unit, $length, $width, $height, $weight, $max_weight, $type );
		$this->boxes[] = $box;

		return $box;
	}

	/**
	 * @return void
	 */
	public function pack() {

		try {
			// Set the $packages property to an empty array.
			$this->clear_packages();

			// If there are no items, show admins an error and return.
			if ( empty( $this->items ) ) {
				throw new Exception( 'No items to pack!' );
			}

			/**
			 * If there are no boxes, add a box that is not possible
			 * to pack so the packer can still loop through the items
			 * and handle them as unpacked.
			 */
			if ( empty( $this->boxes ) ) {
				$unpackable_box = $this->add_box( 0, 0, 0, 0, 0 );
				$unpackable_box->set_id( 'unpackable box' );
				$unpackable_box->set_inner_dimensions( 0, 0, 0 );
				$unpackable_box->set_volume( 0 );

				$this->boxes = array( $unpackable_box );
			}

			// Order the boxes by volume.
			$this->boxes = $this->order_boxes( $this->boxes );

			/*
			 * Instantiate DVDoug InfalliblePacker
			 *
			 * @see https://boxpacker.io/en/stable/too-large-items.html
			 */
			$packer = new InfalliblePacker();

			/**
			 * Disable using multiple boxes just to
			 * balance the weight, for now. Could be
			 * a good optional future feature.
			 *
			 * @see https://boxpacker.io/en/stable/weight-distribution.html
			 */
			$packer->setMaxBoxesToBalanceWeight( 0 );

			// Add box sizes to packer.
			foreach ( $this->boxes as $box ) {
				$packer->addBox( $box );
			}

			// Add items to packer.
			foreach ( $this->items as $item ) {
				$packer->addItem( $item['data'], $item['qty'] );
			}

			/**
			 * Attempt to pack items into boxes and handle
			 * the packed boxes.
			 */
			$packed_box_list = $packer->pack();
			$this->handle_packed_box_list( $packed_box_list );

			/**
			 * Check for any unpacked items,
			 * then handle them.
			 *
			 * @see https://boxpacker.io/en/stable/too-large-items.html
			 */
			$unpacked_item_list = $packer->getUnpackedItems();
			$this->handle_unpacked_item_list( $unpacked_item_list );
		} catch ( Exception $e ) {

			$this->maybe_display_packing_error( $e->getMessage() );
		}
	}

	/**
	 * Loop through DVDoug's PackedBoxList and
	 * convert the objects to an array of
	 * WooCommerce/BoxPacker/Package objects
	 * and add them to the packages[] property.
	 *
	 * @param \DVDoug\BoxPacker\PackedBoxList $packed_box_list
	 *
	 * @return void
	 */
	private function handle_packed_box_list( PackedBoxList $packed_box_list ) {

		if ( empty( $packed_box_list ) ) {
			return;
		}

		foreach ( $packed_box_list as $packed_box ) {
			$box = $packed_box->getBox();

			$packed_items  = array();
			$packed_volume = 0;
			$packed_value  = 0;
			foreach ( $packed_box->getItems() as $item ) {
				$item           = $item->getItem();
				$packed_items[] = $item;
				$packed_volume  += $item->get_volume();
				$packed_value   += $item->get_value();
			}

			$converted_weight = wc_get_weight( $packed_box->getWeight(), $this->weight_unit, 'g' );

			$this->packages[] = new Package( $box->getReference(), $box->get_type(), $converted_weight, $packed_volume, $box->get_outer_length(), $box->get_outer_width(), $box->get_outer_height(), $packed_value, array(), $packed_items );
		}
	}

	/**
	 * Check if unpacked items exist,
	 * then loop through and handle them.
	 *
	 * @param \DVDoug\BoxPacker\ItemList $unpacked_item_list
	 *
	 * @return void
	 */
	private function handle_unpacked_item_list( ItemList $unpacked_item_list ) {
		if ( ! empty( $unpacked_item_list ) ) {
			foreach ( $unpacked_item_list as $item ) {
				$this->handle_unpacked_item( $item );
			}
		}
	}

}