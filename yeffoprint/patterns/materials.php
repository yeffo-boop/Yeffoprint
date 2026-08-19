<?php
/**
 * Title: Materials
 * Slug: yeffoprint/materials
 * Categories: yeffoprint
 */

defined( 'ABSPATH' ) || exit;

$materials = [
	[
		'slug'  => 'glossy-white',
		'name'  => __( 'Glossy White', 'yeffoprint' ),
		'blurb' => __( 'Bright, reflective finish that makes color pop.', 'yeffoprint' ),
	],
	[
		'slug'  => 'matte-white',
		'name'  => __( 'Matte White', 'yeffoprint' ),
		'blurb' => __( 'Soft, no-glare finish for a premium, understated look.', 'yeffoprint' ),
	],
	[
		'slug'  => 'holographic',
		'name'  => __( 'Holographic', 'yeffoprint' ),
		'blurb' => __( 'Rainbow shimmer that shifts with the light.', 'yeffoprint' ),
	],
	[
		'slug'  => 'clear',
		'name'  => __( 'Clear', 'yeffoprint' ),
		'blurb' => __( 'No-label look — print shows straight through.', 'yeffoprint' ),
	],
	[
		'slug'  => 'metallic',
		'name'  => __( 'Metallic', 'yeffoprint' ),
		'blurb' => __( 'Brushed-silver shine for a bold, industrial finish.', 'yeffoprint' ),
	],
];
?>
<!-- wp:group {"tagName":"section","className":"yp-section yp-section--tint","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section yp-section--tint">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow">Materials</p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="wp-block-heading has-text-align-center">Five finishes, every one print-tested</h2>
	<!-- /wp:heading -->

	<!-- wp:html -->
	<div class="yp-materials-grid">
		<?php foreach ( $materials as $material ) : ?>
			<div class="yp-material-swatch">
				<div class="yp-material-swatch__chip yp-material-swatch__chip--<?php echo esc_attr( $material['slug'] ); ?>"></div>
				<p class="yp-material-swatch__name"><?php echo esc_html( $material['name'] ); ?></p>
				<p class="yp-material-swatch__blurb"><?php echo esc_html( $material['blurb'] ); ?></p>
			</div>
		<?php endforeach; ?>
	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
