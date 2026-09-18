<?php
/**
 * Title: How It Works
 * Slug: yeffoprint/how-it-works
 * Categories: yeffoprint
 *
 * Numbered 01→02→03 sequence with connectors — a process, not a
 * feature-card grid.
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section">

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="wp-block-heading has-text-align-center">How It Works</h2>
	<!-- /wp:heading -->

	<!-- wp:html -->
	<ol class="yp-steps yp-steps--sequence">
		<li class="yp-step">
			<span class="yp-step__n" aria-hidden="true">01</span>
			<h3 class="yp-step__title">Choose a design</h3>
			<p>Browse the full gallery — no forced categories, no dead ends.</p>
		</li>
		<li class="yp-steps__conn" aria-hidden="true">
			<span class="yp-steps__arrow">→</span>
		</li>
		<li class="yp-step">
			<span class="yp-step__n" aria-hidden="true">02</span>
			<h3 class="yp-step__title">Customize live</h3>
			<p>Edit text, pick a size and material, and watch the label and vial preview update instantly.</p>
		</li>
		<li class="yp-steps__conn" aria-hidden="true">
			<span class="yp-steps__arrow">→</span>
		</li>
		<li class="yp-step">
			<span class="yp-step__n" aria-hidden="true">03</span>
			<h3 class="yp-step__title">Print</h3>
			<p>We print to order and ship — no inventory, no guesswork, no minimums that don't make sense for you.</p>
		</li>
	</ol>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
