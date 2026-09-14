<?php
/**
 * Renders and sends the two customer-facing proof emails — "proof
 * ready" and the 24h/48h reminders — through the site's own branded
 * HTML template instead of the bare wp_mail() string they used to send
 * as. Direct report, with a screenshot: "It's not styled at all. ALL
 * outgoing emails from the site should follow our same styling format."
 *
 * Extends \WC_Email purely to reuse its rendering plumbing:
 * get_content_html()'s wc_get_template_html() call resolves this
 * theme's own emails/customer-proof-notice.php override via the exact
 * same locate_template() lookup every other emails/*.php file in this
 * theme already relies on (no plugin-side default template needed,
 * since the theme copy is always found first); style_inline() runs the
 * shared email-styles.php rules through Emogrifier so they survive
 * Gmail/Outlook stripping <style> blocks, the same as every other
 * WooCommerce email; send() dispatches through wp_mail() with the
 * correct From/Content-Type headers already applied.
 *
 * Deliberately never registered with `woocommerce_email_classes` — this
 * doesn't need a Settings -> Emails row of its own, since these two
 * emails aren't (and weren't, before this change) admin-toggleable;
 * YeffoPrint_Proof_Meta's own docblock already calls them "best-effort
 * only," always attempted the same as before.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Email_Proof_Notice extends \WC_Email {

	private array $args = [];

	public function __construct() {
		$this->id             = 'yeffoprint_proof_notice';
		$this->customer_email = true;
		$this->template_html  = 'emails/customer-proof-notice.php';

		parent::__construct();
	}

	/**
	 * @param array{email_heading:string, name:string, eyebrow:string,
	 *   intro:string, cta_url:string, proof_image_url:string} $args
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
