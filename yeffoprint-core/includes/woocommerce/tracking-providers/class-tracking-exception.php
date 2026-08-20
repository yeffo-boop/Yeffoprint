<?php
/**
 * Thrown by any YeffoPrint_Tracking_Provider — deliberately its own
 * class rather than a generic \Exception, so class-order-tracking-
 * controller.php can catch tracking-lookup failures specifically
 * without also silently swallowing an unrelated bug elsewhere in the
 * same request.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracking_Exception extends \Exception {}
