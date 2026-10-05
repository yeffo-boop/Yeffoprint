<?php
/**
 * The follow-up review request email (class-review-request.php), sent
 * through the site's own branded template the same way
 * class-email-abandoned-cart.php is: extends \WC_Email only for its
 * rendering plumbing, and is deliberately not registered with
 * `woocommerce_email_classes`, since its on/off switch and delay live
 * on the admin app's Reviews screen.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Email_Review_Request extends \WC_Email {

	private array $args = [];

	public function __construct() {
		$this->id             = 'yeffoprint_review_request';
		$this->customer_email = true;
		$this->template_html  = 'emails/customer-review-request.php';

		parent::__construct();
	}

	/**
	 * @param array{subject:string, email_heading:string, name:string, order_number:string,
	 *   lines:array, more:int, star_urls:array<int,string>,
	 *   review_url:string} $args
	 */
	public function send_request( string $to, array $args ): bool {
		$this->args      = $args;
		$this->recipient = $to;

		$this->setup_locale();
		$sent = $this->send( $to, $args['subject'], $this->get_content_html(), $this->get_headers(), $this->get_attachments() );
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
