<?php
/**
 * Dev-only setup for the "Custom Design" page — creates it assigned to
 * the theme's Custom Design Form template if a page with that slug
 * doesn't already exist. This is site structure, not demo content, so
 * it's a separate command from `wp yeffoprint seed` — same idempotent,
 * dev-triggered-only pattern as `wp yeffoprint setup-shipping`, never
 * run automatically, never overwrites an existing page.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Pages_Setup_Command {

	private const SLUG     = 'custom-design';
	private const TEMPLATE = 'custom-design-form.html';

	public function register(): void {
		\WP_CLI::add_command( 'yeffoprint setup-pages', [ $this, 'setup' ] );
	}

	/**
	 * ## EXAMPLES
	 *
	 *     wp yeffoprint setup-pages
	 */
	public function setup(): void {
		$existing = get_page_by_path( self::SLUG );

		if ( $existing ) {
			\WP_CLI::log( 'A page at /' . self::SLUG . '/ already exists — leaving it as-is.' );
			\WP_CLI::success( 'Nothing to do.' );
			return;
		}

		$page_id = wp_insert_post( [
			'post_type'   => 'page',
			'post_title'  => __( 'Custom Design', 'yeffoprint-core' ),
			'post_name'   => self::SLUG,
			'post_status' => 'publish',
		], true );

		if ( is_wp_error( $page_id ) ) {
			\WP_CLI::error( $page_id->get_error_message() );
			return;
		}

		update_post_meta( $page_id, '_wp_page_template', self::TEMPLATE );

		\WP_CLI::success( 'Created the Custom Design page at /' . self::SLUG . '/.' );
	}
}
