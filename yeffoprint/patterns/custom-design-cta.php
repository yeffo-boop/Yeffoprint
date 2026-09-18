<?php
/**
 * Title: Fully Custom Design (dark break)
 * Slug: yeffoprint/custom-design-cta
 * Categories: yeffoprint
 *
 * Dark two-column break: copy + CTA with a real product visual anchor.
 */

defined( 'ABSPATH' ) || exit;

$cta_image_url = '';
$cta_image_alt = __( 'Custom printed label', 'yeffoprint' );

if ( class_exists( 'YeffoPrint_Template_Meta' ) ) {
	$cta_ids = get_posts( [
		'post_type'      => 'yp_template',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'offset'         => 1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'meta_key'       => YeffoPrint_Template_Meta::VIAL_MOCKUP, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_compare'   => '>',
		'meta_value'     => '0', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		'fields'         => 'ids',
	] );

	if ( $cta_ids && function_exists( 'yeffoprint_core_get_template_card_data' ) ) {
		$card = yeffoprint_core_get_template_card_data( (int) $cta_ids[0] );
		if ( $card ) {
			$cta_image_url = (string) ( $card['vial_mockup_url'] ?: $card['artwork_url'] ?: '' );
			$cta_image_alt = $card['title'] ?: $cta_image_alt;
		}
	}
}
?>
<!-- wp:group {"tagName":"section","className":"yp-section yp-section--dark yp-custom-design-cta","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section yp-section--dark yp-custom-design-cta">

	<!-- wp:html -->
	<div class="yp-custom-design-cta__grid">
		<div class="yp-custom-design-cta__copy">
			<p class="yp-eyebrow"><?php esc_html_e( 'Fully Custom', 'yeffoprint' ); ?></p>
			<h2 class="yp-custom-design-cta__title"><?php esc_html_e( 'Not seeing quite the right design?', 'yeffoprint' ); ?></h2>
			<p><?php esc_html_e( 'Our team builds a one-off label from your brand, colors, and instructions — proofed and approved before anything prints.', 'yeffoprint' ); ?></p>
			<p class="yp-custom-design-cta__actions">
				<a class="wp-block-button__link" href="<?php echo esc_url( home_url( '/custom-design/' ) ); ?>"><?php esc_html_e( 'Create a Custom Label', 'yeffoprint' ); ?></a>
			</p>
		</div>
		<?php if ( $cta_image_url ) : ?>
			<div class="yp-custom-design-cta__visual">
				<img
					src="<?php echo esc_url( $cta_image_url ); ?>"
					alt="<?php echo esc_attr( $cta_image_alt ); ?>"
					loading="lazy"
					decoding="async"
				/>
			</div>
		<?php endif; ?>
	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
