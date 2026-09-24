<?php
/**
 * Shop Labels heading above the template grid (direct request: "there's
 * nothing that distinguishes where the filters end and the templates
 * begin"). Names the category picked from the Product Type tiles above
 * (?yp_product_type=, the same filter the tiles link to) or "All
 * designs", with the main query's result count.
 */

defined( 'ABSPATH' ) || exit;

global $wp_query;

$label = __( 'All designs', 'yeffoprint' );

if ( ! empty( $_GET['yp_product_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$term = get_term_by( 'slug', sanitize_title( wp_unslash( $_GET['yp_product_type'] ) ), 'yp_product_type' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $term && ! is_wp_error( $term ) ) {
		$label = $term->name;
	}
}

$count = $wp_query instanceof WP_Query ? (int) $wp_query->found_posts : 0;
?>
<div class="yp-gallery-head">
	<h2 class="yp-gallery-head__title"><?php echo esc_html( $label ); ?></h2>
	<span class="yp-gallery-head__count">
		<?php
		/* translators: %d: number of designs */
		echo esc_html( sprintf( _n( '%d design', '%d designs', $count, 'yeffoprint' ), $count ) );
		?>
	</span>
</div>
