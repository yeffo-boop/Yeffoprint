<?php
/**
 * Class RESTRequestException
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\Exceptions;

use WP_Error;
use Exception;

/**
 * Exception class for throwing errors in REST requests.
 */
class RESTRequestException extends Exception {
	public function get_error_response() {
		$error = new WP_Error(
			400,
			$this->getMessage(),
			array( 'message' => $this->getMessage() )
		);
		return $error;
	}
}
