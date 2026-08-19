<?php
/**
 * Title: Customer Work / Inspiration
 * Slug: yeffoprint/customer-work
 * Categories: yeffoprint
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","className":"yp-section yp-section--tint","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section yp-section--tint">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow">Inspiration</p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="wp-block-heading has-text-align-center">From Our Customers</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center"} -->
	<p class="has-text-align-center">Real labels, real bottles. Replace these tiles with customer photos as they come in.</p>
	<!-- /wp:paragraph -->

	<!-- wp:html -->
	<div class="yp-card-grid">
		<?php for ( $i = 0; $i < 4; $i++ ) : ?>
			<div class="yp-card yp-card__media yp-customer-tile" aria-hidden="true">
				<svg width="28" height="28" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
					<rect x="2" y="4" width="16" height="12" rx="2" stroke="currentColor" stroke-width="1.5" />
					<circle cx="7" cy="9" r="1.5" stroke="currentColor" stroke-width="1.5" />
					<path d="M2 14l4.5-4 3 3 3-2.5L18 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
				</svg>
			</div>
		<?php endfor; ?>
	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
