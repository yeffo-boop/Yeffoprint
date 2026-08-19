<?php

namespace WooCommerce\BoxPacker;

abstract class Abstract_Packer {

	protected $dimension_unit;
	protected $weight_unit;
	protected $boxes;
	protected $items;
	protected $packages;
	protected $cannot_pack;
	/**
	 * @var bool Try to pack into envelopes and packets
	 */
	protected $prefer_packets = false;

	/**
	 * __construct function.
	 *
	 * @param string $dimension_unit
	 * @param string $weight_unit
	 * @param array $options Optional. An array of options.
	 *
	 * @since 1.0.2 Added `$options` parameter and '$prefer_packets' option.
	 *
	 * @return void
	 */
	public function __construct( $dimension_unit, $weight_unit, $options = array() ) {
		$this->dimension_unit = $dimension_unit;
		$this->weight_unit    = $weight_unit;

		if ( isset( $options['prefer_packets'] ) ) {
			$this->prefer_packets = $options['prefer_packets'];
		}
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
	 * @param int|float|string $qty Item quantity.
	 *
	 * @return void
	 */
	abstract public function add_item( $length, $width, $height, $weight, $value = '', $meta = array(), $qty = 1 );

	abstract public function add_box( $length, $width, $height, $weight = 0, $max_weight = 0.0, $type = '' );

	abstract public function pack();

	/**
	 * clear_items function.
	 *
	 * @return void
	 */
	public function clear_items() {
		$this->items = array();
	}

	/**
	 * clear_boxes function.
	 *
	 * @return void
	 */
	public function clear_boxes() {
		$this->boxes = array();
	}

	/**
	 * clear_packages function.
	 *
	 * @return void
	 */
	public function clear_packages() {
		$this->packages = array();
	}

	/**
	 * get_packages function.
	 *
	 * @return array
	 */
	public function get_packages() {
		return $this->packages ?: array();
	}

	/**
	 * handle_unpacked_item function.
	 *
	 * @return void
	 */
	public function handle_unpacked_item( $item ) {
		$this->packages[] = new Package( '', 'box', $item->get_weight(), $item->get_volume(), $item->get_length(), $item->get_width(), $item->get_height(), $item->get_value(), true );
	}

	/**
	 * If the current user is an admin,
	 * show them a packing error to help
	 * them debug the issue.
	 *
	 * @param string $message The packing error message to display.
	 *
	 * @return void
	 */
	public function maybe_display_packing_error( $message ) {
		if ( current_user_can( 'manage_options' ) ) {
			echo 'Packing error: ', $message, "\n";
		}
	}

	/**
	 * Order boxes by weight and volume
	 *
	 * @param array $sort
	 *
	 * @return array
	 */
	protected function order_boxes( $sort ) {
		if ( ! empty( $sort ) ) {
			uasort( $sort, array( $this, 'box_sorting' ) );
		}

		return $sort;
	}

	/**
	 * Order items by weight and volume
	 * $param array $sort
	 *
	 * @return array
	 */
	protected function order_items( $sort ) {
		if ( ! empty( $sort ) ) {
			uasort( $sort, array( $this, 'item_sorting' ) );
		}

		return $sort;
	}

	/**
	 * item_sorting function.
	 *
	 *
	 * @param mixed $a
	 * @param mixed $b
	 *
	 * @return int
	 */
	public function item_sorting( $a, $b ) {
		if ( $a->get_volume() == $b->get_volume() ) {
			if ( $a->get_weight() == $b->get_weight() ) {
				return 0;
			}

			return ( $a->get_weight() < $b->get_weight() ) ? 1 : - 1;
		}

		return ( $a->get_volume() < $b->get_volume() ) ? 1 : - 1;
	}

	/**
	 * box_sorting function.
	 *
	 *
	 * @param mixed $a
	 * @param mixed $b
	 *
	 * @return int
	 */
	public function box_sorting( $a, $b ) {

		if ( $this->prefer_packets ) {
			// check 'envelope', 'packet' first as they are cheaper even if their volume is more
			$a_cheaper_packaging = in_array( $a->get_type(), array( 'envelope', 'packet' ) );
			$b_cheaper_packaging = in_array( $b->get_type(), array( 'envelope', 'packet' ) );

			if ( $a_cheaper_packaging && ! $b_cheaper_packaging ) {
				return 1;
			}

			if ( $b_cheaper_packaging && ! $a_cheaper_packaging ) {
				return - 1;
			}
		}

		if ( $a->get_volume() == $b->get_volume() ) {
			if ( $a->get_max_weight() == $b->get_max_weight() ) {
				return 0;
			}

			return ( $a->get_max_weight() < $b->get_max_weight() ) ? 1 : - 1;
		}

		return ( $a->get_volume() < $b->get_volume() ) ? 1 : - 1;
	}

}
