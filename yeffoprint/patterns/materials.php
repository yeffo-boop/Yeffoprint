<?php
/**
 * Title: Materials
 * Slug: yeffoprint/materials
 * Categories: yeffoprint
 */

defined( 'ABSPATH' ) || exit;

// Real Material records now (V2: admin-uploadable swatch + hover "on
// the vial" photos, direct request), not a hardcoded list — this
// section always reflects whatever Materials actually exist/are
// published, the same source of truth the configurator's own material
// picker reads from (class-template-schema-controller.php).
$materials = get_posts( [
	'post_type'      => 'yp_material',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'orderby'        => 'menu_order title',
	'order'          => 'ASC',
] );
?>
<!-- wp:group {"tagName":"section","className":"yp-section yp-section--tint","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section yp-section--tint">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow">Materials</p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="wp-block-heading has-text-align-center"><?php echo count( $materials ) ? esc_html( sprintf( _n( '%d finish, every one print-tested', '%d finishes, every one print-tested', count( $materials ), 'yeffoprint' ), count( $materials ) ) ) : esc_html__( 'Finishes, every one print-tested', 'yeffoprint' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:html -->
	<?php if ( $materials ) : ?>
		<div class="yp-materials-grid">
			<?php foreach ( $materials as $material ) :
				$slug       = $material->post_name;
				$swatch_url = get_the_post_thumbnail_url( $material, 'medium' );
				$hover_id   = (int) get_post_meta( $material->ID, YeffoPrint_Commerce_Record_Meta::HOVER_IMAGE, true );
				$hover_url  = $hover_id ? wp_get_attachment_image_url( $hover_id, 'medium' ) : '';
				$blurb      = wp_strip_all_tags( $material->post_content );
				?>
				<div class="yp-material-swatch">
					<div class="yp-material-swatch__chip<?php echo $swatch_url ? '' : ' yp-material-swatch__chip--' . esc_attr( $slug ); ?>">
						<?php if ( $swatch_url ) : ?>
							<img class="yp-material-swatch__image yp-material-swatch__image--primary" src="<?php echo esc_url( $swatch_url ); ?>" alt="" />
							<?php if ( $hover_url ) : ?>
								<img class="yp-material-swatch__image yp-material-swatch__image--hover" src="<?php echo esc_url( $hover_url ); ?>" alt="" />
							<?php endif; ?>
						<?php endif; ?>
					</div>
					<p class="yp-material-swatch__name"><?php echo esc_html( get_the_title( $material ) ); ?></p>
					<?php if ( $blurb ) : ?>
						<p class="yp-material-swatch__blurb"><?php echo esc_html( $blurb ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<p class="has-text-align-center description">No materials published yet — add one from the YeffoDesign admin menu.</p>
	<?php endif; ?>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
