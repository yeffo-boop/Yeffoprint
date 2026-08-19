<?php

namespace Automattic\WCShipping\Packages;

/**
 * Accepts a collection of packages as arrays, sanitizes them, and returns them as WCShip/WCS&T formatted arrays.
 *
 * It might seem weird to use this class to create `Package` objects from an array of packages as arrays (i.e.
 * package data instead of `Package` instances) only to convert them back into an array of packages as arrays.
 *
 * The reason this happens is that the constructor of the `Package` class contains some validation rules
 * as well as logic to map the keys used by WCS&T and some earlier WCShip versions to a consistent format.
 */
class PackagesAsArraysSanitizer {

	private array $packages;

	/**
	 * Construct from an array of packages as arrays (i.e. from package data instead of `Package `instances).
	 *
	 * The `$throw_on_failure` parameter is especially useful when reading package data
	 * from the database which might fail to the validation of the `Package` class
	 * in an unexpected way.
	 *
	 * @param array $packages_as_arrays Array of packages to map to Package instances.
	 * @param bool  $throw_on_failure Whether to throw an exception if mapping to a Package failed.
	 *
	 * @throws PackageValidationException Rethrows `Package` validation if `throw_on_failure === true`.
	 */
	public function __construct( array $packages_as_arrays, bool $throw_on_failure = true ) {
		$packages = array_map(
			fn( array $package_as_array ) => $this->map_to_package( $package_as_array, $throw_on_failure ),
			$packages_as_arrays
		);

		$this->packages = array_filter( $packages );
	}

	/**
	 * Return an array of packages as arrays after sanitizing to WCShip format.
	 *
	 * @return array{
	 *     id: string,
	 *     name: string,
	 *     dimensions: string,
	 *     boxWeight: float,
	 *     maxWeight: float,
	 *     type: string,
	 *     is_user_defined: bool
	 * }[]
	 */
	public function to_packages_as_arrays(): array {
		return array_map(
			fn( Package $package ) => $package->to_array(),
			$this->packages
		);
	}

	/**
	 * Return an array of packages as arrays after sanitizing to WCS&T format.
	 *
	 * @return array{
	 *     id: string,
	 *     name: string,
	 *     dimensions: string,
	 *     box_weight: float,
	 *     max_weight: float,
	 *     is_letter: bool,
	 *     is_user_defined: bool
	 * }[]
	 */
	public function to_packages_as_wcst_arrays(): array {
		return array_map(
			fn( Package $package ) => $package->to_wcst_array(),
			$this->packages
		);
	}


	/**
	 * Return an array of packages as arrays after sanitizing to new API format.
	 *
	 * @return array{
	 *     id: string,
	 *     name: string,
	 *     dimensions: string,
	 *     length: float,
	 *     width: float,
	 *     height: float,
	 *     box_weight: float,
	 *     is_letter: bool,
	 *     is_user_defined: bool
	 * }[]
	 */
	public function to_packages_as_api_arrays(): array {
		return array_map(
			fn( Package $package ) => $package->to_api_array(),
			$this->packages
		);
	}

	private function map_to_package( array $package_as_array, bool $throw_on_failure ): ?Package {
		try {
			return Package::from_array( $package_as_array );
		} catch ( PackageValidationException $e ) {
			if ( $throw_on_failure ) {
				throw $e;
			} else {
				return null;
			}
		}
	}
}
