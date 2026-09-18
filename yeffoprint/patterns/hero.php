<?php
/**
 * Title: Hero
 * Slug: yeffoprint/hero
 * Categories: yeffoprint
 *
 * Brand-first lockup, one headline + sentence + CTAs, and a real vial
 * mockup plane with press-proof crop-mark overlay. No eyebrow pill or
 * stats strip in the first viewport.
 */

defined( 'ABSPATH' ) || exit;

$hero_vial_url   = '';
$hero_vial_alt   = __( 'Custom label on vial', 'yeffoprint' );
$hero_vial_link  = home_url( '/shop-labels/' );

if ( class_exists( 'YeffoPrint_Template_Meta' ) ) {
	$hero_ids = get_posts( [
		'post_type'      => 'yp_template',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'meta_key'       => YeffoPrint_Template_Meta::FEATURED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		'fields'         => 'ids',
	] );

	if ( ! $hero_ids ) {
		$hero_ids = get_posts( [
			'post_type'      => 'yp_template',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_key'       => YeffoPrint_Template_Meta::VIAL_MOCKUP, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_compare'   => '>',
			'meta_value'     => '0', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'         => 'ids',
		] );
	}

	if ( $hero_ids ) {
		$hero_id = (int) $hero_ids[0];
		$card    = function_exists( 'yeffoprint_core_get_template_card_data' )
			? yeffoprint_core_get_template_card_data( $hero_id )
			: null;
		if ( $card ) {
			$hero_vial_url  = (string) ( $card['vial_mockup_url'] ?: $card['artwork_url'] ?: '' );
			$hero_vial_alt  = $card['title'] ?: $hero_vial_alt;
			$hero_vial_link = $card['permalink'] ?: $hero_vial_link;
		}
	}
}
?>
<!-- wp:group {"tagName":"section","className":"yp-hero yp-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-hero yp-section">

	<!-- wp:group {"className":"yp-hero__grid","layout":{"type":"default"}} -->
	<div class="wp-block-group yp-hero__grid">

		<!-- wp:group {"className":"yp-hero__content","layout":{"type":"default"}} -->
		<div class="wp-block-group yp-hero__content">

			<!-- wp:group {"className":"yp-hero__eyebrow-accent"} -->
			<div class="wp-block-group yp-hero__eyebrow-accent"><span></span><span></span><span></span></div>
			<!-- /wp:group -->

			<!-- wp:html -->
			<p class="yp-hero__brand" aria-label="YeffoDesign">Yeffo<span class="yp-hero__brand-accent">Design</span></p>
			<!-- /wp:html -->

			<!-- wp:heading {"level":1,"fontSize":"x-large","className":"yp-hero__headline"} -->
			<h1 class="wp-block-heading has-x-large-font-size yp-hero__headline">Product labels, designed once, <span class="yp-hero__accent-word">printed</span> to spec.</h1>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontSize":"medium","className":"yp-hero__lede"} -->
			<p class="has-medium-font-size yp-hero__lede">Waterproof stock, exact color matching, and a proof you approve before anything hits press.</p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-accent"} -->
				<div class="wp-block-button is-style-accent"><a class="wp-block-button__link wp-element-button" href="/shop-labels/">Browse label templates</a></div>
				<!-- /wp:button -->

				<!-- wp:button {"className":"is-style-outline"} -->
				<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/custom-design/">Start a custom design</a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->

		</div>
		<!-- /wp:group -->

		<!-- wp:html -->
		<div class="yp-hero__visual">
			<?php if ( $hero_vial_url ) : ?>
				<a class="yp-hero__vial-plane" href="<?php echo esc_url( $hero_vial_link ); ?>">
					<img
						src="<?php echo esc_url( $hero_vial_url ); ?>"
						alt="<?php echo esc_attr( $hero_vial_alt ); ?>"
						loading="eager"
						decoding="async"
					/>
					<span class="yp-hero__proof-frame" aria-hidden="true">
						<span class="yp-proof__corner yp-proof__corner--tl"></span>
						<span class="yp-proof__corner yp-proof__corner--tr"></span>
						<span class="yp-proof__corner yp-proof__corner--bl"></span>
						<span class="yp-proof__corner yp-proof__corner--br"></span>
					</span>
					<span class="yp-hero__proof-caption">Press proof · registration</span>
				</a>
			<?php else : ?>
				<div class="yp-proof" aria-hidden="true">
					<span class="yp-proof__corner yp-proof__corner--tl"></span>
					<span class="yp-proof__corner yp-proof__corner--tr"></span>
					<span class="yp-proof__corner yp-proof__corner--bl"></span>
					<span class="yp-proof__corner yp-proof__corner--br"></span>
					<svg class="yp-proof__mark" viewBox="0 0 120 120" fill="none">
						<clipPath id="ypHeroMarkClip"><rect x="8" y="8" width="104" height="104" rx="16" /></clipPath>
						<g clip-path="url(#ypHeroMarkClip)">
							<rect class="yp-proof__bar yp-proof__bar--c" x="8" y="8" width="34.7" height="104" fill="var(--wp--preset--color--cyan)" />
							<rect class="yp-proof__bar yp-proof__bar--m" x="42.7" y="8" width="34.7" height="104" fill="var(--wp--preset--color--magenta)" />
							<rect class="yp-proof__bar yp-proof__bar--y" x="77.3" y="8" width="34.7" height="104" fill="var(--wp--preset--color--yellow)" />
						</g>
					</svg>
					<span class="yp-proof__caption">Press proof &middot; registration mark</span>
				</div>
			<?php endif; ?>
		</div>
		<!-- /wp:html -->

	</div>
	<!-- /wp:group -->

</section>
<!-- /wp:group -->
