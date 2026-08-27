<?php
/**
 * Title: Web Design Included
 * Slug: yeffoprint/web-design-included
 * Categories: yeffoprint
 *
 * Direct request: describe what's included beyond the package tiers
 * above — "can assist with domain setup, email, woocommerce plugins/
 * features, etc." An icon + label grid, reusing the theme's established
 * hand-drawn icon convention (20x20 viewBox, stroke="currentColor",
 * stroke-width 1.6 — see process-steps.php for the same idiom) rather
 * than inventing a new icon style for this one section.
 *
 * Direct follow-up: this grid originally read as a blanket "every
 * package gets all of this," which isn't true — Custom Design, Domain
 * Setup, and Business Email vary by tier (each tier's own FEATURES list
 * on the pricing cards above is the source of truth for which), and
 * Ongoing Support isn't included in any tier at all — it's the
 * Maintenance & Monitoring add-on (web-design-packages.php's own badge/
 * modal). Copy on those four items now says so explicitly rather than
 * implying blanket inclusion; WooCommerce Setup and Plugins & Features
 * stay as-is since those genuinely are universal.
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","className":"yp-section yp-section--tint","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section yp-section--tint">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow">What's Included</p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="wp-block-heading has-text-align-center">More Than Just a Design</h2>
	<!-- /wp:heading -->

	<!-- wp:html -->
	<div class="yp-included-grid">

		<div class="yp-included-item">
			<div class="yp-included-item__icon" aria-hidden="true">
				<svg width="22" height="22" viewBox="0 0 20 20" fill="none">
					<path d="M13.5 2.5L17.5 6.5L7 17H3V13L13.5 2.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
					<line x1="11" y1="5" x2="15" y2="9" stroke="currentColor" stroke-width="1.6" />
				</svg>
			</div>
			<h3>Custom Design</h3>
			<p>A storefront designed around your brand, not a generic template. Included in select packages — see plan details above.</p>
		</div>

		<div class="yp-included-item">
			<div class="yp-included-item__icon" aria-hidden="true">
				<svg width="22" height="22" viewBox="0 0 20 20" fill="none">
					<circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.6" />
					<ellipse cx="10" cy="10" rx="3" ry="7.5" stroke="currentColor" stroke-width="1.6" />
					<line x1="2.5" y1="10" x2="17.5" y2="10" stroke="currentColor" stroke-width="1.6" />
				</svg>
			</div>
			<h3>Domain Setup</h3>
			<p>We connect your domain and get DNS pointed correctly, no guesswork. Included in select packages — see plan details above.</p>
		</div>

		<div class="yp-included-item">
			<div class="yp-included-item__icon" aria-hidden="true">
				<svg width="22" height="22" viewBox="0 0 20 20" fill="none">
					<rect x="2" y="4.5" width="16" height="11" rx="1.5" stroke="currentColor" stroke-width="1.6" />
					<path d="M2.5 5.5L10 11L17.5 5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
				</svg>
			</div>
			<h3>Business Email</h3>
			<p>Professional email at your own domain, set up and ready to use. Included in select packages — see plan details above.</p>
		</div>

		<div class="yp-included-item">
			<div class="yp-included-item__icon" aria-hidden="true">
				<svg width="22" height="22" viewBox="0 0 20 20" fill="none">
					<path d="M2 5h2l1.6 9.2a1.5 1.5 0 0 0 1.48 1.3h7.3a1.5 1.5 0 0 0 1.48-1.24L17 8H5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
					<circle cx="8.5" cy="18" r="1.1" fill="currentColor" />
					<circle cx="15" cy="18" r="1.1" fill="currentColor" />
				</svg>
			</div>
			<h3>WooCommerce Setup</h3>
			<p>Your catalog, shipping, and checkout configured and tested.</p>
		</div>

		<div class="yp-included-item">
			<div class="yp-included-item__icon" aria-hidden="true">
				<svg width="22" height="22" viewBox="0 0 20 20" fill="none">
					<path d="M7 3h3a1 1 0 0 1 1 1v1a1.5 1.5 0 0 0 3 0V4a1 1 0 0 1 1-1h2v3a1 1 0 0 1-1 1h-1a1.5 1.5 0 0 0 0 3h1a1 1 0 0 1 1 1v3h-3a1 1 0 0 1-1-1v-1a1.5 1.5 0 0 0-3 0v1a1 1 0 0 1-1 1H7v-3a1 1 0 0 0-1-1H5a1.5 1.5 0 0 1 0-3h1a1 1 0 0 0 1-1V3z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" />
				</svg>
			</div>
			<h3>Plugins &amp; Features</h3>
			<p>The right plugins for what you actually need — nothing bloated.</p>
		</div>

		<div class="yp-included-item">
			<div class="yp-included-item__icon" aria-hidden="true">
				<svg width="22" height="22" viewBox="0 0 20 20" fill="none">
					<path d="M12.5 3.5a4 4 0 0 0-5.4 4.9L2.5 13a1.8 1.8 0 0 0 2.5 2.5l4.6-4.6a4 4 0 0 0 4.9-5.4l-2.6 2.6-2-2 2.6-2.6z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" />
				</svg>
			</div>
			<h3>Ongoing Support</h3>
			<p>We're here after launch if something needs attention — available as an add-on to any package.</p>
		</div>

	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
