<?php
/**
 * Title: Reviews
 * Slug: yeffoprint/reviews
 * Categories: yeffoprint
 *
 * Approved WooCommerce product reviews only. SVG stars + mono attribution.
 * Hidden when there are none.
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

$star_svg = '<svg class="yp-review-card__star" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 1.5l2.4 5.2 5.6.6-4.2 3.8 1.2 5.5L10 13.8 4.9 16.6l1.2-5.5L2 7.3l5.6-.6z"/></svg>';
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
		<?php
		$i = 0;
		foreach ( $reviews as $review ) :
			$rating = (int) get_comment_meta( $review->comment_ID, 'rating', true );
			$author = $review->comment_author ? $review->comment_author : __( 'Customer', 'yeffoprint' );
			$body   = wp_strip_all_tags( $review->comment_content );
			if ( '' === $body ) {
				continue;
			}
			$accent = [ 'cyan', 'magenta', 'yellow' ][ $i % 3 ];
			$i++;
			?>
			<div class="yp-review-card yp-review-card--accent-<?php echo esc_attr( $accent ); ?>">
				<?php if ( $rating > 0 ) : ?>
					<div class="yp-review-card__stars" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: star rating */ __( '%d out of 5 stars', 'yeffoprint' ), $rating ) ); ?>">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup above.
						echo str_repeat( $star_svg, min( 5, $rating ) );
						?>
					</div>
				<?php endif; ?>
				<p>&ldquo;<?php echo esc_html( $body ); ?>&rdquo;</p>
				<p class="yp-review-card__author">— <?php echo esc_html( $author ); ?></p>
			</div>
		<?php endforeach; ?>
	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
