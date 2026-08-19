<?php

namespace Automattic\WCShipping;

class Validators {
	/**
	 * Regular expression pattern for validating ISO 8601 formatted date strings.
	 * Format: YYYY-MM-DDThh:mm:ss.sssZ or YYYY-MM-DDThh:mm:ss.sss+hh:mm
	 *
	 * Pattern is formatted without delimiters for compatibility with WordPress's
	 * rest_validate_json_schema_pattern() function which adds its own #...#u delimiters.
	 */
	const ISO8601_PATTERN = '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$';

	/**
	 * Validates if a parameter is a boolean-like value.
	 * Accepts: 'true', 'false', true, false, '0', '1', 0, 1
	 *
	 * @param mixed            $param The parameter to validate
	 * @param \WP_REST_Request $request The request object
	 * @param string           $key The parameter key
	 * @return bool Whether the parameter is valid
	 */
	public static function validate_boolean_like( $param, $request, $key ): bool {
		return in_array( $param, array( 'true', 'false', true, false, '0', '1', 0, 1 ), true );
	}
}
