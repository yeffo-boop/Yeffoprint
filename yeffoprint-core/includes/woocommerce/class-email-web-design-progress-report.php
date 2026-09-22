<?php
/**
 * The "progress report" email — same shape/reasoning as class-email-
 * web-design-staging-notice.php: an ad-hoc `send_notice()` email
 * (never hooked into `woocommerce_email_classes`, since nothing about
 * an order status triggers this — a staff member does, from the order
 * drawer's "Send a progress report" form), triggered by
 * class-admin-web-design-controller.php's send_progress_report().
 * Direct request: "provide progress reports to the customer by
 * email but also let them access all of the changes that have been
 * made on the site" — this is the email half; the CTA links to the
 * Updates tab (class-web-design-portal-controller.php's get_updates())
 * for the full, ongoing changelog.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Email_Web_Design_Progress_Report extends \WC_Email {

	private array $args = [];

	public function __construct() {
		$this->id             = 'yeffoprint_web_design_progress_report';
		$this->customer_email = true;
		$this->template_html  = 'emails/customer-web-design-progress-report.php';

		parent::__construct();
	}

	/**
	 * @param array{email_heading:string, name:string, headline:string, message:string, cta_url:string, stepper_html:string} $args
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
