<?php
/**
 * Title: Sticker Size & Shape Guide Modal
 * Slug: yeffoprint/sticker-size-info-modal
 * Categories: yeffoprint
 * Inserter: no
 *
 * MOCK — parallel to patterns/size-info-modal.php for labels. Shows
 * sticker shapes + published sticker size tiers in a shared drawer.
 */

defined( 'ABSPATH' ) || exit;

$shapes = class_exists( 'YeffoPrint_Sticker_Pricing' ) ? YeffoPrint_Sticker_Pricing::SHAPES : [];
$sizes  = get_posts( [
	'post_type'      => 'yp_sticker_size',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'orderby'        => 'menu_order title',
	'order'          => 'ASC',
] );
?>
<div id="yp-sticker-size-info-modal" class="yp-drawer yp-drawer--center" aria-hidden="true">
	<div class="yp-drawer__backdrop"></div>
	<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="yp-sticker-size-info-heading">
		<div class="yp-drawer__header">
			<span id="yp-sticker-size-info-heading"><?php esc_html_e( 'Sticker sizes & shapes', 'yeffoprint' ); ?></span>
			<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="<?php esc_attr_e( 'Close', 'yeffoprint' ); ?>">
				<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
					<line x1="2" y1="2" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
					<line x1="14" y1="2" x2="2" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
				</svg>
			</button>
		</div>
		<div class="yp-drawer__body">
			<p class="yp-mock-banner"><?php esc_html_e( 'Mock guide — dimensions and shape silhouettes for review before we wire final art.', 'yeffoprint' ); ?></p>

			<?php if ( $shapes ) : ?>
				<h3 class="yp-sticker-guide__subhead"><?php esc_html_e( 'Shapes', 'yeffoprint' ); ?></h3>
				<div class="yp-sticker-guide__shapes">
					<?php foreach ( $shapes as $slug => $label ) : ?>
						<div class="yp-sticker-guide__shape">
							<span class="yp-sticker-guide__shape-icon yp-sticker-guide__shape-icon--<?php echo esc_attr( $slug ); ?>" aria-hidden="true"></span>
							<span><?php echo esc_html( $label ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $sizes ) : ?>
				<h3 class="yp-sticker-guide__subhead"><?php esc_html_e( 'Size tiers', 'yeffoprint' ); ?></h3>
				<ul class="yp-sticker-guide__sizes">
					<?php foreach ( $sizes as $size ) :
						$is_custom = (bool) get_post_meta( $size->ID, YeffoPrint_Sticker_Size_Meta::IS_CUSTOM, true );
						$w = (float) get_post_meta( $size->ID, YeffoPrint_Sticker_Size_Meta::WIDTH_IN, true );
						$h = (float) get_post_meta( $size->ID, YeffoPrint_Sticker_Size_Meta::HEIGHT_IN, true );
						?>
						<li>
							<strong><?php echo esc_html( get_the_title( $size ) ); ?></strong>
							<span>
								<?php
								if ( $is_custom ) {
									esc_html_e( 'Enter your own width × height', 'yeffoprint' );
								} else {
									printf(
										/* translators: 1: width inches, 2: height inches */
										esc_html__( '%1$s × %2$s in', 'yeffoprint' ),
										esc_html( rtrim( rtrim( number_format( $w, 2, '.', '' ), '0' ), '.' ) ),
										esc_html( rtrim( rtrim( number_format( $h, 2, '.', '' ), '0' ), '.' ) )
									);
								}
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</div>
