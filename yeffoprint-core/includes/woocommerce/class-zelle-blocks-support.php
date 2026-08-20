<?php
/**
 * Checkout-block registration for Zelle — see class-manual-payment-
 * blocks-support.php.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Zelle_Blocks_Support extends YeffoPrint_Manual_Payment_Blocks_Support {

	protected $name = 'yeffoprint_zelle';

	protected function script_handle(): string {
		return 'yeffoprint-zelle-blocks';
	}

	protected function script_path(): string {
		return 'assets/blocks/zelle-payment-method.js';
	}
}
