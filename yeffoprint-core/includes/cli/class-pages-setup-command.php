<?php
/**
 * Dev-only setup for site-structure pages — creates each one, assigned
 * to whatever theme template it needs, if a page with that slug
 * doesn't already exist. This is site structure, not demo content, so
 * it's a separate command from `wp yeffoprint seed` — same idempotent,
 * dev-triggered-only pattern as `wp yeffoprint setup-shipping`, never
 * run automatically, never overwrites an existing page.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Pages_Setup_Command {

	public function register(): void {
		\WP_CLI::add_command( 'yeffoprint setup-pages', [ $this, 'setup' ] );
	}

	/**
	 * ## EXAMPLES
	 *
	 *     wp yeffoprint setup-pages
	 */
	public function setup(): void {
		$this->create_page(
			'custom-design',
			__( 'Custom Design', 'yeffoprint-core' ),
			'',
			'custom-design-form.html'
		);

		// Reached via the customer's own one-time link (email or the
		// admin Proofs box), never linked from site nav — no account
		// required (class-proof-approval-controller.php's token check).
		$this->create_page(
			'proof-approval',
			__( 'Review Your Proof', 'yeffoprint-core' ),
			'',
			'proof-approval.html'
		);

		// Reached via the "Track your order" link in order emails
		// (class-order-tracking.php), never linked from site nav — same
		// no-account-required, token-in-the-URL pattern as proof-approval
		// above, just reusing WooCommerce's own order_key instead of a
		// new access token.
		$this->create_page(
			'track-order',
			__( 'Track Your Order', 'yeffoprint-core' ),
			'',
			'track-order.html'
		);

		$this->create_page(
			'custom-stickers',
			__( 'Custom Stickers', 'yeffoprint-core' ),
			'',
			'custom-stickers-form.html'
		);

		// No FSE template — the Label Designer moved into Custom Design as
		// a choice (blocks/label-designer-choice), so this page only
		// exists to 301 to /custom-design/ (functions.php's own
		// template_redirect hook) for anything that still links here.
		$this->create_page(
			'design-your-label',
			__( 'Build Your Own Label', 'yeffoprint-core' ),
			''
		);

		$this->create_page(
			'contact',
			__( 'Contact', 'yeffoprint-core' ),
			'',
			'contact-form.html'
		);

		$this->create_page(
			'how-it-works',
			__( 'How It Works', 'yeffoprint-core' ),
			'',
			'how-it-works.html'
		);

		$this->create_page(
			'web-design',
			__( 'Web Design for Peptide Resellers', 'yeffoprint-core' ),
			'',
			'web-design.html'
		);

		$this->create_page(
			'web-design-quote',
			__( 'Web Design Quote Request', 'yeffoprint-core' ),
			'',
			'web-design-quote.html'
		);

		$this->create_page(
			'add-to-order',
			__( 'Add to Order', 'yeffoprint-core' ),
			'',
			'add-to-order.html'
		);

		// The three Web Design portal pages — reached via the customer's
		// own one-time link (agreement/staging emails, or the
		// staging-review page's own redirect to go-live), never linked
		// from site nav — same no-account-required, token-in-the-URL
		// pattern as proof-approval above (class-web-design-portal-
		// controller.php's check_access()).
		$this->create_page(
			'web-design-agreement',
			__( 'Your Web Design Agreement', 'yeffoprint-core' ),
			'',
			'web-design-agreement.html'
		);

		$this->create_page(
			'web-design-staging-review',
			__( 'Review Your Staging Site', 'yeffoprint-core' ),
			'',
			'web-design-staging-review.html'
		);

		$this->create_page(
			'web-design-golive',
			__( "Let's Take Your Site Live", 'yeffoprint-core' ),
			'',
			'web-design-golive.html'
		);
	}

	private function create_page( string $slug, string $title, string $content, string $template = '' ): void {
		$existing = get_page_by_path( $slug );

		if ( $existing ) {
			\WP_CLI::log( 'A page at /' . $slug . '/ already exists — leaving it as-is.' );
			return;
		}

		$page_id = wp_insert_post( [
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
			'post_status'  => 'publish',
		], true );

		if ( is_wp_error( $page_id ) ) {
			\WP_CLI::error( $page_id->get_error_message() );
			return;
		}

		if ( $template ) {
			update_post_meta( $page_id, '_wp_page_template', $template );
		}

		\WP_CLI::success( 'Created the page at /' . $slug . '/.' );
	}
}
