<?php
/**
 * Title: Web Design Coming Soon
 * Slug: yeffoprint/web-design-coming-soon
 * Categories: yeffoprint
 *
 * Shown instead of the real Web Design page to any visitor without
 * manage_options — direct request: "put a role gate on that page so
 * only admins can see it right now. Other users should see a coming
 * soon page instead." The actual swap is a page_template_hierarchy
 * filter (class-web-design-page-gate.php), not a redirect or a
 * condition inside the real page's own patterns — the URL still
 * resolves normally, just to this content instead.
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"680px"}} -->
<section class="wp-block-group yp-section">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow">Web Design for Peptide Resellers</p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":1} -->
	<h1 class="wp-block-heading has-text-align-center">Coming Soon</h1>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center","fontSize":"large"} -->
	<p class="has-text-align-center has-large-font-size">We're putting the finishing touches on this. Check back soon — or reach out now if you'd like to get a head start.</p>
	<!-- /wp:paragraph -->

	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button {"className":"is-style-accent"} -->
		<div class="wp-block-button is-style-accent"><a class="wp-block-button__link wp-element-button" href="/contact/">Contact Us</a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->

</section>
<!-- /wp:group -->
