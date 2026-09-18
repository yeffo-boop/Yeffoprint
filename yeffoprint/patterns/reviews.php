<?php
/**
 * Title: Reviews
 * Slug: yeffoprint/reviews
 * Categories: yeffoprint
 *
 * Approved WooCommerce product reviews only. Hidden when there are
 * none — never invents quotes or a "Verified Buyer" badge.
 */

defined( 'ABSPATH' ) || exit;

$review_args = [
	'status'  => 'approve',
	'type'    => 'review',
	'number'  => 3,
	'orderby' => 'comment_date_gmt',
	'order'   => 'DESC',
];

$reviews = get_comments( $review_args );

if ( ! $reviews ) {
	return;
}
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
		<?php foreach ( $reviews as $review ) :
			$rating = (int) get_comment_meta( $review->comment_ID, 'rating', true );
			$author = $review->comment_author ? $review->comment_author : __( 'Customer', 'yeffoprint' );
			$body   = wp_strip_all_tags( $review->comment_content );
			if ( '' === $body ) {
				continue;
			}
			?>
			<div class="yp-review-card">
				<?php if ( $rating > 0 ) : ?>
					<div class="yp-review-card__stars" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: star rating */ __( '%d out of 5 stars', 'yeffoprint' ), $rating ) ); ?>">
						<?php echo esc_html( str_repeat( '★', min( 5, $rating ) ) ); ?>
					</div>
				<?php endif; ?>
				<p>&ldquo;<?php echo esc_html( $body ); ?>&rdquo;</p>
				<p class="yp-review-card__author"><?php echo esc_html( $author ); ?></p>
			</div>
		<?php endforeach; ?>
	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
