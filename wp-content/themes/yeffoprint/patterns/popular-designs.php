<?php
/**
 * Title: Popular Designs
 * Slug: yeffoprint/popular-designs
 * Categories: yeffoprint
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow">Trending</p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="wp-block-heading has-text-align-center">Popular Right Now</h2>
	<!-- /wp:heading -->

	<!-- wp:query {"queryId":2,"query":{"perPage":4,"postType":"yp_template","order":"desc","orderBy":"meta_value_num","inherit":false,"metaKey":"_yp_popularity"},"className":"yp-card-grid"} -->
	<div class="wp-block-query yp-card-grid">
		<!-- wp:post-template -->
			<!-- wp:yeffoprint/template-card /-->
		<!-- /wp:post-template -->
	</div>
	<!-- /wp:query -->

</section>
<!-- /wp:group -->
