<?php

namespace WooCommerce\BoxPacker;

class Package {

	/**
	 * @var string
	 */
	public $id;
	/**
	 * @var string
	 */
	public $type;
	/**
	 * @var float
	 */
	public $weight;
	/**
	 * @var float
	 */
	public $volume;
	/**
	 * @var float
	 */
	public $length;
	/**
	 * @var float
	 */
	public $width;
	/**
	 * @var float
	 */
	public $height;
	/**
	 * @var float
	 */
	public $value;
	/**
	 * @var array|bool
	 */
	public $unpacked;
	/**
	 * @var array
	 */
	public $packed;
	/**
	 * @var float|int
	 */
	public $percent;

	/**
	 * @param $id string
	 * @param $type string
	 * @param $weight float The total weight of the packed box
	 * @param $volume float The combined volume of all packed items
	 * @param $length float The outer length of the packed box
	 * @param $width float The outer width of the packed box
	 * @param $height float The outer height of the packed box
	 * @param $value float The combined value of all packed items
	 * @param array|bool $unpacked The unpacked items
	 *                             or true if packing an unpacked item individually
	 * @param array $packed The packed items
	 */
	public function __construct( $id, $type, $weight, $volume, $length, $width, $height, $value, $unpacked, $packed = array() ) {
		$this->id       = $id;
		$this->type     = $type;
		$this->weight   = floatval( $weight );
		$this->volume   = floatval( $volume );
		$this->length   = floatval( $length );
		$this->width    = floatval( $width );
		$this->height   = floatval( $height );
		$this->value    = floatval( $value );
		$this->unpacked = $unpacked;
		$this->packed   = $packed;
	}

}