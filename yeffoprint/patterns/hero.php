<?php
/**
 * Title: Hero
 * Slug: yeffoprint/hero
 * Categories: yeffoprint
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","className":"yp-hero yp-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-hero yp-section">

	<!-- wp:group {"className":"yp-hero__grid","layout":{"type":"default"}} -->
	<div class="wp-block-group yp-hero__grid">

		<!-- wp:group {"className":"yp-hero__content","layout":{"type":"default"}} -->
		<div class="wp-block-group yp-hero__content">

			<!-- wp:group {"className":"yp-hero__eyebrow-accent"} -->
			<div class="wp-block-group yp-hero__eyebrow-accent"><span></span><span></span><span></span></div>
			<!-- /wp:group -->

			<!-- wp:paragraph {"className":"yp-eyebrow-label"} -->
			<p class="yp-eyebrow-label">Free digital proof before every print run</p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":1,"fontSize":"xx-large"} -->
			<h1 class="wp-block-heading has-xx-large-font-size">Vial labels, designed once, <span class="yp-hero__accent-word">printed</span> to spec.</h1>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontSize":"large"} -->
			<p class="has-large-font-size">YeffoDesign designs and prints research and supplement vial labels — waterproof stock, exact color matching, and a proof you approve before anything hits press.</p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-accent"} -->
				<div class="wp-block-button is-style-accent"><a class="wp-block-button__link wp-element-button" href="/shop-labels/">Browse label templates</a></div>
				<!-- /wp:button -->

				<!-- wp:button {"className":"is-style-outline"} -->
				<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/custom-design/">Start a custom design</a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->

			<!-- wp:html -->
			<ul class="yp-hero__stats">
				<li><span class="yp-hero__stats-dot" style="background:var(--wp--preset--color--cyan)"></span>Waterproof, solvent-resistant stock</li>
				<li><span class="yp-hero__stats-dot" style="background:var(--wp--preset--color--magenta)"></span>Free proof before printing</li>
				<li><span class="yp-hero__stats-dot" style="background:var(--wp--preset--color--yellow)"></span>Low minimums, from 10 labels</li>
			</ul>
			<!-- /wp:html -->

		</div>
		<!-- /wp:group -->

		<!-- wp:html -->
		<div class="yp-hero__visual" aria-hidden="true">
			<div class="yp-proof">
				<span class="yp-proof__corner yp-proof__corner--tl"></span>
				<span class="yp-proof__corner yp-proof__corner--tr"></span>
				<span class="yp-proof__corner yp-proof__corner--bl"></span>
				<span class="yp-proof__corner yp-proof__corner--br"></span>
				<svg class="yp-proof__mark" viewBox="0 0 120 136" fill="none">
					<clipPath id="ypHeroMarkClip"><path d="M16 24C16 15.16 23.16 8 32 8h56c8.84 0 16 7.16 16 16v88c0 13.25-10.75 24-24 24H40c-13.25 0-24-10.75-24-24V24z" /></clipPath>
					<g clip-path="url(#ypHeroMarkClip)">
						<rect class="yp-proof__bar yp-proof__bar--c" x="16" y="8" width="29.3" height="136" fill="var(--wp--preset--color--cyan)" />
						<rect class="yp-proof__bar yp-proof__bar--m" x="45.3" y="8" width="29.3" height="136" fill="var(--wp--preset--color--magenta)" />
						<rect class="yp-proof__bar yp-proof__bar--y" x="74.7" y="8" width="29.3" height="136" fill="var(--wp--preset--color--yellow)" />
					</g>
				</svg>
				<span class="yp-proof__caption">Press proof &middot; registration mark</span>
			</div>
		</div>
		<!-- /wp:html -->

	</div>
	<!-- /wp:group -->

</section>
<!-- /wp:group -->
