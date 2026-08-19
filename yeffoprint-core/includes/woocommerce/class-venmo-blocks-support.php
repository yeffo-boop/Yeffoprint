<?php
/**
 * Checkout-block registration for Venmo — see class-manual-payment-
 * blocks-support.php.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Venmo_Blocks_Support extends YeffoPrint_Manual_Payment_Blocks_Support {

	protected $name = 'yeffoprint_venmo';

	protected function script_handle(): string {
		return 'yeffoprint-venmo-blocks';
	}

	protected function script_path(): string {
		return 'assets/blocks/venmo-payment-method.js';
	}
}
