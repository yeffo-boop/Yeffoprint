<?php
/**
 * Title: Web Design CTA (dark break)
 * Slug: yeffoprint/web-design-cta
 * Categories: yeffoprint
 *
 * Closing CTA for the Web Design page — same "dark break" section shape
 * as custom-design-cta.php (which this page can't reuse as-is, since
 * that pattern's copy is specific to label design), pointed at /contact/
 * per the confirmed quote-request flow.
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","className":"yp-section yp-section--dark","layout":{"type":"constrained","contentSize":"680px"}} -->
<section class="wp-block-group yp-section yp-section--dark">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow">Ready When You Are</p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":2,"fontSize":"x-large"} -->
	<h2 class="wp-block-heading has-text-align-center has-x-large-font-size">Let's talk about your store.</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center"} -->
	<p class="has-text-align-center">Tell us where you're starting from and what you're trying to sell — we'll put together a real proposal, not a form-letter quote.</p>
	<!-- /wp:paragraph -->

	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button -->
		<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Get a Quote</a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->

</section>
<!-- /wp:group -->
