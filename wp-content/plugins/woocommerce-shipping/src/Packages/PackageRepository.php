<?php

namespace Automattic\WCShipping\Packages;

use Automattic\WCShipping\Connect\WC_Connect_Options;

/**
 * Handles CRUD operations on saved packages.
 */
class PackageRepository {

	/**
	 * Gets custom packages from the database.
	 *
	 * @return array[]
	 */
	public function get_custom_packages() {
		$packages_as_array_sanitizer = new PackagesAsArraysSanitizer(
			WC_Connect_Options::get_option( 'packages', array() ),
			false
		);

		return $packages_as_array_sanitizer->to_packages_as_arrays();
	}

	/**
	 * Adds packages to the collection of existing custom packages in the database.
	 *
	 * @param array[] $new_packages Array of packages-as-arrays.
	 *
	 * @throws PackageValidationException If at least one of the provided packages doesn't pass validation.
	 */
	public function add_custom_packages( array $new_packages ) {
		$this->replace_custom_packages(
			array_merge(
				$this->get_custom_packages(),
				$new_packages
			)
		);
	}

	/**
	 * Replaces the collection of existing custom packages in the database with the provided packages.
	 *
	 * @param array[] $packages Array of packages-as-arrays.
	 *
	 * @throws PackageValidationException If at least one of the provided packages doesn't pass validation.
	 */
	public function replace_custom_packages( array $packages ) {
		WC_Connect_Options::update_option(
			'packages',
			( new PackagesAsArraysSanitizer( $packages ) )->to_packages_as_arrays()
		);
	}
}
