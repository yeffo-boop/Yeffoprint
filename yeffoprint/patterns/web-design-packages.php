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
 * Tiers are real, admin-editable yp_web_design_pkg records now
 * (direct follow-up: "I'd like to make it future proof and be able to
 * adjust prices from the YeffoPrint admin panel") — see
 * class-web-design-package-editor.php. Originally a hardcoded array
 * (material-guide.php's own $materials array established that
 * convention for business copy that isn't computationally load-bearing
 * elsewhere), moved to a real CPT once the request was specifically to
 * make it admin-editable. `wp yeffoprint setup-web-design-packages`
 * seeds the three placeholder tiers that array used to hold, so a fresh
 * deploy isn't blank before the owner has edited anything. A price that
 * still reads exactly "$X,XXX" (that seeded placeholder, untouched) gets
 * an explicit "Placeholder" flag above it on the rendered page — edited
 * to any other value, the flag stops showing on its own.
 *
 * The maintenance-subscription badge above the grid (direct follow-up:
 * "I don't like where the monthly maintenance and monitoring is. Maybe
 * move it towards the top on some badge on the pricing table?") replaces
 * what used to be its own full section further down the page
 * (web-design-maintenance-teaser.php, now deleted — this pattern owns
 * that link now). Same payment-link-with-fallback logic that pattern
 * had: reads 'yeffoprint_maintenance_payment_link' directly, falling
 * back to /contact/ until that Stripe setup is done — see
 * docs/ARCHITECTURE.md.
 */

defined( 'ABSPATH' ) || exit;

$maintenance_payment_link = get_option( 'yeffoprint_maintenance_payment_link', '' );
$maintenance_url          = $maintenance_payment_link ? $maintenance_payment_link : home_url( '/contact/' );

// The seed command's own starting value — still exactly this means the
// owner hasn't edited this tier's price yet. A local variable, not a
// top-level const: this file can run more than once per request (every
// page that includes this pattern), and a const would fatal the second
// time.
$placeholder_price = '$X,XXX';

$packages = array_map( static function ( $post ) {
	return [
		'name'     => get_the_title( $post ),
		'price'    => (string) get_post_meta( $post->ID, YeffoPrint_Web_Design_Package_Meta::PRICE, true ),
		'tagline'  => (string) get_post_meta( $post->ID, YeffoPrint_Web_Design_Package_Meta::TAGLINE, true ),
		'features' => (array) get_post_meta( $post->ID, YeffoPrint_Web_Design_Package_Meta::FEATURES, true ),
		'featured' => (bool) get_post_meta( $post->ID, YeffoPrint_Web_Design_Package_Meta::FEATURED, true ),
	];
}, YeffoPrint_Web_Design_Package_Meta::get_published() );
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
	<a class="yp-web-design-maintenance-badge" href="<?php echo esc_url( $maintenance_url ); ?>">
		<svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
			<path d="M12.5 3.5a4 4 0 0 0-5.4 4.9L2.5 13a1.8 1.8 0 0 0 2.5 2.5l4.6-4.6a4 4 0 0 0 4.9-5.4l-2.6 2.6-2-2 2.6-2.6z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" />
		</svg>
		<span>Every package can add <strong>ongoing maintenance &amp; monitoring</strong> after launch</span>
		<svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
			<path d="M6 3L11 8L6 13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
		</svg>
	</a>
	<!-- /wp:html -->

	<?php if ( $packages ) : ?>
		<!-- wp:html -->
		<div class="yp-web-design-packages">
			<?php foreach ( $packages as $package ) :
				$is_placeholder_price = $placeholder_price === trim( $package['price'] );
				?>
				<div class="yp-web-design-package<?php echo $package['featured'] ? ' yp-web-design-package--featured' : ''; ?>">
					<?php if ( $package['featured'] ) : ?>
						<span class="yp-web-design-package__badge">Most Popular</span>
					<?php endif; ?>
					<h3 class="yp-web-design-package__name"><?php echo esc_html( $package['name'] ); ?></h3>
					<p class="yp-web-design-package__tagline"><?php echo esc_html( $package['tagline'] ); ?></p>
					<div class="yp-web-design-package__price">
						<?php if ( $is_placeholder_price ) : ?>
							<span class="yp-web-design-package__price-flag">Placeholder — edit before launch</span>
						<?php endif; ?>
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
	<?php else : ?>
		<!-- wp:paragraph {"align":"center"} -->
		<p class="has-text-align-center">Packages coming soon — <a href="/contact/">contact us</a> in the meantime.</p>
		<!-- /wp:paragraph -->
	<?php endif; ?>

</section>
<!-- /wp:group -->
