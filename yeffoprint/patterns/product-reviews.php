<?php
/**
 * Title: Product Reviews
 * Slug: yeffoprint/product-reviews
 * Categories: yeffoprint
 * Inserter: no
 *
 * Reviews on a label template or 3D print page: published reviews from
 * orders that included this design (YeffoPrint_Order_Reviews stores one
 * "template:<id>" / "print:<id>" tag per design in the order). With
 * none yet, falls back to the latest store-wide reviews so the page
 * still shows what customers think. Hidden when there are none at all.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'YeffoPrint_Order_Reviews' ) ) {
	return;
}

$current = get_post( (int) get_the_ID() );
if ( ! $current || ! in_array( $current->post_type, [ 'yp_template', 'yp_print' ], true ) ) {
	return;
}

$for     = ( 'yp_print' === $current->post_type ? 'print:' : 'template:' ) . $current->ID;
$reviews = YeffoPrint_Order_Reviews::published( $for, 6 );
$own     = (bool) $reviews;

if ( ! $own ) {
	$reviews = YeffoPrint_Order_Reviews::published( '', 3 );
}

if ( ! $reviews ) {
	return;
}

$summary = YeffoPrint_Order_Reviews::summary( $own ? $for : '' );
$heading = $own ? __( 'Customer Reviews', 'yeffoprint' ) : __( 'What Customers Say', 'yeffoprint' );
?>
<!-- wp:group {"tagName":"section","className":"yp-section yp-product-reviews","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section yp-product-reviews" id="reviews">

	<!-- wp:html -->
	<div class="yp-product-reviews__head">
		<h2 class="wp-block-heading"><?php echo esc_html( $heading ); ?></h2>
		<?php echo yeffoprint_render_review_summary( $summary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper. ?>
	</div>
	<?php echo yeffoprint_render_review_cards( $reviews ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper. ?>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
