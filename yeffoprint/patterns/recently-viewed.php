<?php
/**
 * Title: Recently Viewed Designs
 * Slug: yeffoprint/recently-viewed
 * Categories: yeffoprint
 *
 * Reads the yp_recent_templates cookie. Matches Featured Designs
 * rhythm (tint + View all).
 */

defined( 'ABSPATH' ) || exit;

$exclude = is_singular( 'yp_template' ) ? (int) get_the_ID() : 0;
$ids     = yeffoprint_recent_template_ids( 4, $exclude );

if ( ! $ids ) {
	return;
}

$gallery = get_post_type_archive_link( 'yp_template' ) ?: home_url( '/shop-labels/' );
?>
<!-- wp:group {"tagName":"section","className":"yp-section yp-section--tint","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section yp-section--tint">

	<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap"}} -->
	<div class="wp-block-group">
		<!-- wp:group {"layout":{"type":"constrained"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"className":"yp-eyebrow"} -->
			<p class="yp-eyebrow"><?php esc_html_e( 'Continue browsing', 'yeffoprint' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":2} -->
			<h2 class="wp-block-heading"><?php esc_html_e( 'Recently viewed', 'yeffoprint' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:group -->

		<!-- wp:paragraph -->
		<p><a class="yp-view-all-link" href="<?php echo esc_url( $gallery ); ?>"><?php esc_html_e( 'View all designs', 'yeffoprint' ); ?> &rarr;</a></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

	<!-- wp:html -->
	<?php yeffoprint_render_template_card_grid( $ids ); ?>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
