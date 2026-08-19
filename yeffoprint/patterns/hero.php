<?php
/**
 * Title: Hero
 * Slug: yeffoprint/hero
 * Categories: yeffoprint
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","className":"yp-hero yp-section","layout":{"type":"constrained","contentSize":"760px"}} -->
<section class="wp-block-group yp-hero yp-section">

	<!-- wp:group {"className":"yp-hero__eyebrow-accent"} -->
	<div class="wp-block-group yp-hero__eyebrow-accent"><span></span><span></span><span></span></div>
	<!-- /wp:group -->

	<!-- wp:heading {"textAlign":"center","level":1,"fontSize":"xx-large"} -->
	<h1 class="wp-block-heading has-text-align-center has-xx-large-font-size">Your label, exactly as you imagine it.</h1>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center","fontSize":"large"} -->
	<p class="has-text-align-center has-large-font-size">Design custom vial labels live, preview them on the actual bottle, and print with studio-grade precision. No design software required.</p>
	<!-- /wp:paragraph -->

	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button {"className":"is-style-accent"} -->
		<div class="wp-block-button is-style-accent"><a class="wp-block-button__link wp-element-button" href="/shop-labels/">Browse Designs</a></div>
		<!-- /wp:button -->

		<!-- wp:button {"className":"is-style-outline"} -->
		<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/custom-design/">Create a Custom Label</a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->

	<!-- wp:html -->
	<ul class="yp-hero__stats">
		<li><span class="yp-hero__stats-dot" style="background:var(--wp--preset--color--cyan)"></span>48-hour turnaround</li>
		<li><span class="yp-hero__stats-dot" style="background:var(--wp--preset--color--magenta)"></span>No minimum order</li>
		<li><span class="yp-hero__stats-dot" style="background:var(--wp--preset--color--yellow)"></span>Studio-grade print quality</li>
	</ul>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
