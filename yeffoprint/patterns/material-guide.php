<?php
/**
 * Title: Material Guide
 * Slug: yeffoprint/material-guide
 * Categories: yeffoprint
 *
 * Direct request: real customer-facing copy for each material type,
 * to sit right below the swatch grid (materials.php) as a deeper
 * "which one should I pick" guide. The copy itself is deliberately
 * hardcoded, not data-driven like materials.php — this is verbatim
 * business copy the site owner supplied (lightly copyedited for
 * grammar only, facts untouched), not something to derive from a
 * Material record's own short blurb field.
 *
 * The photo next to each entry is real, though (V2, direct follow-up
 * request: "pull the picture assigned to the material") — looked up by
 * matching each entry's own `slug` below against a published
 * `yp_material` record's post_name, the same swatch image
 * materials.php's own grid already reads via get_the_post_thumbnail_url().
 * A material with no matching record yet, or a record with no photo
 * uploaded, falls back to the same per-material gradient "chip" look
 * materials.php's own swatch grid already falls back to — same
 * `--{slug}` modifier classes, shared CSS rule (see patterns.css).
 *
 * The thickness figure in each entry's spec pill (V3, direct follow-up
 * request: "add that field to the material form so I can indicate
 * thickness there and you can pull that data and display it here") is
 * live where the matched record has one set (Material editor's
 * Thickness (mil) field, class-material-size-editor.php) — built from
 * that value plus the entry's own hardcoded `finish` label below. A
 * material with no matching record, or a record with no thickness set
 * yet, falls back to this entry's own hardcoded `spec` string.
 *
 * Each circle is click-to-enlarge (V4, direct follow-up request: "hover
 * or click on the image to see a bigger image of what the material
 * looks like on the vinyl") — reuses the site's existing accessible
 * drawer primitive (assets/js/site.js's openDrawer/closeDrawer, the
 * same one driving the header's search/cart panels and the splash
 * screen) in its centered-modal variant, one per material, wired
 * entirely through data-yp-drawer-trigger/-close — no new JS. The
 * enlarged photo prefers the matched record's "Hover / on-vial image"
 * (Material editor field, class-material-size-editor.php) — a real
 * photo of the finish actually applied to a vial, which is exactly
 * what was asked for — falling back to a larger export of the same
 * swatch photo if no on-vial photo has been uploaded yet. A material
 * with neither (gradient-fallback only) gets no zoom affordance at
 * all, since there's no larger image to show.
 */

defined( 'ABSPATH' ) || exit;

