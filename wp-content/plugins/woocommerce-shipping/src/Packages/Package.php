<?php

namespace Automattic\WCShipping\Packages;

class Package {

	const TYPE_BOX      = 'box';
	const TYPE_ENVELOPE = 'envelope';

	private string $id;
	private string $name;
	private string $dimensions;
	private float $length;
	private float $width;
	private float $height;
	private float $box_weight;
	private float $max_weight;
	private string $type;
	private bool $is_user_defined = true;

	/**
	 * Creates an instance of the class from package data provided as an array.
	 *
	 * @throws PackageValidationException
	 */
	public static function from_array( array $data ): Package {
		$data = self::map_wcst_keys_to_wcshipping_keys( $data );
		self::validate_from_array_input( $data );

		return new Package(
			$data['id'] ?? null,
			$data['name'],
			$data['type'] ?? self::TYPE_BOX,
			$data['dimensions'],
			$data['boxWeight'],
			$data['maxWeight'] ?? 0.0
		);
	}

	/**
	 * @throws PackageValidationException
	 */
	public function __construct( ?string $id, string $name, string $type, string $dimensions, float $box_weight, ?float $max_weight = 0.0 ) {
		$this->name       = $name;
		$this->type       = $type;
		$this->dimensions = $dimensions;
		$this->box_weight = $box_weight;
		$this->max_weight = $max_weight;
		$this->set_or_generate_id( $id );
		$this->validate();
		$this->set_length_width_height_from_dimensions( $dimensions );
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function __get( $property ) {
		if ( property_exists( $this, $property ) ) {
			return $this->$property;
		}
		throw new \InvalidArgumentException( sprintf( 'Property %s does not exist.', esc_html( $property ) ) );
	}

	/**
	 * @return array{
	 *     id: string,
	 *     name: string,
	 *     dimensions: string,
	 *     boxWeight: float,
	 *     maxWeight: float,
	 *     type: string,
	 *     is_user_defined: true
	 * }
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'name'            => $this->name,
			'dimensions'      => $this->dimensions,
			'boxWeight'       => $this->box_weight,
			'maxWeight'       => $this->max_weight,
			'type'            => $this->type,
			'is_user_defined' => $this->is_user_defined,
		);
	}

	/**
	 * @return array{
	 *     id: string,
	 *     name: string,
	 *     inner_dimensions: string,
	 *     box_weight: float,
	 *     max_weight: float,
	 *     is_letter: bool,
	 *     is_user_defined: bool
	 * }
	 */
	public function to_wcst_array(): array {
		return array(
			'id'               => $this->id,
			'name'             => $this->name,
			'inner_dimensions' => $this->dimensions,
			'box_weight'       => $this->box_weight,
			'max_weight'       => $this->max_weight,
			'is_letter'        => self::TYPE_ENVELOPE === $this->type,
			'is_user_defined'  => $this->is_user_defined,
		);
	}

	public function to_api_array(): array {
		return array(
			'id'              => $this->id,
			'name'            => $this->name,
			'dimensions'      => $this->dimensions,
			'length'          => $this->length,
			'width'           => $this->width,
			'height'          => $this->height,
			'box_weight'      => $this->box_weight,
			'is_letter'       => self::TYPE_ENVELOPE === $this->type,
			'is_user_defined' => $this->is_user_defined,
			'type'            => $this->type,
		);
	}

	/**
	 * Generate an ID if the package does not have one yet.
	 *
	 * We need a unique ID for each package to be able to identify them as a box_id
	 * to prepopulate the saved templates form, and other types of situations where
	 * uniqueness is required.
	 *
	 * This introduces backwards support for migrated WCS&T packages that do not have an ID
	 * and older version of WC Shipping that had a bug not setting an ID.
	 *
	 * @since 1.2.2 Moved this method here from `WC_Connect_Service_Settings_Store`.
	 * @since 1.1.2 This logic was introduced in `WC_Connect_Service_Settings_Store`.
	 *
	 * @return void
	 */
	private function set_or_generate_id( $id ) {
		if ( ! empty( $id ) && 'custom_box' !== $id ) {
			$this->id = $id;
		} else {
			$this->id = md5( $this->name );
		}
	}

	/**
	 * Throws if the package instance has invalid data.
	 *
	 * @return void
	 * @throws PackageValidationException
	 */
	private function validate() {
		if ( ! in_array( $this->type, array( self::TYPE_ENVELOPE, self::TYPE_BOX ), true ) ) {
			throw new PackageValidationException( 'Invalid package type' );
		}

		if ( 1 !== preg_match( '/^(.+) x (.+) x (.+)$/', $this->dimensions ) ) {
			throw new PackageValidationException( 'Invalid package dimensions' );
		}

		if ( $this->box_weight < 0 ) {
			throw new PackageValidationException( 'Invalid package weight' );
		}

		if ( ! empty( $this->max_weight ) && $this->max_weight < 0 ) {
			throw new PackageValidationException( 'Invalid package max weight' );
		}
	}

	/**
	 * @param array $data Package data as array.
	 *
	 * @return array Package data as array with WCS&T keys mapped to WCShip keys.
	 */
	private static function map_wcst_keys_to_wcshipping_keys( array $data ): array {
		if ( isset( $data['max_weight'] ) && ! isset( $data['maxWeight'] ) ) {
			$data['maxWeight'] = $data['max_weight'];
			unset( $data['max_weight'] );
		}

		if ( isset( $data['box_weight'] ) && ! isset( $data['boxWeight'] ) ) {
			$data['boxWeight'] = $data['box_weight'];
			unset( $data['box_weight'] );
		}

		if ( isset( $data['inner_dimensions'] ) && ! isset( $data['dimensions'] ) ) {
			$data['dimensions'] = $data['inner_dimensions'];
			unset( $data['inner_dimensions'] );
		}

		if ( isset( $data['is_letter'] ) && ! isset( $data['type'] ) ) {
			$data['type'] = $data['is_letter'] ? self::TYPE_ENVELOPE : self::TYPE_BOX;
			unset( $data['is_letter'] );
		}

		return $data;
	}

	/**
	 * Throws if the array of package data to create a Package from is invalid.
	 *
	 * @return void
	 * @throws PackageValidationException
	 */
	private static function validate_from_array_input( array $data ) {
		$required_keys   = array( 'name', 'dimensions', 'boxWeight' );
		$stringable_keys = array( 'id', 'name', 'type', 'dimensions' );
		$numeric_keys    = array( 'boxWeight', 'maxWeight' );

		foreach ( $required_keys as $required_key ) {
			if ( ! isset( $data[ $required_key ] ) ) {
				throw new PackageValidationException( sprintf( 'Missing required key: %s', esc_html( $required_key ) ) );
			}
		}

		foreach ( $stringable_keys as $stringable_key ) {
			if ( isset( $data[ $stringable_key ] ) && ! is_scalar( $data[ $stringable_key ] ) && ! is_null( $data[ $stringable_key ] ) ) {
				throw new PackageValidationException( sprintf( 'Key %s must be a string', esc_html( $stringable_key ) ) );
			}
		}

		foreach ( $numeric_keys as $numeric_key ) {
			if ( isset( $data[ $numeric_key ] ) && ! is_numeric( $data[ $numeric_key ] ) ) {
				throw new PackageValidationException( sprintf( 'Key %s must be a number', esc_html( $numeric_key ) ) );
			}
		}
	}

	/**
	 * Convert dimensions from "LxWxH" to "length", "width", "height".
	 *
	 * @param string $dimensions The dimensions string in the format "LxWxH".
	 *
	 * @return array{length: string, width: string, height: string}
	 */
	private function dimensions_to_length_width_height( string $dimensions ): array {
		list( $length, $width, $height ) = explode( 'x', $dimensions );
		return array(
			'length' => trim( $length ),
			'width'  => trim( $width ),
			'height' => trim( $height ),
		);
	}

	/**
	 * Set the length, width, and height from the dimensions.
	 *
	 * @param string $dimensions The dimensions string in the format "LxWxH".
	 *
	 * @return void
	 */
	private function set_length_width_height_from_dimensions( string $dimensions ) {
		$dimensions_array = $this->dimensions_to_length_width_height( $dimensions );
		$this->length     = (float) $dimensions_array['length'];
		$this->width      = (float) $dimensions_array['width'];
		$this->height     = (float) $dimensions_array['height'];
	}
}
