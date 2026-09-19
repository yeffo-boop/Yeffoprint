<?php
/**
 * The "please sign your web design agreement" email — same shape as
 * class-email-proof-notice.php (extends \WC_Email purely to reuse its
 * template-rendering/style-inlining/send() plumbing so this comes out
 * branded like every other outgoing email), sent from
 * class-admin-web-design-controller.php's send_agreement() once staff
 * finish building the agreement in the admin app.
 *
 * Not registered with `woocommerce_email_classes` — same reasoning as
 * the proof notice: this isn't an admin-toggleable Settings -> Emails
 * row, it only ever fires from one explicit staff action.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Email_Web_Design_Agreement_Notice extends \WC_Email {

	private array $args = [];

	public function __construct() {
		$this->id             = 'yeffoprint_web_design_agreement_notice';
		$this->customer_email = true;
		$this->template_html  = 'emails/customer-web-design-agreement.php';

		parent::__construct();
	}

	/**
	 * @param array{email_heading:string, name:string, package_name:string, cta_url:string, stepper_html:string} $args
	 */
	public function send_notice( string $to, string $subject, array $args ): bool {
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
