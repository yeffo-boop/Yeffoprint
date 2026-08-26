<?php
/**
 * Title: Featured Designs
 * Slug: yeffoprint/featured-designs
 * Categories: yeffoprint
 *
 * Direct bug report: "The featured indicator on the vial templates is
 * not what the homepage uses to decide what to put there. I checked
 * the box that said featured on the [a] template and it doesn't show
 * on the homepage." Confirmed by elimination with the owner: the
 * section WAS showing templates, but not ones actually marked
 * Featured (YeffoPrint_Template_Meta::FEATURED, '_yp_featured') — it
 * was relying on the Query Loop block's own `metaKey`/`metaValue`
 * JSON attributes, which depend on WordPress core translating them
 * into an actual meta filter at render time. On this site that
 * translation wasn't taking effect, so the query silently fell back
 * to "4 most recent yp_template posts," featured or not.
 *
 * Fixed by resolving the actual featured post IDs in plain PHP
 * (get_posts(), the same reliable meta-query pattern every other
 * "live data" section of this theme already uses — see
 * materials.php/material-guide.php) and passing that exact ID list to
 * the Query Loop via `include` — a plain array of post IDs has always
 * been unambiguous and correctly supported, unlike the metaKey/
 * metaValue attribute pair this depended on before. Card rendering
 * itself (wp:post-template + the yeffoprint/template-card block) is
 * untouched — only which posts get selected changed.
 */

defined( 'ABSPATH' ) || exit;

$featured_ids = get_posts( [
	'post_type'      => 'yp_template',
	'post_status'    => 'publish',
	'posts_per_page' => 4,
	'orderby'        => 'date',
	'order'          => 'DESC',
	'meta_key'       => YeffoPrint_Template_Meta::FEATURED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, admin-managed table.
	'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- small, admin-managed table.
	'fields'         => 'ids',
] );
?>
<?php if ( $featured_ids ) : ?>
<!-- wp:group {"tagName":"section","className":"yp-section yp-section--tint","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section yp-section--tint">

	<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between"}} -->
	<div class="wp-block-group">
		<!-- wp:group {"layout":{"type":"constrained"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"className":"yp-eyebrow"} -->
			<p class="yp-eyebrow">Featured</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":2} -->
			<h2 class="wp-block-heading">Featured Designs</h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:group -->

		<!-- wp:paragraph -->
		<p><a class="yp-view-all-link" href="/shop-labels/">View all designs &rarr;</a></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

	<!-- wp:query {"queryId":1,"query":{"perPage":4,"postType":"yp_template","order":"desc","orderBy":"date","inherit":false,"include":<?php echo wp_json_encode( array_values( $featured_ids ) ); ?>}} -->
	<div class="wp-block-query">
		<!-- wp:post-template {"className":"yp-card-grid"} -->
			<!-- wp:yeffoprint/template-card /-->
		<!-- /wp:post-template -->
	</div>
	<!-- /wp:query -->

</section>
<!-- /wp:group -->
<?php endif; ?>
