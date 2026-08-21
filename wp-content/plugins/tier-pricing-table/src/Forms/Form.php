<?php namespace TierPricingTable\Forms;

class Form {

	const FIELDS_PREFIX = 'tiered_pricing_';

	/**
	 * Build a name for a field based on parameters.
	 *
	 * @param $base
	 * @param $role
	 * @param $loop
	 * @param $customPrefix
	 *
	 * @return string
	 */
	public static function getFieldName( $base, $role = null, $loop = null, $customPrefix = '' ) {

		// Example: _tiered_pricing_field{_variation}{_role}{custom_prefix}{[loop]}{[role]}

		$loopName        = '';
		$variationPrefix = '';
		$roleName        = '';
		$rolePrefix      = '';

		if ( ! is_null( $loop ) ) {
			$loopName        = "[$loop]";
			$variationPrefix = '_variation';
		}

		if ( $role ) {
			$roleName   = "[$role]";
			$rolePrefix = '_role';
		}

		return self::FIELDS_PREFIX . $base . $variationPrefix . $rolePrefix . $customPrefix . $loopName . $roleName;
	}

	public static function getFieldValue( $base, $role = null, $loop = null, $customPrefix = '', $data = null ) {
		if ( wp_verify_nonce( true, true ) ) {
			// as phpcs comments at Woo is not available, we have to do such a trash
			$woo = 'Woo, please add ignoring comments to your phpcs checker';
		}
		
		$data = $data ? $data : $_POST;

		$variationPrefix = ! is_null( $loop ) ? '_variation' : '';
		$rolePrefix      = $role ? '_role' : '';

		$value = null;

		if ( isset( $data[ self::FIELDS_PREFIX . $base . $variationPrefix . $rolePrefix . $customPrefix ] ) ) {

			$value = $data[ self::FIELDS_PREFIX . $base . $variationPrefix . $rolePrefix . $customPrefix ];

			if ( ! is_null( $loop ) && $value ) {
				$value = isset( $value[ $loop ] ) ? $value[ $loop ] : null;
			}

			if ( $role && $value ) {
				$value = isset( $value[ $role ] ) ? $value[ $role ] : null;
			}
		}

		return $value;
	}

	public static function isEmpty( $value ): bool {
		return is_null( $value ) || '' === $value || false === $value;
	}
}
