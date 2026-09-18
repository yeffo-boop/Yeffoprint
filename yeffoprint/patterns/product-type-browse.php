<?php
/**
 * Title: Product Type Browse
 * Slug: yeffoprint/product-type-browse
 * Categories: yeffoprint
 *
 * Product Type cards with a real vial/artwork thumb from a template
 * in that term, linking into gallery filter URLs (?yp_product_type=).
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

/**
 * First published template in a product-type term that has a vial or artwork.
 *
 * @return array{url:string,alt:string}|null
 */
$image_for_term = static function ( int $term_id ): ?array {
	if ( ! class_exists( 'YeffoPrint_Template_Meta' ) ) {
		return null;
	}

	$ids = get_posts( [
		'post_type'      => 'yp_template',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'fields'         => 'ids',
		'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			[
				'taxonomy' => 'yp_product_type',
				'field'    => 'term_id',
				'terms'    => [ $term_id ],
			],
		],
	] );

	if ( ! $ids ) {
		return null;
	}

	$card = function_exists( 'yeffoprint_core_get_template_card_data' )
		? yeffoprint_core_get_template_card_data( (int) $ids[0] )
		: null;

	if ( ! $card ) {
		return null;
	}

	$url = (string) ( $card['vial_mockup_url'] ?: $card['artwork_url'] ?: '' );
	if ( ! $url ) {
		return null;
	}

	return [
		'url' => $url,
		'alt' => $card['title'] ?: '',
	];
};
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section" id="yp-product-type-browse">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow"><?php esc_html_e( 'Browse by product', 'yeffoprint' ); ?></p>
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
			$url   = add_query_arg( 'yp_product_type', $term->slug, $base );
			$image = $image_for_term( (int) $term->term_id );
			?>
			<a class="yp-product-type-browse__card<?php echo $image ? ' yp-product-type-browse__card--photo' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
				<?php if ( $image ) : ?>
					<span class="yp-product-type-browse__media">
						<img
							src="<?php echo esc_url( $image['url'] ); ?>"
							alt="<?php echo esc_attr( $image['alt'] ); ?>"
							loading="lazy"
							decoding="async"
						/>
					</span>
				<?php endif; ?>
				<span class="yp-product-type-browse__body">
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
				</span>
			</a>
		<?php endforeach; ?>
	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
