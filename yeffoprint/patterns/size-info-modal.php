<?php
/**
 * Title: Size Info Modal
 * Slug: yeffoprint/size-info-modal
 * Categories: yeffoprint
 * Inserter: no
 *
 * Direct request: an info button next to the label configurator's Size
 * section (templates/single-yp_template.html) that opens a modal
 * showing this design's compatible sizes side by side, each with a
 * rectangle drawn to its actual print dimensions — so a customer can
 * see at a glance how much bigger a 3"×2" label really is next to a
 * 2"×1", not just compare two names in a list.
 *
 * Scoped to this one Template's own compatible sizes (Template.
 * compatible_sizes, the same list class-template-schema-controller.php
 * already resolves for the picker itself) rather than every Size the
 * store has ever defined — unlike the Material info modal, there's no
 * pre-existing "general reference" content to reuse here, so this
 * shows exactly what's actually selectable on this page.
 *
 * `<!-- wp:pattern -->` block templates don't execute PHP themselves,
 * but a referenced pattern file does, with the main query's post
 * already resolved — get_the_ID()/get_post() here work the same way
 * functions.php's own wp_enqueue_scripts hook already relies on them
 * working on this exact template (is_singular('yp_template')).
 *
 * All rectangles share one scale factor (largest side across every
 * compatible size maps to a fixed pixel box) so the drawings stay
 * proportional to each other, not just internally consistent — a
 * size drawn on its own scale would look identical to any other no
 * matter how different their real dimensions are.
 */

defined( 'ABSPATH' ) || exit;

$current_post = get_post( get_the_ID() );

if ( ! $current_post || 'yp_template' !== $current_post->post_type ) {
	return;
}

$size_ids = array_map( 'absint', (array) get_post_meta( $current_post->ID, YeffoPrint_Template_Meta::COMPATIBLE_SIZES, true ) );
$sizes    = [];

foreach ( $size_ids as $size_id ) {
	$size_post = get_post( $size_id );
	if ( ! $size_post || 'publish' !== $size_post->post_status ) {
		continue;
	}

	$sizes[] = [
		'name'      => $size_post->post_title,
		'width_mm'  => (float) get_post_meta( $size_id, YeffoPrint_Commerce_Record_Meta::PRINT_WIDTH_MM, true ),
		'height_mm' => (float) get_post_meta( $size_id, YeffoPrint_Commerce_Record_Meta::PRINT_HEIGHT_MM, true ),
	];
}

if ( ! $sizes ) {
	return;
}

$canvas_px = 120;
$max_mm    = 0;
foreach ( $sizes as $size ) {
	$max_mm = max( $max_mm, $size['width_mm'], $size['height_mm'] );
}
$scale = $max_mm > 0 ? ( $canvas_px / $max_mm ) : 0;
$mm_per_in = 25.4;
?>
<div id="yp-size-info-modal" class="yp-drawer yp-drawer--center" aria-hidden="true">
	<div class="yp-drawer__backdrop"></div>
	<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Label Sizes', 'yeffoprint' ); ?>">
		<div class="yp-drawer__header">
			<span><?php esc_html_e( 'Label Sizes', 'yeffoprint' ); ?></span>
			<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="<?php esc_attr_e( 'Close', 'yeffoprint' ); ?>">
				<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
					<line x1="2" y1="2" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
					<line x1="14" y1="2" x2="2" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
				</svg>
			</button>
		</div>
		<div class="yp-drawer__body">
			<p class="yp-size-info__intro"><?php esc_html_e( 'Drawn to scale, so you can compare how much print area each size actually gives you.', 'yeffoprint' ); ?></p>
			<div class="yp-size-info-grid">
				<?php foreach ( $sizes as $size ) : ?>
					<div class="yp-size-info-card">
						<?php if ( $size['width_mm'] > 0 && $size['height_mm'] > 0 ) :
							$rect_w = round( $size['width_mm'] * $scale, 1 );
							$rect_h = round( $size['height_mm'] * $scale, 1 );
							$rect_x = round( ( $canvas_px - $rect_w ) / 2, 1 );
							$rect_y = round( ( $canvas_px - $rect_h ) / 2, 1 );
							$width_in  = round( $size['width_mm'] / $mm_per_in, 1 );
							$height_in = round( $size['height_mm'] / $mm_per_in, 1 );
							?>
							<svg class="yp-size-info-card__canvas" width="<?php echo esc_attr( $canvas_px ); ?>" height="<?php echo esc_attr( $canvas_px ); ?>" viewBox="0 0 <?php echo esc_attr( $canvas_px ); ?> <?php echo esc_attr( $canvas_px ); ?>" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: width in mm, 2: height in mm */ __( 'Rectangle showing a %1$smm by %2$smm label', 'yeffoprint' ), $size['width_mm'], $size['height_mm'] ) ); ?>">
								<rect x="0" y="0" width="<?php echo esc_attr( $canvas_px ); ?>" height="<?php echo esc_attr( $canvas_px ); ?>" class="yp-size-info-card__bounds" />
								<rect x="<?php echo esc_attr( $rect_x ); ?>" y="<?php echo esc_attr( $rect_y ); ?>" width="<?php echo esc_attr( $rect_w ); ?>" height="<?php echo esc_attr( $rect_h ); ?>" class="yp-size-info-card__rect" />
							</svg>
							<p class="yp-size-info-card__dims">
								<?php echo esc_html( rtrim( rtrim( number_format( $size['width_mm'], 1, '.', '' ), '0' ), '.' ) ); ?>&nbsp;&times;&nbsp;<?php echo esc_html( rtrim( rtrim( number_format( $size['height_mm'], 1, '.', '' ), '0' ), '.' ) ); ?>&nbsp;mm
								<span class="yp-size-info-card__dims-in">(<?php echo esc_html( $width_in ); ?>&Prime;&nbsp;&times;&nbsp;<?php echo esc_html( $height_in ); ?>&Prime;)</span>
							</p>
						<?php else : ?>
							<div class="yp-size-info-card__canvas yp-size-info-card__canvas--unset" aria-hidden="true"></div>
							<p class="yp-size-info-card__dims"><?php esc_html_e( 'Dimensions not set yet', 'yeffoprint' ); ?></p>
						<?php endif; ?>
						<p class="yp-size-info-card__name"><?php echo esc_html( $size['name'] ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</div>
