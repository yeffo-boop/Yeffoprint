<?php

namespace WooCommerce\BoxPacker;

interface Box {

	/**
	 * set_id function.
	 *
	 * @param mixed $id
	 *
	 * @return void
	 */
	public function set_id( $id );

	/**
	 * Set the volume to a specific value, instead of calculating it.
	 *
	 * @param float $volume
	 */
	public function set_volume( $volume );

	/**
	 * Get the type of the box.
	 *
	 * @return string Box type.
	 * @version 1.0.1
	 *
	 * @since 1.0.1
	 */
	public function get_type();

	/**
	 * Set the type of box
	 *
	 * @param string $type
	 */
	public function set_type( $type );

	/**
	 * Get max weight.
	 *
	 * @return float
	 */
	public function get_max_weight();

	/**
	 * set_max_weight function.
	 *
	 *
	 * @param mixed $weight
	 *
	 * @return void
	 */
	public function set_max_weight( $weight );

	/**
	 * set_inner_dimensions function.
	 *
	 * @param mixed $length
	 * @param mixed $width
	 * @param mixed $height
	 *
	 * @return void
	 */
	public function set_inner_dimensions( $length, $width, $height );

	/**
	 * get_volume function.
	 *
	 * @return float
	 */
	public function get_volume();

	/**
	 * get_height function.
	 *
	 * @return float
	 */
	public function get_height();

	/**
	 * get_width function.
	 *
	 * @return float
	 */
	public function get_width();

	/**
	 * get_width function.
	 *
	 * @return float
	 */
	public function get_length();

	/**
	 * get_weight function.
	 *
	 * @return float
	 */
	public function get_weight();

	/**
	 * get_outer_height
	 *
	 * @return float
	 */
	public function get_outer_height();

	/**
	 * get_outer_width
	 *
	 * @return float
	 */
	public function get_outer_width();

	/**
	 * get_outer_length
	 *
	 * @return float
	 */
	public function get_outer_length();

}