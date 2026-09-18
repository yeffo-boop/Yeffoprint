<?php
/**
 * Title: Recently Viewed Designs
 * Slug: yeffoprint/recently-viewed
 * Categories: yeffoprint
 *
 * Reads the yp_recent_templates cookie written by recently-viewed.js
 * when a customer opens a template configurator page.
 */

defined( 'ABSPATH' ) || exit;

$exclude = is_singular( 'yp_template' ) ? (int) get_the_ID() : 0;
$ids     = yeffoprint_recent_template_ids( 4, $exclude );

if ( ! $ids ) {
	return;
}
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section">

	<!-- wp:paragraph {"className":"yp-eyebrow"} -->
	<p class="yp-eyebrow"><?php esc_html_e( 'Continue browsing', 'yeffoprint' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"level":2} -->
	<h2 class="wp-block-heading"><?php esc_html_e( 'Recently viewed', 'yeffoprint' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:html -->
	<?php yeffoprint_render_template_card_grid( $ids ); ?>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
