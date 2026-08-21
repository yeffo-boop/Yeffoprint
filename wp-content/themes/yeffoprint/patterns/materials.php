<?php
/**
 * Title: Materials
 * Slug: yeffoprint/materials
 * Categories: yeffoprint
 */

defined( 'ABSPATH' ) || exit;

$materials = [
	__( 'Glossy White', 'yeffoprint' ),
	__( 'Matte White', 'yeffoprint' ),
	__( 'Holographic', 'yeffoprint' ),
	__( 'Clear', 'yeffoprint' ),
	__( 'Metallic', 'yeffoprint' ),
];
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section">

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
				<div class="yp-material-swatch__chip"></div>
				<p><?php echo esc_html( $material ); ?></p>
			</div>
		<?php endforeach; ?>
	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
