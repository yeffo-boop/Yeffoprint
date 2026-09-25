<?php
/**
 * A 3D print's product page (direct request: a 3D Prints section where
 * the customer picks a color for each part of the print). Fully
 * server-rendered — title, price, photo, dots and every color option
 * are real HTML a crawler can read, and the color pickers are plain
 * radio buttons that work before any script runs. assets/js/print-
 * product.js only layers on the live bits: the picked color's name,
 * the dot's color badge, the running total, and Add to Cart.
 *
 * Each color choice's number matches the numbered dot on the photo,
 * placed by the admin in the 3D Prints editor (x/y as percentages).
 */

defined( 'ABSPATH' ) || exit;

$print_id = get_the_ID();
$print    = function_exists( 'yeffoprint_core_get_print_data' ) ? yeffoprint_core_get_print_data( (int) $print_id ) : null;

if ( ! $print ) {
	return;
}

$slots       = $print['slots'];
$sizes       = $print['sizes'];
$description = get_post_field( 'post_content', $print_id );
$has_extras  = false;
$start_total = $print['price'];

/**
 * The default pick for a slot: its admin-set default when that color is
 * in stock, else the first in-stock color, else nothing (the customer
 * has to choose, and Add to Cart says so).
 */
$pick_for = static function ( array $slot ): int {
	foreach ( $slot['colors'] as $color ) {
		if ( $color['id'] === $slot['default_id'] && $color['in_stock'] ) {
			return $color['id'];
		}
	}
	foreach ( $slot['colors'] as $color ) {
		if ( $color['in_stock'] ) {
			return $color['id'];
		}
	}
	return 0;
};

foreach ( $slots as $slot ) {
	$picked_id = $pick_for( $slot );
	foreach ( $slot['colors'] as $color ) {
		if ( $color['extra_charge'] > 0 ) {
			$has_extras = true;
		}
		if ( $color['id'] === $picked_id ) {
			$start_total += $color['extra_charge'];
		}
	}
}

$money = static function ( float $amount ): string {
	return '$' . number_format( $amount, 2 );
};

