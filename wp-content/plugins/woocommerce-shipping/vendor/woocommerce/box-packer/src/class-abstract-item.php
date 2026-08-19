<?php

namespace WooCommerce\BoxPacker;

abstract class Abstract_Item {

	protected $length;
	protected $width;
	protected $height;
	protected $weight;
	protected $volume;
	protected $value;
	protected $meta;

	/**
	 * get_value function.
	 *
	 * @return float
	 */
	function get_value() {
		return $this->value;
	}

	/**
	 * get_meta function.
	 *
	 * @return array
	 */
	function get_meta( $key = '' ) {
		if ( $key ) {
			if ( isset( $this->meta[ $key ] ) ) {
				return $this->meta[ $key ];
			} else {
				return NULL;
			}
		} else {
			return array_filter( (array) $this->meta );
		}
	}

	/**
	 * get_volume function.
	 *
	 * @return float
	 */
	abstract function get_volume();

	/**
	 * get_height function.
	 *
	 * @return float
	 */
	abstract function get_height();

	/**
	 * get_width function.
	 *
	 * @return float
	 */
	abstract function get_width();

	/**
	 * get_width function.
	 *
	 * @return float
	 */
	abstract function get_length();

	/**
	 * get_width function.
	 *
	 * @return float
	 */
	abstract function get_weight();

}