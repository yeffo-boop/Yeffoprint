<?php
/**
 * A 3D print's product page (direct request: a 3D Prints section where
 * the customer picks a color for each part of the print). Fully
 * server-rendered — title, price, photo, dots and every filament option
 * are real HTML a crawler can read, and the filament pickers are plain
 * radio buttons inside <details> that work before any script runs.
 * assets/js/print-product.js only layers on the live bits: the picked
 * filament's row, search and group filters, the bigger photo on hover
 * or press-and-hold, the dot's badge, the running total, and Add to Cart.
 *
 * Filament picker (direct request: "change that to a filament selector",
 * then "the list can get kind of long ... I don't want to keep the user
 * endlessly scrolling"): each part shows its picked filament as one row;
 * Change opens small photo cards in two columns inside a panel that
 * scrolls on its own, grouped Solid / Matte / Silk / Specialty, with a
 * search box and group chips once the list is long enough to need them.
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

$slots        = $print['slots'];
$sizes        = $print['size_options'];
$addons       = $print['addons'];
$description  = get_post_field( 'post_content', $print_id );
$has_extras   = false;
$size_prices  = array_unique( array_column( $sizes, 'price' ) );
$varied_sizes = count( $size_prices ) > 1;
// One size is picked up front only when it's the only size; otherwise
// the total starts at the cheapest size, same as the "From" price.
$start_total  = $sizes ? ( 1 === count( $sizes ) ? $sizes[0]['price'] : $print['from_price'] ) : $print['price'];
$area         = $addons['area'];

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

$groups = class_exists( 'YeffoPrint_Print_Meta' ) ? YeffoPrint_Print_Meta::FINISHES : [ 'solid' => 'Solid', 'matte' => 'Matte', 'silk' => 'Silk', 'specialty' => 'Specialty' ];

/** A filament's photo zoomed into its focus point, or its hex swatch. */
$filament_tile = static function ( array $color, string $class = 'yp-fil__tile' ): string {
	$html = '<span class="' . esc_attr( $class ) . ( ! $color['image_url'] && 'silk' === $color['finish'] ? ' is-silk' : '' ) . '" style="background-color:' . esc_attr( $color['hex'] ) . '">';
	if ( $color['image_url'] ) {
		$focus  = $color['focus'];
		$origin = $focus['x'] . '% ' . $focus['y'] . '%';
		$html  .= '<img src="' . esc_url( $color['image_url'] ) . '" alt="" loading="lazy" decoding="async" style="' . esc_attr( 'object-position:' . $origin . ';transform-origin:' . $origin . ';transform:scale(' . ( $focus['zoom'] / 100 ) . ')' ) . '" />';
	}
	return $html . '</span>';
};

/** Search / filter / pinned-group chrome only once a list is long enough to need it. */
$picker_tools_from = 8;

/**
 * One filament picker: the picked filament as a row (the <summary>),
 * and every offered filament as a small photo card grouped by type.
 * $picked_id 0 means nothing starts picked (lid text / image color),
 * so the panel starts open with "Pick a filament".
 */
