<?php
/**
 * Title: Product Type Browse
 * Slug: yeffoprint/product-type-browse
 * Categories: yeffoprint
 *
 * Product Type cards, each with its own image (the term's chosen
 * Image, else a template from that term not already on another card),
 * linking into gallery filter URLs (?yp_product_type=).
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
 * Image for a product-type tile. The term's own "Image" (set under
 * YeffoPrint → Product Types) wins. Otherwise use the newest published
 * template in the term whose vial/artwork isn't already on another
 * tile: templates usually sit in several product types, so "just the
 * newest" put the same bottle on every tile. Falls back to the newest
 * one anyway if every candidate is taken.
 *
 * @param string[] $used Image URLs already placed on earlier tiles.
 * @return array{url:string,alt:string}|null
 */
$image_for_term = static function ( WP_Term $term, array $used ): ?array {
	if ( class_exists( 'YeffoPrint_Product_Type_Image' ) ) {
		$url = YeffoPrint_Product_Type_Image::get_url( (int) $term->term_id );
		if ( $url ) {
			return [
				'url' => $url,
				'alt' => $term->name,
			];
		}
	}

	if ( ! class_exists( 'YeffoPrint_Template_Meta' ) || ! function_exists( 'yeffoprint_core_get_template_card_data' ) ) {
		return null;
	}

	$ids = get_posts( [
		'post_type'      => 'yp_template',
		'post_status'    => 'publish',
		'posts_per_page' => 20,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'fields'         => 'ids',
		'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			[
				'taxonomy' => 'yp_product_type',
				'field'    => 'term_id',
				'terms'    => [ (int) $term->term_id ],
			],
		],
	] );

	$first = null;

	foreach ( $ids as $id ) {
		$card = yeffoprint_core_get_template_card_data( (int) $id );
		$url  = $card ? (string) ( $card['vial_mockup_url'] ?: $card['artwork_url'] ?: '' ) : '';

		if ( ! $url ) {
			continue;
		}

		$image = [
			'url' => $url,
			'alt' => $card['title'] ?: '',
		];

		if ( ! in_array( $url, $used, true ) ) {
			return $image;
		}

		$first = $first ?? $image;
	}

	return $first;
};

// Resolve every tile up front so terms with a chosen image claim it
// before the automatic picks run and try to avoid it.
$images = [];
$used   = [];

foreach ( $terms as $term ) {
	if ( class_exists( 'YeffoPrint_Product_Type_Image' ) ) {
		$url = YeffoPrint_Product_Type_Image::get_url( (int) $term->term_id );
		if ( $url ) {
			$used[] = $url;
		}
	}
}

foreach ( $terms as $term ) {
	$image = $image_for_term( $term, $used );

	$images[ $term->term_id ] = $image;
	if ( $image ) {
		$used[] = $image['url'];
	}
}
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section" id="yp-product-type-browse">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow"><?php esc_html_e( 'Browse by product', 'yeffoprint' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="wp-block-heading has-text-align-center"><?php esc_html_e( 'What are you labeling?', 'yeffoprint' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center","className":"yp-product-type-browse__intro"} -->
	<p class="has-text-align-center yp-product-type-browse__intro"><?php esc_html_e( 'Jump straight into Cosmetics, Peptides, Pens, Skincare, or Supplements — same gallery, already filtered.', 'yeffoprint' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:html -->
	<?php
	// These tiles are the Shop Labels filter now that the Show/sort bar is
	// gone (direct request), so mark the one in use and offer a way back.
	$active_type = isset( $_GET['yp_product_type'] ) ? sanitize_title( wp_unslash( $_GET['yp_product_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="yp-product-type-browse">
		<?php foreach ( $terms as $term ) :
			$url       = add_query_arg( 'yp_product_type', $term->slug, $base );
			$image     = $images[ $term->term_id ];
			$is_active = $active_type === $term->slug;
			?>
			<a class="yp-product-type-browse__card<?php echo $image ? ' yp-product-type-browse__card--photo' : ''; ?><?php echo $is_active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>"<?php echo $is_active ? ' aria-current="page"' : ''; ?>>
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
	<?php if ( $active_type ) : ?>
		<p class="yp-product-type-browse__clear"><a href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Show all designs', 'yeffoprint' ); ?></a></p>
	<?php endif; ?>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
