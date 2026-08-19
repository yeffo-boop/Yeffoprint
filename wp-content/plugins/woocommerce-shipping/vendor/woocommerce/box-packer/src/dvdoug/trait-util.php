<?php

namespace WooCommerce\BoxPacker\DVDoug;

/**
 * Functions to help keep our code clean and dry.
 */
trait Util {

	/**
	 * Convert the passed value to millimeters from the
	 * inheriting class dimension_unit property.
	 *
	 * @param $dimension
	 *
	 * @return int
	 */
	public function convert_to_mm( $dimension ): int {
		return intval( round( wc_get_dimension( $dimension, 'mm', $this->dimension_unit ) ) );
	}

	/**
	 * Convert the passed value to grams from the
	 * inheriting class dimension_unit property.
	 *
	 * @param $weight
	 *
	 * @return int
	 */
	public function convert_to_g( $weight ): int {
		return intval( round( wc_get_weight( $weight, 'g', $this->weight_unit ) ) );
	}

}