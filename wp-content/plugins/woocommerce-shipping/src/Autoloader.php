<?php
/**
 * Includes the composer Autoloader used for packages and classes in the src/ directory.
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping;

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader class.
 *
 * @since 1.0.5
 */
class Autoloader {

	/**
	 * Static-only class.
	 */
	private function __construct() {}

	/**
	 * Require the autoloader and return the result.
	 *
	 * If the autoloader is not present, let's log the failure and display a nice admin notice.
	 *
	 * @return boolean
	 */
	public static function init() {
		$autoloader = WCSHIPPING_PLUGIN_DIR . '/vendor/autoload_packages.php';

		if ( ! is_readable( $autoloader ) ) {
			return false;
		}

		$autoloader_result = require $autoloader;
		if ( ! $autoloader_result ) {
			return false;
		}

		return $autoloader_result;
	}
}
