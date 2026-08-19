<?php

/**
 * This is a custom fatal error handler for the Nexcess Managed Applications (MAPPS) platform.
 *
 * @author Nexcess
 */

use Nexcess\MAPPS\Support\FatalErrorHandler;

$file = __DIR__ . '/../Support/FatalErrorHandler.php';

// If we can't locate the MAPPS version, use the WordPress default.
if ( ! is_readable( $file ) ) {
	return new WP_Fatal_Error_Handler();
}

require_once $file;

return new FatalErrorHandler();
