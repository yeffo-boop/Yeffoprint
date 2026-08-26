<?php
/**
 * Gates the Web Design page to admins only while its content is still
 * being finalized — direct request: "put a role gate on that page so
 * only admins can see it right now. Other users should see a coming
 * soon page instead." Same 'manage_options' capability every other
 * admin-only surface in this plugin already gates on (Dashboard,
 * Settings, …).
 *
 * A `page_template_hierarchy` filter, not a redirect or a content
 * swap in the pattern itself — this is the standard, documented
 * WordPress mechanism for "resolve this page to a different block
 * template based on a runtime condition" (get_page_template() runs
 * its candidate list through this filter, then hands it to
 * locate_block_template()); the page keeps its own real URL and
 * resolves normally for an admin previewing it or a direct link
 * shared internally, only what actually renders there changes.
 * Prepending the coming-soon template's own filename wins the search
 * WordPress does over that candidate list, since it takes the first
 * match.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Page_Gate {

	public function __construct() {
		add_filter( 'page_template_hierarchy', [ $this, 'maybe_swap_template' ] );
	}

	public function maybe_swap_template( array $templates ): array {
		if ( is_page( 'web-design' ) && ! current_user_can( 'manage_options' ) ) {
			array_unshift( $templates, 'web-design-coming-soon.html' );
		}

		return $templates;
	}
}
