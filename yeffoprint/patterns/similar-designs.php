<?php
/**
 * Title: Similar Designs
 * Slug: yeffoprint/similar-designs
 * Categories: yeffoprint
 * Inserter: no
 *
 * Same product type (and style as a soft boost) as the current template.
 * Featured Designs rhythm (tint + View all).
 */

defined( 'ABSPATH' ) || exit;

$current_id = (int) get_the_ID();
$post       = get_post( $current_id );

if ( ! $post || 'yp_template' !== $post->post_type ) {
	return;
}

$product_types = wp_get_post_terms( $current_id, 'yp_product_type', [ 'fields' => 'ids' ] );
$styles        = wp_get_post_terms( $current_id, 'yp_style', [ 'fields' => 'ids' ] );

if ( is_wp_error( $product_types ) ) {
	$product_types = [];
}
if ( is_wp_error( $styles ) ) {
	$styles = [];
}

$query_args = [
	'post_type'      => 'yp_template',
	'post_status'    => 'publish',
	'posts_per_page' => 4,
	'post__not_in'   => [ $current_id ],
	'orderby'        => 'date',
	'order'          => 'DESC',
	'fields'         => 'ids',
];

if ( $product_types ) {
	$query_args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		[
			'taxonomy' => 'yp_product_type',
			'field'    => 'term_id',
			'terms'    => $product_types,
		],
	];
} elseif ( $styles ) {
	$query_args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		[
			'taxonomy' => 'yp_style',
			'field'    => 'term_id',
			'terms'    => $styles,
		],
	];
}

$ids = get_posts( $query_args );

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
			<p class="yp-eyebrow"><?php esc_html_e( 'You might also like', 'yeffoprint' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":2} -->
			<h2 class="wp-block-heading"><?php esc_html_e( 'Similar designs', 'yeffoprint' ); ?></h2>
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
