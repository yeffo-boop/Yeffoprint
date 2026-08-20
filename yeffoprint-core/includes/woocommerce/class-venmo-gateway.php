<?php
/**
 * Venmo checkout option — see class-manual-payment-gateway.php for
 * the shared behavior (on-hold until matched, then auto-processed).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Venmo_Gateway extends YeffoPrint_Manual_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'yeffoprint_venmo';
		$this->icon                = '';
		$this->method_title        = __( 'Venmo', 'yeffoprint-core' );
		$this->method_description  = __( 'Customer pays you directly via Venmo. The order is held until the payment is matched — see the "Automatic matching" section below.', 'yeffoprint-core' );

		parent::__construct();
	}

	protected function method_slug(): string {
		return 'venmo';
	}

	protected function handle_field_label(): string {
		return __( 'Venmo username (e.g. @YeffoPrint)', 'yeffoprint-core' );
	}

	protected function default_description(): string {
		return __( 'Pay with Venmo — we\'ll confirm your payment and begin your order shortly after.', 'yeffoprint-core' );
	}
}
