<?php
/**
 * Title: Product Type Browse
 * Slug: yeffoprint/product-type-browse
 * Categories: yeffoprint
 *
 * MOCK — first-class Product Type browse cards linking into the gallery
 * filter URLs (?yp_product_type=). Replace copy/art when this graduates
 * from prototype.
 */

defined( 'ABSPATH' ) || exit;

$terms = get_terms( [
	'taxonomy'   => 'yp_product_type',
	'hide_empty' => true,
] );

if ( is_wp_error( $terms ) || ! $terms ) {
	return;
}

$base = get_post_type_archive_link( 'yp_template' ) ?: home_url( '/shop-labels/' );
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section" id="yp-product-type-browse">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow"><?php esc_html_e( 'Mock · Browse by product', 'yeffoprint' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="wp-block-heading has-text-align-center"><?php esc_html_e( 'What are you labeling?', 'yeffoprint' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center"} -->
	<p class="has-text-align-center"><?php esc_html_e( 'Jump straight into Cosmetics, Peptides, Skincare, or Supplements — same gallery, already filtered.', 'yeffoprint' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:html -->
	<div class="yp-product-type-browse">
		<?php foreach ( $terms as $term ) :
			$url = add_query_arg( 'yp_product_type', $term->slug, $base );
			?>
			<a class="yp-product-type-browse__card" href="<?php echo esc_url( $url ); ?>">
				<span class="yp-product-type-browse__name"><?php echo esc_html( $term->name ); ?></span>
				<span class="yp-product-type-browse__count">
					<?php
					printf(
						/* translators: %d: number of designs */
						esc_html( _n( '%d design', '%d designs', (int) $term->count, 'yeffoprint' ) ),
						(int) $term->count
					);
					?>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
