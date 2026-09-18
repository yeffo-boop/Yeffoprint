<?php
/**
 * Title: Customer Work / Inspiration
 * Slug: yeffoprint/customer-work
 * Categories: yeffoprint
 *
 * Published Templates with vial mockups, captioned like Featured cards.
 * Hidden when none are available.
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
	$card = function_exists( 'yeffoprint_core_get_template_card_data' )
		? yeffoprint_core_get_template_card_data( (int) $template_id )
		: null;
	$url  = $card ? (string) ( $card['vial_mockup_url'] ?: $card['artwork_url'] ?: '' ) : '';
	if ( ! $url ) {
		continue;
	}
	$tiles[] = [
		'url'       => $url,
		'title'     => $card['title'] ?: get_the_title( $template_id ),
		'permalink' => $card['permalink'] ?: get_permalink( $template_id ),
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
			<a class="yp-customer-tile yp-customer-tile--captioned" href="<?php echo esc_url( $tile['permalink'] ); ?>">
				<span class="yp-customer-tile__media">
					<img
						src="<?php echo esc_url( $tile['url'] ); ?>"
						alt="<?php echo esc_attr( $tile['title'] ); ?>"
						loading="lazy"
						decoding="async"
					/>
				</span>
				<span class="yp-customer-tile__body">
					<strong class="yp-customer-tile__title"><?php echo esc_html( $tile['title'] ); ?></strong>
					<span class="yp-customer-tile__cta"><?php esc_html_e( 'Customize', 'yeffoprint' ); ?> →</span>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
