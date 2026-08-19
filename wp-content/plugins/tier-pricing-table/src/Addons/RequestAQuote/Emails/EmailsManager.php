<?php namespace TierPricingTable\Addons\RequestAQuote\Emails;

class EmailsManager {

	public function __construct() {
		add_filter( 'woocommerce_email_classes', array( $this, 'register_emails' ) );
	}

	/**
	 * Register custom WooCommerce emails.
	 *
	 * @param array $emails
	 * @return array
	 */
	public function register_emails( $emails ) {
		require_once 'AdminQuoteEmail.php';
		require_once 'CustomerQuoteEmail.php';

		$emails['AdminQuoteEmail']    = new AdminQuoteEmail();
		$emails['CustomerQuoteEmail'] = new CustomerQuoteEmail();

		return $emails;
	}
}
