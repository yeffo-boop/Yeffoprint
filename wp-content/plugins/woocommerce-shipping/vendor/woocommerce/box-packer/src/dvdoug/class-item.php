<?php

namespace WooCommerce\BoxPacker\DVDoug;

use WooCommerce\BoxPacker\Abstract_Item;

class Item extends Abstract_Item implements \DVDoug\BoxPacker\Item {

	use Util;

	/**
	 * @var float
	 */
	private $original_length;
	/**
	 * @var float
	 */
	private $original_width;
	/**
	 * @var float
	 */
	private $original_height;
	/**
	 * @var float
	 */
	private $original_weight;
	/**
	 * @var string
	 */
	private $dimension_unit;
	/**
	 * @var string
	 */
	private $weight_unit;
	/**
	 * @var string
	 */
	private $description;
	/**
	 * @var int
	 */
	private $depth;
	/**
	 * @var int
	 */
	private $keepFlat;

	public function __construct( $dimension_unit, $weight_unit, $length, $width, $height, $weight, $value = 0, $meta = array(), $keep_flat = false ) {

		$this->dimension_unit = $dimension_unit;
		$this->weight_unit    = $weight_unit;

		$dimensions = array( $length, $width, $height );

		sort( $dimensions );

		/**
		 * Set original values, so we don't have to convert back
		 */
		$this->original_length = floatval( $dimensions[2] );
		$this->original_width  = floatval( $dimensions[1] );
		$this->original_height = floatval( $dimensions[0] );
		$this->original_weight = floatval( $weight );

		/**
		 * Set values with unit conversion to work with
		 * DVDoug library
		 */
		$this->length = $this->convert_to_mm( $dimensions[2] );
		$this->width  = $this->convert_to_mm( $dimensions[1] );
		$this->depth  = $this->convert_to_mm( $dimensions[0] );
		$this->volume = $this->width * $this->depth * $this->length;

		$this->weight = $this->convert_to_g( $weight );

		$this->keepFlat = $keep_flat;

		$this->description = implode( 'x', array( $this->length, $this->width, $this->depth ) );

		$this->value = floatval( $value );
		$this->meta  = $meta;
	}

	/**
	 * @inheritDoc
	 */
	public function get_volume() {
		return $this->original_length * $this->original_width * $this->original_height;
	}

	/**
	 * @inheritDoc
	 */
	public function get_height(): float {
		return $this->original_height;
	}

	/**
	 * @inheritDoc
	 */
	public function get_width(): float {
		return $this->original_width;
	}

	/**
	 * @inheritDoc
	 */
	public function get_length(): float {
		return $this->original_length;
	}

	/**
	 * @inheritDoc
	 */
	public function get_weight(): float {
		return $this->original_weight;
	}

	/**
	 * @inerhitDoc
	 */
	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * @inerhitDoc
	 */
	public function getWidth(): int {
		return $this->width;
	}

	/**
	 * @inerhitDoc
	 */
	public function getLength(): int {
		return $this->length;
	}

	/**
	 * @inerhitDoc
	 */
	public function getDepth(): int {
		return $this->depth;
	}

	/**
	 * @inerhitDoc
	 */
	public function getWeight(): int {
		return $this->weight;
	}

	/**
	 * @inerhitDoc
	 */
	public function getKeepFlat(): bool {
		return $this->keepFlat;
	}

}