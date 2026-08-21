<?php

namespace WooCommerce\BoxPacker\DVDoug;

class Box implements \DVDoug\BoxPacker\Box, \WooCommerce\BoxPacker\Box {

	use Util;

	/**
	 * @var string
	 */
	private $dimension_unit;
	/**
	 * @var string
	 */
	private $weight_unit;
	/**
	 * @var float
	 */
	private $original_outer_length;
	/**
	 * @var float
	 */
	private $original_outer_width;
	/**
	 * @var float
	 */
	private $original_outer_height;
	/**
	 * @var float
	 */
	private $original_inner_length;
	/**
	 * @var float
	 */
	private $original_inner_width;
	/**
	 * @var float
	 */
	private $original_inner_height;
	/**
	 * @var float
	 */
	private $original_weight;
	/**
	 * @var float
	 */
	private $original_max_weight;
	/**
	 * @var float
	 */
	private $original_volume;
	/**
	 * @var int
	 */
	private $outerWidth;
	/**
	 * @var int
	 */
	private $outerLength;
	/**
	 * @var int
	 */
	private $outerDepth;
	/**
	 * @var int
	 */
	private $emptyWeight;
	/**
	 * @var int
	 */
	private $innerWidth;
	/**
	 * @var int
	 */
	private $innerLength;
	/**
	 * @var int
	 */
	private $innerDepth;
	/**
	 * @var int
	 */
	private $maxWeight;
	/**
	 * @var string
	 */
	private $id;
	/**
	 * @var string
	 */
	private $type;

	public function __construct( $dimension_unit, $weight_unit, $length, $width, $height, $weight = 0.0, $max_weight = 0.0, $type = 'box' ) {

		$this->dimension_unit = $dimension_unit;
		$this->weight_unit    = $weight_unit;

		/**
		 * Set original values, so we don't have to convert back
		 */
		$this->original_outer_length = floatval( $length );
		$this->original_outer_width  = floatval( $width );
		$this->original_outer_height = floatval( $height );
		$this->original_weight       = floatval( $weight );
		$this->original_max_weight   = floatval( $max_weight );
		$this->original_inner_length = $this->original_outer_length;
		$this->original_inner_width  = $this->original_outer_width;
		$this->original_inner_height = $this->original_outer_height;
		$this->original_volume       = floatval( $this->original_inner_length * $this->original_inner_width * $this->original_inner_height );

		/**
		 * Set values with unit conversion to work with
		 * DVDoug library
		 */
		$this->outerWidth  = $this->convert_to_mm( $width );
		$this->outerLength = $this->convert_to_mm( $length );
		$this->outerDepth  = $this->convert_to_mm( $height );
		$this->innerWidth  = $this->outerWidth;
		$this->innerLength = $this->outerLength;
		$this->innerDepth  = $this->outerDepth;

		$this->emptyWeight = $this->convert_to_g( $weight );
		$this->maxWeight   = $this->convert_to_g( $max_weight );

		$this->type = $type;
	}

	/**
	 * Begin \WooCommerce\BoxPacker\Box required methods
	 */

	/**
	 * @inheritDoc
	 */
	public function set_id( $id ) {
		$this->id = $id;
	}

	/**
	 * @inheritDoc
	 */
	public function set_volume( $volume ) {
		/**
		 * Set original volume, so we don't have to convert back
		 */
		$this->original_volume = floatval( $volume );
	}

	/**
	 * @inheritDoc
	 */
	public function set_max_weight( $weight ) {
		/**
		 * Set original max weight, so we don't have to convert back
		 */
		$this->original_max_weight = floatval( $weight );

		/**
		 * Set max weight with unit conversion to work with
		 * DVDoug library
		 */
		$this->maxWeight = $this->convert_to_g( $weight );
	}

	/**
	 * @inheritDoc
	 */
	public function set_inner_dimensions( $length, $width, $height ) {
		$dimensions = array(
			$length,
			$width,
			$height
		);

		sort( $dimensions );

		/**
		 * Set original inner values, so we don't have to convert back
		 */
		$this->original_inner_length = floatval( $dimensions[2] );
		$this->original_inner_width  = floatval( $dimensions[1] );
		$this->original_inner_height = floatval( $dimensions[0] );

		/**
		 * Set inner values with unit conversion to work with
		 * DVDoug library
		 */
		$this->innerLength = $this->convert_to_mm( $width );
		$this->innerWidth  = $this->convert_to_mm( $length );
		$this->innerDepth  = $this->convert_to_mm( $height );
	}

	/**
	 * @inheritDoc
	 */
	public function set_type( $type ) {
		$this->type = $type;
	}

	/**
	 * @inheritDoc
	 */
	public function get_type() {
		return $this->type;
	}

	/**
	 * @inheritDoc
	 */
	public function get_max_weight(): float {
		return $this->original_max_weight;
	}

	/**
	 * @inheritDoc
	 */
	public function get_volume(): float {
		return $this->original_volume;
	}

	/**
	 * @inheritDoc
	 */
	public function get_height(): float {
		return $this->original_inner_height;
	}

	/**
	 * @inheritDoc
	 */
	public function get_width(): float {
		return $this->original_inner_width;
	}

	/**
	 * @inheritDoc
	 */
	public function get_length(): float {
		return $this->original_inner_length;
	}

	/**
	 * @inheritDoc
	 */
	public function get_weight(): float {
		return $this->original_weight;
	}

	/**
	 * @inheritDoc
	 */
	public function get_outer_height(): float {
		return $this->original_outer_height;
	}

	/**
	 * @inheritDoc
	 */
	public function get_outer_width(): float {
		return $this->original_outer_width;
	}

	/**
	 * @inheritDoc
	 */
	public function get_outer_length(): float {
		return $this->original_outer_length;
	}

	/**
	 * Begin \DVDoug\BoxPacker\Box required methods
	 */

	/**
	 * @inheritDoc
	 */
	public function getReference(): string {
		return $this->id;
	}

	/**
	 * @inheritDoc
	 */
	public function getOuterWidth(): int {
		return $this->outerWidth;
	}

	/**
	 * @inheritDoc
	 */
	public function getOuterLength(): int {
		return $this->outerLength;
	}

	/**
	 * @inheritDoc
	 */
	public function getOuterDepth(): int {
		return $this->outerDepth;
	}

	/**
	 * @inheritDoc
	 */
	public function getEmptyWeight(): int {
		return $this->emptyWeight;
	}

	/**
	 * @inheritDoc
	 */
	public function getInnerWidth(): int {
		return $this->innerWidth;
	}

	/**
	 * @inheritDoc
	 */
	public function getInnerLength(): int {
		return $this->innerLength;
	}

	/**
	 * @inheritDoc
	 */
	public function getInnerDepth(): int {
		return $this->innerDepth;
	}

	/**
	 * @inheritDoc
	 */
	public function getMaxWeight(): int {
		return $this->maxWeight;
	}

}