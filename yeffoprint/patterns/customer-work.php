<?php
/**
 * Title: Customer Work / Inspiration
 * Slug: yeffoprint/customer-work
 * Categories: yeffoprint
 *
 * Shows published Templates that have a vial mockup (real product
 * imagery). Hidden entirely when none are available — no placeholder
 * tiles or "coming soon" empty states on the live homepage.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'YeffoPrint_Template_Meta' ) ) {
	return;
}

$inspiration_ids = get_posts( [
	'post_type'      => 'yp_template',
	'post_status'    => 'publish',
	'posts_per_page' => 4,
	'orderby'        => 'date',
	'order'          => 'DESC',
	'meta_key'       => YeffoPrint_Template_Meta::VIAL_MOCKUP, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, admin-managed table.
	'meta_compare'   => '>',
	'meta_value'     => '0', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- small, admin-managed table.
	'fields'         => 'ids',
] );

if ( ! $inspiration_ids ) {
	return;
}

$tiles = [];
foreach ( $inspiration_ids as $template_id ) {
	$vial_id = (int) get_post_meta( $template_id, YeffoPrint_Template_Meta::VIAL_MOCKUP, true );
	$url     = $vial_id ? (string) wp_get_attachment_image_url( $vial_id, 'medium_large' ) : '';
	if ( ! $url ) {
		continue;
	}
	$tiles[] = [
		'url'       => $url,
		'title'     => get_the_title( $template_id ),
		'permalink' => get_permalink( $template_id ),
	];
}

if ( ! $tiles ) {
	return;
}
?>
<!-- wp:group {"tagName":"section","className":"yp-section yp-section--tint","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section yp-section--tint">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow">Inspiration</p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="wp-block-heading has-text-align-center">Label Inspiration</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center"} -->
	<p class="has-text-align-center">Real designs from the gallery — open any one to customize it for your brand.</p>
	<!-- /wp:paragraph -->

	<!-- wp:html -->
	<div class="yp-card-grid">
		<?php foreach ( $tiles as $tile ) : ?>
			<a class="yp-card yp-card__media yp-customer-tile yp-customer-tile--photo" href="<?php echo esc_url( $tile['permalink'] ); ?>">
				<img
					src="<?php echo esc_url( $tile['url'] ); ?>"
					alt="<?php echo esc_attr( $tile['title'] ); ?>"
					loading="lazy"
					decoding="async"
				/>
			</a>
		<?php endforeach; ?>
	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
