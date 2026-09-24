<?php
/**
 * The two abandoned-cart reminder emails (class-abandoned-carts.php),
 * sent through the site's own branded template the same way
 * class-email-proof-notice.php sends proof emails: extends \WC_Email
 * only for its rendering plumbing (theme template lookup, inlined
 * email-styles.php, From/Content-Type headers), and is deliberately
 * not registered with `woocommerce_email_classes` — its on/off switch
 * and timing live on the admin app's Abandoned Carts screen.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Email_Abandoned_Cart extends \WC_Email {

	private array $args = [];

	public function __construct() {
		$this->id             = 'yeffoprint_abandoned_cart';
		$this->customer_email = true;
		$this->template_html  = 'emails/customer-abandoned-cart.php';

		parent::__construct();
	}

	/**
	 * @param array{email_heading:string, name:string, intro:string,
	 *   lines:array, total:float, coupon_code:string, discount_note:string,
	 *   button_label:string, cta_url:string, optout_url:string,
	 *   is_last:bool, telegram_url:string} $args
	 */
	public function send_reminder( string $to, string $subject, array $args ): bool {
		$this->args      = $args;
		$this->recipient = $to;

		$this->setup_locale();
		$sent = $this->send( $to, $subject, $this->get_content_html(), $this->get_headers(), $this->get_attachments() );
		$this->restore_locale();

		return (bool) $sent;
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array_merge( $this->args, [ 'email' => $this ] )
		);
	}
}
