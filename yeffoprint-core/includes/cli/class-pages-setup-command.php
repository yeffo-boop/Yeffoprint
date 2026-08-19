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

		// Custom Stickers is header/footer nav (PROJECT_SPEC §7) for a
		// Phase 2 product line that's an explicit V1 non-goal (§19) —
		// without this page the nav link 404s. Plain default template
		// (no custom-stickers-form.html exists yet, nor should it until
		// that phase actually starts) with a "coming soon" body, same
		// honest-placeholder pattern as the My Account Saved
		// Designs/Rewards tabs (class-account-endpoints.php).
		$this->create_page(
			'custom-stickers',
			__( 'Custom Stickers', 'yeffoprint-core' ),
			$this->coming_soon_content()
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

	private function coming_soon_content(): string {
		return <<<'HTML'
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"680px"}} -->
<section class="wp-block-group yp-section">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow">Coming Soon</p>
	<!-- /wp:paragraph -->

	<!-- wp:paragraph {"align":"center"} -->
	<p class="has-text-align-center">We're building a full custom sticker configurator. In the meantime, browse our vial labels or reach out and we'll help directly.</p>
	<!-- /wp:paragraph -->

	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button {"className":"is-style-accent"} -->
		<div class="wp-block-button is-style-accent"><a class="wp-block-button__link wp-element-button" href="/shop-labels/">Browse Vial Labels</a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->

</section>
<!-- /wp:group -->
HTML;
	}
}
