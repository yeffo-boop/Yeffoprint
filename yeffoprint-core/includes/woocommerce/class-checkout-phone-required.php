<?php
/**
 * Customers must give a phone number when they order.
 *
 * Direct request: "How do we require phone numbers from customers when
 * ordering? Some shipping methods require it". WooCommerce 11's block
 * checkout, its address field definitions (WC_Countries) and the
 * checkout block editor's own Phone toggle all read one option,
 * `woocommerce_checkout_phone_field` (hidden / optional / required).
 * Pinning it to "required" here keeps phone required even if that
 * editor toggle is later switched back. The payment link page for
 * admin-created orders has its own phone rule in
 * class-order-pay-address.php.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Checkout_Phone_Required {

	public function __construct() {
		add_filter( 'pre_option_woocommerce_checkout_phone_field', [ $this, 'required' ] );
	}

	public function required(): string {
		return 'required';
	}
}
