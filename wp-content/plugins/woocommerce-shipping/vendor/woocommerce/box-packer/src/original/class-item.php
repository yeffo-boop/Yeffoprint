<?php

namespace WooCommerce\BoxPacker\Original;

use WooCommerce\BoxPacker\Abstract_Item;

/**
 * WC_Boxpack_Item class.
 */
class Item extends Abstract_Item {

	public function __construct( $length, $width, $height, $weight, $value = 0, $meta = array() ) {
		$dimensions = array( $length, $width, $height );

		sort( $dimensions );

		$this->length = floatval( $dimensions[2] );
		$this->width  = floatval( $dimensions[1] );
		$this->height = floatval( $dimensions[0] );

		$this->volume = floatval( $width * $height * $length );
		$this->weight = floatval( $weight );
		$this->value  = floatval( $value );
		$this->meta   = $meta;
	}

	/**
	 * @inerhitDoc
	 */
	public function get_volume() {
		return $this->volume;
	}

	/**
	 * @inerhitDoc
	 */
	public function get_height() {
		return $this->height;
	}

	/**
	 * @inerhitDoc
	 */
	public function get_width() {
		return $this->width;
	}

	/**
	 * @inerhitDoc
	 */
	public function get_length() {
		return $this->length;
	}

	/**
	 * @inerhitDoc
	 */
	public function get_weight() {
		return $this->weight;
	}

}
