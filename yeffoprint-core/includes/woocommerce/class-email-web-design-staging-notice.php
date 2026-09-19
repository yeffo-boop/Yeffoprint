<?php
/**
 * The "your staging site is ready" email — same shape/reasoning as
 * class-email-web-design-agreement-notice.php, sent from
 * class-admin-web-design-controller.php's send_staging() once staff
 * finish filling in the staged-site credentials in the admin app.
 * Carries the client preview login in plain text (this is the one time
 * those credentials are ever shown outside the admin app — there is no
 * separate "reveal" step for the customer, they own that login), and
 * links to the staging-review page (Approve / Request changes) rather
 * than putting either action directly in the email, since "approve"
 * has to be a real authenticated write, not a bare GET link a mail
 * scanner or link-preview bot could trigger by prefetching it.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Email_Web_Design_Staging_Notice extends \WC_Email {

	private array $args = [];

	public function __construct() {
		$this->id             = 'yeffoprint_web_design_staging_notice';
		$this->customer_email = true;
		$this->template_html  = 'emails/customer-web-design-staging.php';

		parent::__construct();
	}

	/**
	 * @param array{email_heading:string, name:string, staging_url:string, preview_user:string,
	 *   preview_password:string, note:string, cta_review_url:string, revisions_due:string, stepper_html:string} $args
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