$archive_url = get_post_type_archive_link( 'yp_print' );
?>
<div class="yp-print" id="yp-print" data-yp-print-id="<?php echo (int) $print_id; ?>" data-yp-base-price="<?php echo esc_attr( (string) $print['price'] ); ?>">

	<nav class="yp-print__crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'yeffoprint' ); ?>">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'yeffoprint' ); ?></a>
		<span aria-hidden="true">/</span>
		<a href="<?php echo esc_url( $archive_url ); ?>"><?php esc_html_e( '3D Prints', 'yeffoprint' ); ?></a>
		<span aria-hidden="true">/</span>
		<span><?php echo esc_html( $print['title'] ); ?></span>
	</nav>

	<div class="yp-print__layout">

		<div class="yp-print__media">
			<div class="yp-print__photo">
				<?php if ( $print['image_url'] ) : ?>
					<img src="<?php echo esc_url( $print['image_url'] ); ?>" alt="<?php echo esc_attr( $print['title'] ); ?>" />
				<?php else : ?>
					<div class="yp-print__photo-empty" aria-hidden="true"></div>
				<?php endif; ?>

				<?php foreach ( $slots as $index => $slot ) : ?>
					<?php
					if ( null === $slot['x'] || null === $slot['y'] || ! $print['image_url'] ) {
						continue;
					}
					$picked = null;
					foreach ( $slot['colors'] as $color ) {
						if ( $color['id'] === $pick_for( $slot ) ) {
							$picked = $color;
						}
					}
					?>
					<span class="yp-print__dot" style="left:<?php echo esc_attr( (string) $slot['x'] ); ?>%;top:<?php echo esc_attr( (string) $slot['y'] ); ?>%" title="<?php echo esc_attr( $slot['name'] ); ?>" data-yp-dot="<?php echo (int) $index; ?>">
						<?php echo (int) $index + 1; ?>
						<i class="yp-print__dot-color<?php echo $picked && 'silk' === $picked['finish'] ? ' is-silk' : ''; ?>" style="background-color:<?php echo esc_attr( $picked ? $picked['hex'] : 'transparent' ); ?>"></i>
					</span>
				<?php endforeach; ?>
			</div>
			<?php if ( $slots && $print['image_url'] ) : ?>
				<p class="yp-print__caption"><?php esc_html_e( 'Numbered dots show where each color goes. They match the color steps.', 'yeffoprint' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="yp-print__details">
			<p class="yp-eyebrow"><?php esc_html_e( '3D Prints', 'yeffoprint' ); ?></p>
			<h1 class="yp-print__title"><?php echo esc_html( $print['title'] ); ?></h1>
			<p class="yp-print__price">
				<?php echo esc_html( $money( $print['price'] ) ); ?>
				<?php if ( $has_extras ) : ?>
					<small><?php esc_html_e( '+ color upgrades', 'yeffoprint' ); ?></small>
				<?php endif; ?>
			</p>

			<ul class="yp-print__bubbles">
				<li>
					<?php
					echo esc_html( $slots
						/* translators: %d: number of color choices */
						? sprintf( _n( '%d color choice', '%d color choices', count( $slots ), 'yeffoprint' ), count( $slots ) )
						: __( 'One color', 'yeffoprint' ) );
					?>
				</li>
				<?php if ( '' !== $print['ships_in'] ) : ?>
					<?php /* translators: %s: e.g. "3 to 5 days" */ ?>
					<li><?php echo esc_html( sprintf( __( 'Ships in %s', 'yeffoprint' ), $print['ships_in'] ) ); ?></li>
				<?php endif; ?>
				<li><?php esc_html_e( 'Printed to order', 'yeffoprint' ); ?></li>
			</ul>

			<?php if ( '' !== trim( wp_strip_all_tags( $description ) ) ) : ?>
				<div class="yp-print__description"><?php echo wp_kses_post( wpautop( $description ) ); ?></div>
			<?php endif; ?>

			<form class="yp-print__form" data-yp-print-form>

					<?php if ( $sizes ) : ?>
						<?php // No size starts picked: the wrong one won't fit, so the customer chooses on purpose. ?>
						<fieldset class="yp-print-sizes" data-yp-sizes>
							<legend class="yp-print__section-title"><?php esc_html_e( 'Choose your size', 'yeffoprint' ); ?></legend>
							<div class="yp-print-sizes__options">
								<?php foreach ( $sizes as $size ) : ?>
									<label class="yp-print-size">
										<input type="radio" name="size" value="<?php echo esc_attr( $size ); ?>"<?php checked( 1 === count( $sizes ) ); ?> />
										<span><?php echo esc_html( $size ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</fieldset>
					<?php endif; ?>

				<?php if ( $slots ) : ?>
					<p class="yp-print__section-title">
						<?php esc_html_e( 'Choose your colors', 'yeffoprint' ); ?>
					</p>

					<?php foreach ( $slots as $index => $slot ) : ?>
						<?php $picked_id = $pick_for( $slot ); ?>
						<fieldset class="yp-print-slot" data-yp-slot="<?php echo (int) $index; ?>">
							<legend class="yp-print-slot__head">
								<span class="yp-print-slot__num" aria-hidden="true"><?php echo (int) $index + 1; ?></span>
								<span class="yp-print-slot__label">
									<strong><?php echo esc_html( $slot['name'] ); ?></strong>
									<?php if ( '' !== $slot['hint'] ) : ?>
										<span><?php echo esc_html( $slot['hint'] ); ?></span>
									<?php endif; ?>
								</span>
								<span class="yp-print-slot__picked" data-yp-picked aria-live="polite"></span>
							</legend>
							<div class="yp-print-slot__swatches">
								<?php foreach ( $slot['colors'] as $color ) : ?>
									<label class="yp-print-swatch<?php echo $color['in_stock'] ? '' : ' is-out'; ?>" title="<?php echo esc_attr( $color['name'] . ( $color['in_stock'] ? '' : ' (out of stock)' ) ); ?>">
										<input
											type="radio"
											name="color_<?php echo (int) $index; ?>"
											value="<?php echo (int) $color['id']; ?>"
											data-name="<?php echo esc_attr( $color['name'] ); ?>"
											data-hex="<?php echo esc_attr( $color['hex'] ); ?>"
											data-finish="<?php echo esc_attr( $color['finish'] ); ?>"
											data-extra="<?php echo esc_attr( (string) $color['extra_charge'] ); ?>"
											<?php checked( $color['id'], $picked_id ); ?>
											<?php disabled( ! $color['in_stock'] ); ?>
										/>
										<span class="yp-print-swatch__dot<?php echo 'silk' === $color['finish'] ? ' is-silk' : ''; ?>" style="background-color:<?php echo esc_attr( $color['hex'] ); ?>"></span>
										<span class="screen-reader-text"><?php echo esc_html( $color['name'] . ( $color['in_stock'] ? '' : ' (out of stock)' ) ); ?></span>
										<?php if ( $color['extra_charge'] > 0 ) : ?>
											<span class="yp-print-swatch__extra">+<?php echo esc_html( $money( $color['extra_charge'] ) ); ?></span>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</div>
						</fieldset>
					<?php endforeach; ?>

					<p class="yp-print__note"><?php esc_html_e( 'Crossed-out colors are out of stock right now. Colors can look slightly different in person.', 'yeffoprint' ); ?></p>
				<?php endif; ?>

				<div class="yp-print__buy">
					<div class="yp-print__qty">
						<button type="button" data-yp-qty="-1" aria-label="<?php esc_attr_e( 'Decrease quantity', 'yeffoprint' ); ?>">&minus;</button>
						<input type="number" name="quantity" value="1" min="1" max="100" inputmode="numeric" aria-label="<?php esc_attr_e( 'Quantity', 'yeffoprint' ); ?>" data-yp-qty-input />
						<button type="button" data-yp-qty="1" aria-label="<?php esc_attr_e( 'Increase quantity', 'yeffoprint' ); ?>">+</button>
					</div>
					<button type="submit" class="wp-block-button__link yp-print__add" data-yp-add>
						<?php esc_html_e( 'Add to Cart', 'yeffoprint' ); ?> &middot; <span data-yp-total><?php echo esc_html( $money( $start_total ) ); ?></span>
					</button>
				</div>

				<p class="yp-print__status" role="status" aria-live="polite" data-yp-status></p>

				<?php if ( $slots || $sizes ) : ?>
					<p class="yp-print__summary" data-yp-summary></p>
				<?php endif; ?>
			</form>
		</div>
	</div>
</div>
