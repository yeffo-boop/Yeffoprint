<?php
/**
 * Logger class.
 *
 * A wrapper class to handle logging for the WooCommerce Shipping extension.
 *
 * @package Automattic/WCShipping
 */

namespace Automattic\WCShipping;

use Automattic\WCShipping\Connect\WC_Connect_Options;
use WC_Logger;

/**
 * Logger class.
 */
class Logger {

	/**
	 * WC Logger
	 *
	 * @var WC_Logger|null
	 */
	private static ?WC_Logger $logger = null;

	/**
	 * Is debug enabled.
	 *
	 * @var bool
	 */
	private static bool $is_debug_enabled = false;

	/**
	 * Constructor.
	 */
	private function __construct() {
		// No need to instantiate this class.
	}

	/**
	 * Initialize the logger.
	 */
	private static function init(): void {
		if ( is_null( self::$logger ) ) {
			self::$logger           = wc_get_logger();
			self::$is_debug_enabled = WC_Connect_Options::get_option( 'debug_logging_enabled' );
		}
	}

	/**
	 * Add a debug log entry.
	 * Only logs if debug is enabled.
	 *
	 * @param string $message Message to display.
	 * @param array  $data    Additional contextual data to pass.
	 *
	 * @return void
	 */
	public static function debug( string $message, array $data = array() ) {
		self::init();

		if ( ! self::$is_debug_enabled ) {
			return;
		}

		self::$logger->debug( $message, $data );
	}

	/**
	 * Add a warning log entry.
	 * Always logs regardless of debug setting, as warnings indicate potential issues.
	 *
	 * @param string $message Message to display.
	 * @param array  $data    Additional contextual data to pass.
	 *
	 * @return void
	 */
	public static function warning( string $message, array $data = array() ) {
		self::init();

		self::$logger->warning( $message, $data );
	}
}
