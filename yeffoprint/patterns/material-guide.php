<?php
/**
 * Title: Material Guide
 * Slug: yeffoprint/material-guide
 * Categories: yeffoprint
 *
 * Direct request: real customer-facing copy for each material type,
 * to sit right below the swatch grid (materials.php) as a deeper
 * "which one should I pick" guide. Deliberately hardcoded, not
 * data-driven like materials.php — this is verbatim business copy the
 * site owner supplied (lightly copyedited for grammar only, facts
 * untouched), not something to derive from a Material record's own
 * short blurb field.
 *
 * Includes Prism, which materials.php's swatch grid won't show unless
 * a published `yp_material` record for it exists yet — see this
 * pattern's own note in docs/ARCHITECTURE.md.
 */

defined( 'ABSPATH' ) || exit;
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

		<div class="yp-material-guide__item">
			<div class="yp-material-guide__header">
				<h3>Standard White Glossy</h3>
				<span class="yp-material-guide__spec">2.75mil &middot; Glossy</span>
			</div>
			<p>Our standard material, recommended for most projects. A bright white base with a slight shine, and very easy to apply.</p>
		</div>

		<div class="yp-material-guide__item">
			<div class="yp-material-guide__header">
				<h3>Standard White Matte</h3>
				<span class="yp-material-guide__spec">2.75mil &middot; Matte</span>
			</div>
			<p>Our standard material, recommended for most projects. The same bright white base as our glossy option, without the shine, and very easy to apply.</p>
		</div>

		<div class="yp-material-guide__item">
			<div class="yp-material-guide__header">
				<h3>Holographic</h3>
				<span class="yp-material-guide__spec">Rainbow Sheen</span>
			</div>
			<p>Our most popular holographic option, slightly thicker than our standard labels. Anywhere your design shows white, it takes on a rainbow, holographic sheen in the light.</p>
			<p class="yp-material-guide__note">Designs with highly saturated solid colors can occasionally cause holographic sheets to curl slightly during shipping. This never affects a label's print quality or stickiness, but it does mean holographic orders ship about 24 hours later than usual to account for it.</p>
		</div>

		<div class="yp-material-guide__item">
			<div class="yp-material-guide__header">
				<h3>Prism</h3>
				<span class="yp-material-guide__spec">Prism Pattern</span>
			</div>
			<p>One of the newest additions to our lineup, slightly thicker than our standard labels. Anywhere your design shows white, it takes on our prism pattern — best suited to simpler designs, since a busier design can make the effect feel overwhelming.</p>
		</div>

		<div class="yp-material-guide__item">
			<div class="yp-material-guide__header">
				<h3>Metallic</h3>
				<span class="yp-material-guide__spec">4mil &middot; Chrome</span>
			</div>
			<p>A newer addition with a true chrome finish. Anywhere your design shows white, it takes on a metallic shine.</p>
		</div>

		<div class="yp-material-guide__item">
			<div class="yp-material-guide__header">
				<h3>Transparent (Clear)</h3>
				<span class="yp-material-guide__spec">Clear</span>
			</div>
			<p>Exactly what it sounds like — a fully clear label. Works best with simpler designs and less image detail, since fine detail can oversaturate during printing and edges can appear slightly blurred.</p>
		</div>

	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
