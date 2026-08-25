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
			$thickness = $record ? (float) get_post_meta( $record[0]->ID, YeffoPrint_Commerce_Record_Meta::THICKNESS_MIL, true ) : 0;
			$spec      = $material['spec'];
			if ( $thickness > 0 ) {
				$thickness_display = rtrim( rtrim( number_format( $thickness, 2, '.', '' ), '0' ), '.' );
				$spec              = $thickness_display . 'mil · ' . $material['finish'];
			}
			?>
			<div class="yp-material-guide__item">

				<?php if ( $photo_url ) : ?>
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
		<?php endforeach; ?>

	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
