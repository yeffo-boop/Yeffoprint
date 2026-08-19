<?php

namespace WooCommerce\BoxPacker\Original;

use Exception;
use WooCommerce\BoxPacker\Abstract_Packer;

class Packer extends Abstract_Packer {

	/**
	 * Add Item to items property.
	 *
	 * @param int|float|string $length Item Length.
	 * @param int|float|string $width Item width.
	 * @param int|float|string $height Item height.
	 * @param int|float|string $weight Item weight.
	 * @param int|float|string $value Item price.
	 * @param array            $meta Item metadata.
	 * @param int|float|string $qty Item quantity.
	 *
	 * @return void
	 */
	public function add_item( $length, $width, $height, $weight, $value = '', $meta = array(), $qty = 1 ) {
		for ( $i = 0; $i < $qty; $i++ ) {
			$this->items[] = new Item( $length, $width, $height, $weight, $value, $meta );
		}
	}

	/**
	 * add_box function.
	 *
	 * @param mixed $length
	 * @param mixed $width
	 * @param mixed $height
	 * @param mixed $weight
	 * @param float $max_weight
	 * @param string $type
	 *
	 * @return \WooCommerce\BoxPacker\Original\Box
	 */
	public function add_box( $length, $width, $height, $weight = 0, $max_weight = 0.0, $type = '' ) {
		$new_box       = new Box( $length, $width, $height, $weight, $max_weight, $type );
		$this->boxes[] = $new_box;

		return $new_box;
	}

	/**
	 * pack function.
	 *
	 * @return void
	 */
	public function pack() {
		try {
			// We need items
			if ( ! is_array( $this->items ) ) {
				throw new Exception( 'No items to pack!' );
			}

			// Clear packages
			$this->packages = array();

			// Order the boxes by volume
			$this->boxes = $this->order_boxes( $this->boxes );

			if ( ! $this->boxes ) {
				$this->cannot_pack = $this->items;
				$this->items       = array();
			}

			// Keep looping until packed
			while ( sizeof( $this->items ) > 0 ) {
				$this->items       = $this->order_items( $this->items );
				$possible_packages = array();
				$best_package      = '';

				// Attempt to pack all items in each box
				foreach ( $this->boxes as $box ) {
					$possible_packages[] = $box->pack( $this->items );
				}

				// Find the best success rate
				$best_percent = 0;

				foreach ( $possible_packages as $package ) {
					if ( $package->percent > $best_percent ) {
						$best_percent = $package->percent;
					}
				}

				if ( $best_percent == 0 ) {
					$this->cannot_pack = $this->items;
					$this->items       = array();
				} else {
					// Get smallest box with best_percent
					$possible_packages = array_reverse( $possible_packages );

					foreach ( $possible_packages as $package ) {
						if ( $package->percent == $best_percent ) {
							$best_package = $package;
							break; // Done packing
						}
					}

					// Update items array
					$this->items = $best_package->unpacked;

					// Store package
					$this->packages[] = $best_package;
				}
			}

			// Items we cannot pack (by now) get packaged individually
			if ( $this->cannot_pack ) {
				foreach ( $this->cannot_pack as $item ) {
					$this->handle_unpacked_item( $item );
				}
			}
		} catch ( Exception $e ) {

			$this->maybe_display_packing_error( $e->getMessage() );
		}
	}

}