$filament_picker = static function ( string $name, array $colors, int $picked_id ) use ( $groups, $filament_tile, $money, $picker_tools_from ): void {
	$picked  = null;
	$grouped = [];
	foreach ( $colors as $color ) {
		if ( $color['id'] === $picked_id ) {
			$picked = $color;
		}
		$grouped[ isset( $groups[ $color['finish'] ] ) ? $color['finish'] : 'solid' ][] = $color;
	}
	$grouped    = array_filter( array_replace( array_fill_keys( array_keys( $groups ), [] ), $grouped ) );
	$with_tools = count( $colors ) >= $picker_tools_from;
	?>
	<details class="yp-fil-picker<?php echo $with_tools ? ' has-tools' : ''; ?>" data-yp-fil-picker<?php echo $picked ? '' : ' open'; ?>>
		<summary class="yp-fil-picker__chosen" data-yp-fil-chosen>
			<?php if ( $picked ) : ?>
				<?php echo $filament_tile( $picked ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
				<span class="yp-fil__text">
					<b><?php echo esc_html( $picked['name'] ); ?></b>
					<small><?php echo esc_html( implode( ' · ', array_filter( [ $picked['brand'], $picked['extra_charge'] > 0 ? '+' . $money( $picked['extra_charge'] ) : '' ] ) ) ); ?></small>
				</span>
			<?php else : ?>
				<span class="yp-fil__tile is-empty" aria-hidden="true"></span>
				<span class="yp-fil__text"><b class="is-missing"><?php esc_html_e( 'Pick a filament', 'yeffoprint' ); ?></b></span>
			<?php endif; ?>
			<span class="yp-fil-picker__toggle" aria-hidden="true">
				<span class="yp-fil-picker__open-label"><?php esc_html_e( 'Change', 'yeffoprint' ); ?></span>
				<span class="yp-fil-picker__close-label"><?php esc_html_e( 'Close', 'yeffoprint' ); ?></span>
			</span>
		</summary>
		<div class="yp-fil-picker__panel">
			<?php if ( $with_tools ) : ?>
				<div class="yp-fil-picker__tools" data-yp-fil-tools hidden>
					<label class="yp-fil-picker__search">
						<span class="screen-reader-text"><?php esc_html_e( 'Search filaments', 'yeffoprint' ); ?></span>
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
						<input type="search" placeholder="<?php esc_attr_e( 'Search blue, silk, Bambu…', 'yeffoprint' ); ?>" autocomplete="off" data-yp-fil-search />
					</label>
					<?php if ( count( $grouped ) > 1 ) : ?>
						<div class="yp-fil-picker__chips" role="group" aria-label="<?php esc_attr_e( 'Filament type', 'yeffoprint' ); ?>">
							<button type="button" class="is-on" aria-pressed="true" data-yp-fil-chip=""><?php esc_html_e( 'All', 'yeffoprint' ); ?> <em><?php echo (int) count( $colors ); ?></em></button>
							<?php foreach ( $grouped as $key => $group_colors ) : ?>
								<button type="button" aria-pressed="false" data-yp-fil-chip="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $groups[ $key ] ); ?> <em><?php echo (int) count( $group_colors ); ?></em></button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div class="yp-fil-picker__list" data-yp-fil-list>
				<?php foreach ( $grouped as $key => $group_colors ) : ?>
					<div class="yp-fil-picker__group" data-yp-fil-group="<?php echo esc_attr( $key ); ?>">
						<?php if ( count( $grouped ) > 1 ) : ?>
							<p class="yp-fil-picker__group-title"><?php echo esc_html( $groups[ $key ] ); ?></p>
						<?php endif; ?>
						<div class="yp-fil-picker__grid">
							<?php foreach ( $group_colors as $color ) : ?>
								<?php $out = ! $color['in_stock']; ?>
								<label class="yp-fil<?php echo $out ? ' is-out' : ''; ?>" data-yp-fil data-search="<?php echo esc_attr( strtolower( implode( ' ', [ $color['name'], $color['brand'], $color['line'], $groups[ $key ] ] ) ) ); ?>">
									<input
										type="radio"
										class="screen-reader-text"
										name="<?php echo esc_attr( $name ); ?>"
										value="<?php echo (int) $color['id']; ?>"
										data-name="<?php echo esc_attr( $color['name'] ); ?>"
										data-brand="<?php echo esc_attr( $color['brand'] ); ?>"
										data-line="<?php echo esc_attr( $color['line'] ); ?>"
										data-hex="<?php echo esc_attr( $color['hex'] ); ?>"
										data-finish="<?php echo esc_attr( $color['finish'] ); ?>"
										data-image="<?php echo esc_attr( $color['image_url'] ); ?>"
										data-extra="<?php echo esc_attr( (string) $color['extra_charge'] ); ?>"
										<?php checked( $color['id'], $picked_id ); ?>
										<?php disabled( $out ); ?>
									/>
									<?php echo $filament_tile( $color ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
									<span class="yp-fil__text">
										<b><?php echo esc_html( $color['name'] ); ?></b>
										<small><?php echo esc_html( $out ? __( 'Out of stock', 'yeffoprint' ) : ( '' !== $color['brand'] ? $color['brand'] : $color['line'] ) ); ?></small>
									</span>
									<?php if ( $color['extra_charge'] > 0 ) : ?>
										<span class="yp-fil__extra">+<?php echo esc_html( $money( $color['extra_charge'] ) ); ?></span>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
				<p class="yp-fil-picker__empty" data-yp-fil-empty hidden><?php esc_html_e( 'No filaments match. Try another word.', 'yeffoprint' ); ?></p>
			</div>
		</div>
	</details>
	<?php
};

/**
 * A lid add-on's filament (text or image). Nothing starts picked: text
 * in the lid's own color would vanish, so the customer chooses on purpose.
 */
$addon_swatches = static function ( string $name, string $label ) use ( $print, $filament_picker ): void {
	?>
	<div class="yp-print-addon__colors" data-yp-addon-colors>
		<p class="yp-print-addon__colors-head">
			<strong><?php echo esc_html( $label ); ?></strong>
		</p>
		<?php $filament_picker( $name, $print['addon_colors'], 0 ); ?>
	</div>
	<?php
};
?>
<div class="yp-print" id="yp-print" data-yp-print-id="<?php echo (int) $print_id; ?>" data-yp-base-price="<?php echo esc_attr( (string) $print['price'] ); ?>" data-yp-from-price="<?php echo esc_attr( (string) $print['from_price'] ); ?>">

	<nav class="yp-print__crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'yeffoprint' ); ?>">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'yeffoprint' ); ?></a>
		<span aria-hidden="true">/</span>
		<a href="<?php echo esc_url( $archive_url ); ?>"><?php esc_html_e( '3D Prints', 'yeffoprint' ); ?></a>
		<span aria-hidden="true">/</span>
		<span><?php echo esc_html( $print['title'] ); ?></span>
	</nav>

	<div class="yp-print__layout">

		<div class="yp-print__media">
			<?php
			// A size's own photo replaces the main one while that size is
			// picked (print-product.js). The color dots are placed on the
			// main photo, so they hide while a size photo shows.
			$has_size_images = (bool) array_filter( array_column( $sizes, 'image_url' ) );
			$first_image     = $print['image_url'];
			if ( ! $first_image && 1 === count( $sizes ) ) {
				$first_image = $sizes[0]['image_url'];
			}
			?>
			<div class="yp-print__photo<?php echo $first_image !== $print['image_url'] ? ' is-size-photo' : ''; ?>" data-yp-photo data-yp-main-image="<?php echo esc_attr( $print['image_url'] ); ?>">
				<?php if ( $first_image || $has_size_images ) : ?>
					<img src="<?php echo esc_url( $first_image ); ?>" alt="<?php echo esc_attr( $print['title'] ); ?>"<?php echo $first_image ? '' : ' hidden'; ?> data-yp-photo-img />
				<?php endif; ?>
				<?php if ( ! $first_image ) : ?>
					<div class="yp-print__photo-empty" aria-hidden="true" data-yp-photo-empty></div>
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
						<i class="yp-print__dot-color<?php echo $picked && ! $picked['image_url'] && 'silk' === $picked['finish'] ? ' is-silk' : ''; ?>" style="background-color:<?php echo esc_attr( $picked ? $picked['hex'] : 'transparent' ); ?><?php echo $picked && $picked['image_url'] ? esc_attr( ';background-image:url(' . esc_url( $picked['image_url'] ) . ');background-size:' . $picked['focus']['zoom'] . '%;background-position:' . $picked['focus']['x'] . '% ' . $picked['focus']['y'] . '%' ) : ''; ?>"></i>
					</span>
				<?php endforeach; ?>
			</div>
			<?php if ( $slots && $print['image_url'] ) : ?>
				<p class="yp-print__caption"><?php esc_html_e( 'Numbered dots show which part each filament is for.', 'yeffoprint' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="yp-print__details">
			<p class="yp-eyebrow"><?php esc_html_e( '3D Prints', 'yeffoprint' ); ?></p>
			<h1 class="yp-print__title"><?php echo esc_html( $print['title'] ); ?></h1>
			<?php echo function_exists( 'yeffoprint_render_rating_jump' ) ? yeffoprint_render_rating_jump( 'print:' . (int) get_the_ID() ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper. ?>
			<p class="yp-print__price">
				<?php if ( $varied_sizes ) : ?>
					<?php /* translators: %s: lowest price, e.g. "$18.00" */ ?>
					<?php echo esc_html( sprintf( __( 'From %s', 'yeffoprint' ), $money( $print['from_price'] ) ) ); ?>
				<?php else : ?>
					<?php echo esc_html( $money( $sizes ? $sizes[0]['price'] : $print['price'] ) ); ?>
				<?php endif; ?>
				<?php if ( $has_extras ) : ?>
					<small><?php esc_html_e( '+ filament upgrades', 'yeffoprint' ); ?></small>
				<?php endif; ?>
			</p>

			<ul class="yp-print__bubbles">
				<li>
					<?php
					echo esc_html( $slots
						/* translators: %d: number of filament choices */
						? sprintf( _n( '%d filament choice', '%d filament choices', count( $slots ), 'yeffoprint' ), count( $slots ) )
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
							<p class="yp-print-sizes__error" data-yp-size-error role="alert" hidden><?php esc_html_e( 'Pick your size to add this to your cart.', 'yeffoprint' ); ?></p>
							<div class="yp-print-sizes__options">
								<?php foreach ( $sizes as $size ) : ?>
									<label class="yp-print-size">
										<input type="radio" name="size" value="<?php echo esc_attr( $size['name'] ); ?>" data-price="<?php echo esc_attr( (string) $size['price'] ); ?>" data-image="<?php echo esc_attr( $size['image_url'] ); ?>"<?php checked( 1 === count( $sizes ) ); ?> />
										<span>
											<?php echo esc_html( $size['name'] ); ?>
											<?php if ( $varied_sizes ) : ?>
												<small><?php echo esc_html( $money( $size['price'] ) ); ?></small>
											<?php endif; ?>
										</span>
									</label>
								<?php endforeach; ?>
							</div>
						</fieldset>
					<?php endif; ?>

				<?php if ( $slots ) : ?>
					<p class="yp-print__section-title">
						<?php esc_html_e( 'Choose your filament', 'yeffoprint' ); ?>
					</p>

					<?php foreach ( $slots as $index => $slot ) : ?>
						<fieldset class="yp-print-slot" data-yp-slot="<?php echo (int) $index; ?>">
							<legend class="yp-print-slot__head">
								<span class="yp-print-slot__num" aria-hidden="true"><?php echo (int) $index + 1; ?></span>
								<span class="yp-print-slot__label">
									<strong><?php echo esc_html( $slot['name'] ); ?></strong>
									<?php if ( '' !== $slot['hint'] ) : ?>
										<span><?php echo esc_html( $slot['hint'] ); ?></span>
									<?php endif; ?>
								</span>
							</legend>
							<?php $filament_picker( 'color_' . $index, $slot['colors'], $pick_for( $slot ) ); ?>
						</fieldset>
					<?php endforeach; ?>

					<p class="yp-print__note"><?php esc_html_e( 'Photos come from each filament brand. Colors can look slightly different in person.', 'yeffoprint' ); ?></p>
				<?php endif; ?>

				<?php if ( $addons['text'] || $addons['image'] ) : ?>
					<?php // Both off until ticked, so the upcharge is always the customer's own choice. ?>
					<div class="yp-print-addons" data-yp-addons>
						<p class="yp-print__section-title">
							<?php /* translators: %s: where on the print, e.g. "lid" */ ?>
							<?php echo esc_html( sprintf( __( 'Personalize the %s', 'yeffoprint' ), $area ) ); ?>
							<span class="yp-print-addons__optional"><?php esc_html_e( 'Optional', 'yeffoprint' ); ?></span>
						</p>

						<?php if ( $addons['text'] ) : ?>
							<div class="yp-print-addon" data-yp-addon="text">
								<label class="yp-print-addon__toggle">
									<input type="checkbox" data-yp-addon-toggle data-price="<?php echo esc_attr( (string) $addons['text_price'] ); ?>" />
									<span>
										<?php /* translators: %s: where on the print, e.g. "lid" */ ?>
										<strong><?php echo esc_html( sprintf( __( 'Add text to the %s', 'yeffoprint' ), $area ) ); ?></strong>
										<?php if ( $addons['text_price'] > 0 ) : ?>
											<em>+<?php echo esc_html( $money( $addons['text_price'] ) ); ?></em>
										<?php endif; ?>
									</span>
								</label>
								<div class="yp-print-addon__body" data-yp-addon-body hidden>
									<label class="screen-reader-text" for="yp-print-text"><?php esc_html_e( 'Your text', 'yeffoprint' ); ?></label>
									<input type="text" id="yp-print-text" name="text" maxlength="<?php echo (int) $addons['text_max']; ?>" placeholder="<?php esc_attr_e( 'e.g. Jess’s Peptides', 'yeffoprint' ); ?>" autocomplete="off" data-yp-text />
									<p class="yp-print-addon__hint">
										<span data-yp-text-count>0</span>/<?php echo (int) $addons['text_max']; ?> <?php esc_html_e( 'characters. We print it exactly as typed.', 'yeffoprint' ); ?>
									</p>
									<?php $addon_swatches( 'text_color', __( 'Text color', 'yeffoprint' ) ); ?>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( $addons['image'] ) : ?>
							<div class="yp-print-addon" data-yp-addon="image">
								<label class="yp-print-addon__toggle">
									<input type="checkbox" data-yp-addon-toggle data-price="<?php echo esc_attr( (string) $addons['image_price'] ); ?>" />
									<span>
										<?php /* translators: %s: where on the print, e.g. "lid" */ ?>
										<strong><?php echo esc_html( sprintf( __( 'Add an image to the %s', 'yeffoprint' ), $area ) ); ?></strong>
										<small><?php esc_html_e( 'Single-color design', 'yeffoprint' ); ?></small>
										<?php if ( $addons['image_price'] > 0 ) : ?>
											<em>+<?php echo esc_html( $money( $addons['image_price'] ) ); ?></em>
										<?php endif; ?>
									</span>
								</label>
								<div class="yp-print-addon__body" data-yp-addon-body hidden>
									<label class="yp-print-addon__file">
										<input type="file" accept=".png,.jpg,.jpeg,.svg,.pdf,image/png,image/jpeg,image/svg+xml,application/pdf" data-yp-image-file />
										<span data-yp-image-label><?php esc_html_e( 'Choose a logo or image', 'yeffoprint' ); ?></span>
									</label>
									<p class="yp-print-addon__hint"><?php esc_html_e( 'Your image is printed in one color, so use a simple, single-color design like a logo, icon or silhouette. Photos, gradients and shading won’t come through. PNG, JPG, SVG or PDF, up to 10MB.', 'yeffoprint' ); ?></p>
									<?php $addon_swatches( 'image_color', __( 'Image color', 'yeffoprint' ) ); ?>
								</div>
							</div>
						<?php endif; ?>
					</div>
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

				<?php if ( $slots || $sizes || $addons['text'] || $addons['image'] ) : ?>
					<p class="yp-print__summary" data-yp-summary></p>
				<?php endif; ?>
			</form>
		</div>
	</div>
</div>
