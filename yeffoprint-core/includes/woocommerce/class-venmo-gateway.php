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

	/**
	 * Venmo's own profile link (`venmo.com/u/username`) — opens the
	 * Venmo app on mobile (a universal link) or the profile page on
	 * desktop, either way landing the customer on a real "Pay" button.
	 * Deliberately not the app-only `venmo://paycharge?...` deep-link
	 * scheme some integrations use to prefill the amount/note: that one
	 * has no web fallback at all, so it just fails silently for anyone
	 * without the Venmo app installed (or opening this from a desktop
	 * email client) — a plain, always-working link beats a fancier one
	 * that sometimes does nothing. The amount/order-number the customer
	 * still needs to enter themselves are right next to this link in
	 * instructions_text().
	 */
	protected function pay_url(): ?string {
		$handle = ltrim( trim( (string) $this->get_option( 'handle' ) ), '@' );
		return '' !== $handle ? 'https://venmo.com/u/' . rawurlencode( $handle ) : null;
	}
}