// One entry per material this guide describes. `slug` must match the
// yp_material record's own post_name for the real photo to be found —
// this project's own established slugs (glossy-white, matte-white,
// holographic, clear, metallic) for the five that already exist from
// initial seeding; `prism` is a guess for if/when that one is added,
// since it doesn't exist yet (see this pattern's own docblock above).
$materials = [
	[
		'slug'   => 'glossy-white',
		'name'   => 'Standard White Glossy',
		'spec'   => '2.75mil · Glossy',
		'finish' => 'Glossy',
		'body'   => "Our standard material, recommended for most projects. A bright white base with a slight shine, and very easy to apply.",
		'note'   => '',
	],
	[
		'slug'   => 'matte-white',
		'name'   => 'Standard White Matte',
		'spec'   => '2.75mil · Matte',
		'finish' => 'Matte',
		'body'   => 'Our standard material, recommended for most projects. The same bright white base as our glossy option, without the shine, and very easy to apply.',
		'note'   => '',
	],
	[
		'slug'   => 'holographic',
		'name'   => 'Holographic',
		'spec'   => 'Rainbow Sheen',
		'finish' => 'Rainbow Sheen',
		'body'   => 'Our most popular holographic option, slightly thicker than our standard labels. Anywhere your design shows white, it takes on a rainbow, holographic sheen in the light.',
		'note'   => "Designs with highly saturated solid colors can occasionally cause holographic sheets to curl slightly during shipping. This never affects a label's print quality or stickiness, but it does mean holographic orders ship about 24 hours later than usual to account for it.",
	],
	[
		'slug'   => 'prism',
		'name'   => 'Prism',
		'spec'   => 'Prism Pattern',
		'finish' => 'Prism Pattern',
		'body'   => 'One of the newest additions to our lineup, slightly thicker than our standard labels. Anywhere your design shows white, it takes on our prism pattern — best suited to simpler designs, since a busier design can make the effect feel overwhelming.',
		'note'   => '',
	],
	[
		'slug'   => 'metallic',
		'name'   => 'Metallic',
		'spec'   => '4mil · Chrome',
		'finish' => 'Chrome',
		'body'   => 'A newer addition with a true chrome finish. Anywhere your design shows white, it takes on a metallic shine.',
		'note'   => '',
	],
	[
		'slug'   => 'clear',
		'name'   => 'Transparent (Clear)',
		'spec'   => 'Clear',
		'finish' => 'Clear',
		'body'   => 'Exactly what it sounds like — a fully clear label. Works best with simpler designs and less image detail, since fine detail can oversaturate during printing and edges can appear slightly blurred.',
		'note'   => '',
	],
];
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"760px"}} -->
<section class="wp-block-group yp-section">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow">Choosing a Material</p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="wp-block-heading has-text-align-center">What Material Should I Select?</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center"} -->
	<p class="has-text-align-center">This is a great question — and an important one for your label design. Here's what to know about each material we offer. Have questions about a specific one, or want a recommendation for the design you've chosen? <a href="/contact/">Contact us</a> — we're happy to help.</p>
	<!-- /wp:paragraph -->

	<!-- wp:html -->
	<div class="yp-material-guide">

		<?php foreach ( $materials as $material ) :
			$record     = get_posts( [
				'name'           => $material['slug'],
				'post_type'      => 'yp_material',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			] );
			$photo_url = $record ? get_the_post_thumbnail_url( $record[0], 'thumbnail' ) : '';
			$hover_id  = $record ? (int) get_post_meta( $record[0]->ID, YeffoPrint_Commerce_Record_Meta::HOVER_IMAGE, true ) : 0;
			$zoom_url  = $hover_id ? wp_get_attachment_image_url( $hover_id, 'large' ) : ( $record ? get_the_post_thumbnail_url( $record[0], 'large' ) : '' );
			$thickness = $record ? (float) get_post_meta( $record[0]->ID, YeffoPrint_Commerce_Record_Meta::THICKNESS_MIL, true ) : 0;
			$spec      = $material['spec'];
			if ( $thickness > 0 ) {
				$thickness_display = rtrim( rtrim( number_format( $thickness, 2, '.', '' ), '0' ), '.' );
				$spec              = $thickness_display . 'mil · ' . $material['finish'];
			}
			$zoom_id = 'yp-material-zoom-' . $material['slug'];
			?>
			<div class="yp-material-guide__item">

				<?php if ( $zoom_url ) : ?>
					<button type="button" class="yp-material-guide__photo-btn" data-yp-drawer-trigger="<?php echo esc_attr( $zoom_id ); ?>" aria-haspopup="dialog" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: material name */ __( 'See a larger photo of %s', 'yeffoprint' ), $material['name'] ) ); ?>">
						<?php if ( $photo_url ) : ?>
							<img class="yp-material-guide__photo" src="<?php echo esc_url( $photo_url ); ?>" alt="" width="88" height="88" />
						<?php else : ?>
							<span class="yp-material-guide__photo yp-material-guide__photo--<?php echo esc_attr( $material['slug'] ); ?>" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="yp-material-guide__zoom-badge" aria-hidden="true">
							<svg width="12" height="12" viewBox="0 0 16 16" fill="none" focusable="false">
								<circle cx="6.75" cy="6.75" r="4.75" stroke="currentColor" stroke-width="1.5" />
								<line x1="10.25" y1="10.25" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
							</svg>
						</span>
					</button>
				<?php elseif ( $photo_url ) : ?>
					<img class="yp-material-guide__photo" src="<?php echo esc_url( $photo_url ); ?>" alt="" width="88" height="88" />
				<?php else : ?>
					<div class="yp-material-guide__photo yp-material-guide__photo--<?php echo esc_attr( $material['slug'] ); ?>" aria-hidden="true"></div>
				<?php endif; ?>

				<div class="yp-material-guide__body">
					<div class="yp-material-guide__header">
						<h3><?php echo esc_html( $material['name'] ); ?></h3>
						<span class="yp-material-guide__spec"><?php echo esc_html( $spec ); ?></span>
					</div>
					<p><?php echo esc_html( $material['body'] ); ?></p>
					<?php if ( $material['note'] ) : ?>
						<p class="yp-material-guide__note"><?php echo esc_html( $material['note'] ); ?></p>
					<?php endif; ?>
				</div>

			</div>

			<?php if ( $zoom_url ) : ?>
				<div id="<?php echo esc_attr( $zoom_id ); ?>" class="yp-drawer yp-drawer--center" aria-hidden="true">
					<div class="yp-drawer__backdrop"></div>
					<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $material['name'] ); ?>">
						<div class="yp-drawer__header">
							<span><?php echo esc_html( $material['name'] ); ?></span>
							<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="<?php esc_attr_e( 'Close', 'yeffoprint' ); ?>">
								<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
									<line x1="2" y1="2" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
									<line x1="14" y1="2" x2="2" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
								</svg>
							</button>
						</div>
						<div class="yp-drawer__body yp-material-guide__zoom-body">
							<img src="<?php echo esc_url( $zoom_url ); ?>" alt="<?php echo esc_attr( $material['name'] ); ?>" />
						</div>
					</div>
				</div>
			<?php endif; ?>
		<?php endforeach; ?>

	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
