<?php
/**
 * Title: Reviews
 * Slug: yeffoprint/reviews
 * Categories: yeffoprint
 *
 * Published customer reviews (YeffoPrint_Order_Reviews: the ones left
 * from the Delivered email), with their photos and the store-wide
 * average. Hidden when there are none.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'YeffoPrint_Order_Reviews' ) ) {
	return;
}

$reviews = YeffoPrint_Order_Reviews::published( '', 3 );

if ( ! $reviews ) {
	return;
}

$summary = YeffoPrint_Order_Reviews::summary();
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow">Reviews</p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="wp-block-heading has-text-align-center">What Customers Say</h2>
	<!-- /wp:heading -->

	<!-- wp:html -->
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside both helpers (functions.php).
	echo yeffoprint_render_review_summary( $summary ) . yeffoprint_render_review_cards( $reviews );
	?>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
