<?php
/**
 * Title: Reviews
 * Slug: yeffoprint/reviews
 * Categories: yeffoprint
 *
 * Placeholder testimonials — replace with real, attributed customer
 * reviews before launch. Left generic and unattributed on purpose so
 * this section never gets mistaken for real reviews if it ships
 * before that swap happens.
 */

defined( 'ABSPATH' ) || exit;

$placeholder_reviews = [
	__( 'Turnaround was fast and the print quality matched the preview almost exactly.', 'yeffoprint' ),
	__( 'Being able to split one order across a few label variants saved us a second order entirely.', 'yeffoprint' ),
	__( 'The custom design process was easy — proof came back quickly and the changes were painless.', 'yeffoprint' ),
];
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
	<div class="yp-reviews-grid">
		<?php foreach ( $placeholder_reviews as $review ) : ?>
			<div class="yp-review-card">
				<div class="yp-review-card__stars" aria-label="5 out of 5 stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
				<p>&ldquo;<?php echo esc_html( $review ); ?>&rdquo;</p>
				<p class="yp-review-card__author"><em><?php esc_html_e( 'Placeholder review — Verified Buyer', 'yeffoprint' ); ?></em></p>
			</div>
		<?php endforeach; ?>
	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
