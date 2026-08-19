<?php
namespace Automattic\WCShipping\Utilities;

/**
 * Base model class providing common functionality for data objects
 * Uses get_object_vars() for flexible serialization across different types
 */
abstract class BaseModel {
	/**
	 * Convert the model to an array using get_object_vars()
	 *
	 * This approach automatically includes all public properties
	 * and handles nested models recursively.
	 *
	 * @param string|null $key Optional property key to serialize only that property
	 * @return mixed The model data as an associative array, or specific property value
	 */
	public function to_array( ?string $key = null ) {
		$vars = get_object_vars( $this );

		// If a specific key is requested
		if ( $key !== null ) {
			if ( ! array_key_exists( $key, $vars ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Property "%s" does not exist on %s', esc_html( $key ), esc_html( static::class ) )
				);
			}
			return $this->serialize_value( $vars[ $key ] );
		}

		// Default behavior - return all properties
		$result = array();
		foreach ( $vars as $property => $value ) {
			$result[ $property ] = $this->serialize_value( $value );
		}

		return $result;
	}

	/**
	 * Recursively serialize a value for array conversion
	 *
	 * @param mixed $value The value to serialize
	 * @return mixed The serialized value
	 */
	private function serialize_value( $value ) {
		if ( $value instanceof BaseModel ) {
			// Nested model - convert to array
			return $value->to_array();
		}

		if ( is_array( $value ) ) {
			// Array - recursively serialize each element
			return array_map( array( $this, 'serialize_value' ), $value );
		}

		// Primitive value - return as-is
		return $value;
	}

	/**
	 * Convert to JSON string
	 *
	 * @return string JSON representation of the model
	 */
	public function to_json(): string {
		return wp_json_encode( $this->to_array() );
	}
}
