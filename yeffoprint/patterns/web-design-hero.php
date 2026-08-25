<?php
/**
 * Title: Web Design Hero
 * Slug: yeffoprint/web-design-hero
 * Categories: yeffoprint
 *
 * New line of business (direct request): web design for peptide
 * resellers, packages from design through execution. Reuses hero.php's
 * own structure (eyebrow-accent, H1, intro, buttons, stats row) so this
 * page opens exactly like every other page's hero rather than inventing
 * new hero markup — only the copy and the illustration are new.
 *
 * The illustration ("needs some nice graphics") follows hero.php's own
 * `.yp-proof__mark` technique: solid brand-colored shapes built directly
 * in SVG, not a raster image, so it stays crisp at any size and needs no
 * asset to manage. Concept: a browser-window mockup of a wireframed
 * storefront — window chrome (traffic-light dots in the brand's
 * cyan/magenta/yellow), a gradient hero band, a 3-card product grid, and
 * a cart badge — reads as "we build stores like this" at a glance.
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
			<p class="yp-eyebrow-label">Web design for peptide resellers</p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":1,"fontSize":"xx-large"} -->
			<h1 class="wp-block-heading has-xx-large-font-size">A store built for what you actually sell, <span class="yp-hero__accent-word">launched</span> end to end.</h1>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontSize":"large"} -->
			<p class="has-large-font-size">Design, build, and launch — domain, email, and WooCommerce set up and configured for a peptide storefront, not bolted on after the fact.</p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-accent"} -->
				<div class="wp-block-button is-style-accent"><a class="wp-block-button__link wp-element-button" href="/contact/">Get a Quote</a></div>
				<!-- /wp:button -->

				<!-- wp:button {"className":"is-style-outline"} -->
				<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#yp-web-design-packages">See What's Included</a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->

			<!-- wp:html -->
			<ul class="yp-hero__stats">
				<li><span class="yp-hero__stats-dot" style="background:var(--wp--preset--color--cyan)"></span>Design through launch, one team</li>
				<li><span class="yp-hero__stats-dot" style="background:var(--wp--preset--color--magenta)"></span>Domain, email &amp; WooCommerce configured</li>
				<li><span class="yp-hero__stats-dot" style="background:var(--wp--preset--color--yellow)"></span>Optional ongoing maintenance</li>
			</ul>
			<!-- /wp:html -->

		</div>
		<!-- /wp:group -->

		<!-- wp:html -->
		<div class="yp-hero__visual" aria-hidden="true">
			<svg class="yp-browser-mock" viewBox="0 0 320 224" fill="none">
				<defs>
					<linearGradient id="ypWebDesignBannerGradient" x1="0%" y1="0%" x2="100%" y2="100%">
						<stop offset="0%" style="stop-color:var(--wp--preset--color--magenta)" />
						<stop offset="100%" style="stop-color:var(--wp--preset--color--cyan)" />
					</linearGradient>
				</defs>
				<rect class="yp-browser-mock__frame" x="1" y="1" width="318" height="222" rx="14" />
				<rect class="yp-browser-mock__chrome" x="1" y="1" width="318" height="30" rx="14" />
				<rect class="yp-browser-mock__chrome" x="1" y="17" width="318" height="14" />
				<circle class="yp-browser-mock__dot yp-browser-mock__dot--c" cx="18" cy="16" r="4" />
				<circle class="yp-browser-mock__dot yp-browser-mock__dot--m" cx="32" cy="16" r="4" />
				<circle class="yp-browser-mock__dot yp-browser-mock__dot--y" cx="46" cy="16" r="4" />
				<rect class="yp-browser-mock__address" x="66" y="10" width="220" height="12" rx="6" />

				<rect class="yp-browser-mock__banner" x="16" y="46" width="288" height="46" rx="10" />
				<rect class="yp-browser-mock__banner-line" x="32" y="60" width="140" height="8" rx="4" />
				<rect class="yp-browser-mock__banner-line yp-browser-mock__banner-line--soft" x="32" y="74" width="90" height="6" rx="3" />

				<g class="yp-browser-mock__card">
					<rect x="16" y="106" width="88" height="94" rx="8" />
					<rect class="yp-browser-mock__swatch yp-browser-mock__swatch--c" x="24" y="114" width="72" height="46" rx="6" />
					<rect class="yp-browser-mock__text" x="24" y="168" width="56" height="6" rx="3" />
					<rect class="yp-browser-mock__text yp-browser-mock__text--soft" x="24" y="180" width="34" height="6" rx="3" />
				</g>
				<g class="yp-browser-mock__card">
					<rect x="116" y="106" width="88" height="94" rx="8" />
					<rect class="yp-browser-mock__swatch yp-browser-mock__swatch--m" x="124" y="114" width="72" height="46" rx="6" />
					<rect class="yp-browser-mock__text" x="124" y="168" width="56" height="6" rx="3" />
					<rect class="yp-browser-mock__text yp-browser-mock__text--soft" x="124" y="180" width="34" height="6" rx="3" />
				</g>
				<g class="yp-browser-mock__card">
					<rect x="216" y="106" width="88" height="94" rx="8" />
					<rect class="yp-browser-mock__swatch yp-browser-mock__swatch--y" x="224" y="114" width="72" height="46" rx="6" />
					<rect class="yp-browser-mock__text" x="224" y="168" width="56" height="6" rx="3" />
					<rect class="yp-browser-mock__text yp-browser-mock__text--soft" x="224" y="180" width="34" height="6" rx="3" />
				</g>

				<circle class="yp-browser-mock__cart" cx="290" cy="200" r="16" />
				<path class="yp-browser-mock__cart-icon" d="M283 196h2l1.4 8.2a1.3 1.3 0 0 0 1.3 1.1h6.4a1.3 1.3 0 0 0 1.3-1.1L297 199h-11" />
			</svg>
		</div>
		<!-- /wp:html -->

	</div>
	<!-- /wp:group -->

</section>
<!-- /wp:group -->
