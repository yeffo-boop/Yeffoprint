<?php
/**
 * Title: Featured Designs
 * Slug: yeffoprint/featured-designs
 * Categories: yeffoprint
 */

defined( 'ABSPATH' ) || exit;
?>
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
		<p><a href="/shop-labels/">View all designs &rarr;</a></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

	<!-- wp:query {"queryId":1,"query":{"perPage":4,"postType":"yp_template","order":"desc","orderBy":"date","inherit":false,"metaKey":"_yp_featured","metaValue":"1"}} -->
	<div class="wp-block-query">
		<!-- wp:post-template {"className":"yp-card-grid"} -->
			<!-- wp:yeffoprint/template-card /-->
		<!-- /wp:post-template -->
	</div>
	<!-- /wp:query -->

</section>
<!-- /wp:group -->
