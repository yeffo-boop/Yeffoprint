<?php
/**
 * Title: Web Design Packages
 * Slug: yeffoprint/web-design-packages
 * Categories: yeffoprint
 *
 * Direct request: describe web design packages "from design to
 * execution." Confirmed with the site owner: packages are sold via a
 * quote/contact conversation, not self-serve checkout — scope varies too
 * much per client for a fixed price — so every card's CTA goes to
 * /contact/, not a cart/checkout flow.
 *
 * Confirmed with the owner: draft realistic placeholder tiers/pricing now
 * rather than waiting on final numbers. Every price below is a hardcoded
 * PLACEHOLDER, deliberately impossible to mistake for a real price both
 * in code (see the array below) and on the rendered page (each card
 * shows an explicit "Placeholder" flag above the dollar amount) — edit
 * the $packages array directly to set real pricing/features before this
 * page goes live. Same "hardcoded content array, easy to hand-edit"
 * convention material-guide.php's own $materials array already
 * established for business copy that isn't computationally load-bearing
 * elsewhere.
 */

defined( 'ABSPATH' ) || exit;

$packages = [
	[
		'name'     => 'Starter',
		'price'    => '$X,XXX',
		'tagline'  => 'A focused, single-line storefront to get selling fast.',
		'features' => [
			'Up to 5 pages, built on a proven template',
			'WooCommerce set up for your product catalog',
			'Domain connected',
			'Launch checklist & handoff walkthrough',
		],
		'featured' => false,
	],
	[
		'name'     => 'Growth',
		'price'    => '$X,XXX',
		'tagline'  => 'A fully custom storefront designed around your brand.',
		'features' => [
			'Custom design, not a template',
			'WooCommerce configured for your full catalog',
			'Domain + business email set up',
			'Basic on-page SEO setup',
			'Two rounds of revisions before launch',
		],
		'featured' => true,
	],
	[
		'name'     => 'Full-Service',
		'price'    => '$X,XXX',
		'tagline'  => 'Everything in Growth, plus we run the entire launch.',
		'features' => [
			'Everything in Growth',
			'Payment processing & shipping setup',
			'Product photography guidance',
			'Priority launch support',
			'30 days of post-launch support included',
		],
		'featured' => false,
	],
];
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section" id="yp-web-design-packages">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow">Packages</p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="wp-block-heading has-text-align-center">Design Through Execution</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center"} -->
	<p class="has-text-align-center">Every project is scoped to what you're actually building — these are starting points, not a fixed menu. <a href="/contact/">Tell us about your store</a> and we'll put together a real quote.</p>
	<!-- /wp:paragraph -->

	<!-- wp:html -->
	<div class="yp-web-design-packages">
		<?php foreach ( $packages as $package ) : ?>
			<div class="yp-web-design-package<?php echo $package['featured'] ? ' yp-web-design-package--featured' : ''; ?>">
				<?php if ( $package['featured'] ) : ?>
					<span class="yp-web-design-package__badge">Most Popular</span>
				<?php endif; ?>
				<h3 class="yp-web-design-package__name"><?php echo esc_html( $package['name'] ); ?></h3>
				<p class="yp-web-design-package__tagline"><?php echo esc_html( $package['tagline'] ); ?></p>
				<div class="yp-web-design-package__price">
					<span class="yp-web-design-package__price-flag">Placeholder — edit before launch</span>
					<span class="yp-web-design-package__price-amount"><?php echo esc_html( $package['price'] ); ?></span>
				</div>
				<ul class="yp-web-design-package__features">
					<?php foreach ( $package['features'] as $feature ) : ?>
						<li>
							<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
								<path d="M3 8.5L6.5 12L13 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
							</svg>
							<?php echo esc_html( $feature ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<a class="wp-block-button__link wp-element-button yp-web-design-package__cta" href="/contact/">Get a Quote</a>
			</div>
		<?php endforeach; ?>
	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
