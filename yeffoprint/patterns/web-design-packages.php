<?php
/**
 * Title: Web Design Packages
 * Slug: yeffoprint/web-design-packages
 * Categories: yeffoprint
 *
 * Direct request: describe web design packages "from design to
 * execution." Confirmed with the site owner: packages are sold via a
 * quote conversation, not self-serve checkout — scope varies too much
 * per client for a fixed price — so every card's CTA goes to a quote
 * form, not a cart/checkout flow (originally /contact/, now the
 * dedicated /web-design-quote/ intake form — see the follow-up note
 * below).
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
 * that link now).
 *
 * The badge opens a modal rather than linking out directly (direct
 * follow-up: "the badge doesn't do anything when clicked... show a
 * modal window styled like the site with information on what's
 * included") — reuses the site's existing accessible drawer primitive
 * (assets/js/site.js's openDrawer/closeDrawer, the same one already
 * driving the header's search/cart panels and the material guide's
 * photo lightboxes) in its centered-modal variant, wired purely
 * through data-yp-drawer-trigger/-close — no new JS. The Payment-Link-
 * with-/contact/-fallback logic (reads
 * 'yeffoprint_maintenance_payment_link' directly, falling back until
 * that Stripe setup is done — see docs/ARCHITECTURE.md) now drives the
 * modal's own CTA button instead of the badge's href.
 *
 * Direct follow-up: packages don't include hosting or domain
 * registration — those are the customer's own cost, unless they add
 * the new Hosting add-on ($35/mo, includes email + a 1-year domain
 * registration; a one-time $75 setup fee applies only if the customer
 * has no existing host and doesn't want to use their own). A second
 * badge, same `.yp-web-design-maintenance-badge` class as the
 * Maintenance one above (the styling is generic despite the name) and
 * the identical badge→modal pattern, just its own copy and a new
 * `#yp-hosting-modal` id. No Stripe payment link exists for hosting the
 * way Maintenance has one, so its CTA is a lead-in to the new quote
 * form rather than a subscribe button.
 *
 * Every "Get a Quote" link on this page (the intro paragraph, each
 * package card, the hosting modal) now points at the new
 * `/web-design-quote/` intake form (class-web-design-quote-controller.php)
 * instead of the generic `/contact/` form — direct request for a richer
 * intake than name/email/message.
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
	<p class="has-text-align-center">Every project is scoped to what you're actually building — these are starting points, not a fixed menu. <a href="/web-design-quote/">Tell us about your store</a> and we'll put together a real quote.</p>
	<!-- /wp:paragraph -->

	<!-- wp:html -->
	<div class="yp-web-design-badge-row">
		<button type="button" class="yp-web-design-maintenance-badge" data-yp-drawer-trigger="yp-maintenance-modal" aria-haspopup="dialog">
			<svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
				<path d="M12.5 3.5a4 4 0 0 0-5.4 4.9L2.5 13a1.8 1.8 0 0 0 2.5 2.5l4.6-4.6a4 4 0 0 0 4.9-5.4l-2.6 2.6-2-2 2.6-2.6z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" />
			</svg>
			<span>Every package can add <strong>ongoing maintenance &amp; monitoring</strong> for <strong>$35/mo</strong></span>
			<svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
				<path d="M6 3L11 8L6 13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
			</svg>
		</button>
		<button type="button" class="yp-web-design-maintenance-badge" data-yp-drawer-trigger="yp-hosting-modal" aria-haspopup="dialog">
			<svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
				<circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.6" />
				<ellipse cx="10" cy="10" rx="3" ry="7.5" stroke="currentColor" stroke-width="1.6" />
				<line x1="2.5" y1="10" x2="17.5" y2="10" stroke="currentColor" stroke-width="1.6" />
			</svg>
			<span>Need hosting too? Add it from <strong>$35/mo</strong> — email &amp; a domain included</span>
			<svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
				<path d="M6 3L11 8L6 13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
			</svg>
		</button>
	</div>
	<!-- /wp:html -->

	<!-- wp:html -->
	<div id="yp-hosting-modal" class="yp-drawer yp-drawer--center" aria-hidden="true">
		<div class="yp-drawer__backdrop"></div>
		<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="yp-hosting-modal-heading">
			<div class="yp-drawer__header">
				<span id="yp-hosting-modal-heading">Hosting Add-On</span>
				<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">
					<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
						<line x1="2" y1="2" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
						<line x1="14" y1="2" x2="2" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
					</svg>
				</button>
			</div>
			<div class="yp-drawer__body">
				<p>None of the packages above include hosting or domain registration — those are ongoing costs you hold directly, or you can add our hosting for <strong>$35/mo</strong>:</p>
				<ul class="yp-web-design-package__features">
					<li>
						<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
							<path d="M3 8.5L6.5 12L13 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
						</svg>
						Hosting for your storefront
					</li>
					<li>
						<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
							<path d="M3 8.5L6.5 12L13 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
						</svg>
						Business email at your own domain
					</li>
					<li>
						<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
							<path d="M3 8.5L6.5 12L13 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
						</svg>
						1 year of domain registration
					</li>
				</ul>
				<p>Don't have a host yet and don't want to use your own? There's a one-time <strong>$75 setup fee</strong> to get everything configured and moved in — otherwise, if you're bringing your own host, that fee doesn't apply.</p>
				<div class="wp-block-buttons">
					<div class="wp-block-button is-style-accent yp-maintenance-modal__cta">
						<a class="wp-block-button__link wp-element-button" href="/web-design-quote/">Ask About Hosting</a>
					</div>
				</div>
			</div>
		</div>
	</div>
	<!-- /wp:html -->

	<!-- wp:html -->
	<div id="yp-maintenance-modal" class="yp-drawer yp-drawer--center" aria-hidden="true">
		<div class="yp-drawer__backdrop"></div>
		<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="yp-maintenance-modal-heading">
			<div class="yp-drawer__header">
				<span id="yp-maintenance-modal-heading">Ongoing Maintenance &amp; Monitoring</span>
				<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">
					<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
						<line x1="2" y1="2" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
						<line x1="14" y1="2" x2="2" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
					</svg>
				</button>
			</div>
			<div class="yp-drawer__body">
				<p>A launched site still needs attention — plugin and core updates, and someone watching for issues before your customers find them. Add this to any package for <strong>$35/mo</strong> and we'll keep your store current and monitored, month to month.</p>
				<ul class="yp-web-design-package__features">
					<li>
						<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
							<path d="M3 8.5L6.5 12L13 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
						</svg>
						Core, theme, and plugin updates — applied and tested, not just installed blind
					</li>
					<li>
						<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
							<path d="M3 8.5L6.5 12L13 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
						</svg>
						Uptime monitoring, so we know before your customers do
					</li>
					<li>
						<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
							<path d="M3 8.5L6.5 12L13 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
						</svg>
						Regular backups
					</li>
					<li>
						<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
							<path d="M3 8.5L6.5 12L13 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
						</svg>
						Security monitoring for common vulnerabilities
					</li>
					<li>
						<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
							<path d="M3 8.5L6.5 12L13 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
						</svg>
						Priority support if something needs attention
					</li>
				</ul>
				<div class="wp-block-buttons">
					<div class="wp-block-button is-style-accent yp-maintenance-modal__cta">
						<a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $maintenance_url ); ?>">
							<?php echo esc_html( $maintenance_payment_link ? 'Subscribe to Maintenance' : 'Ask About Maintenance' ); ?>
						</a>
					</div>
				</div>
			</div>
		</div>
	</div>
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
					<a class="wp-block-button__link wp-element-button yp-web-design-package__cta" href="/web-design-quote/">Get a Quote</a>
				</div>
			<?php endforeach; ?>
		</div>
		<!-- /wp:html -->

		<!-- wp:paragraph {"align":"center","className":"yp-web-design-packages__disclaimer"} -->
		<p class="has-text-align-center yp-web-design-packages__disclaimer">Packages cover design and build — hosting and domain registration aren't included and are billed separately, either through your own provider or our hosting add-on above.</p>
		<!-- /wp:paragraph -->
	<?php else : ?>
		<!-- wp:paragraph {"align":"center"} -->
		<p class="has-text-align-center">Packages coming soon — <a href="/web-design-quote/">contact us</a> in the meantime.</p>
		<!-- /wp:paragraph -->
	<?php endif; ?>

</section>
<!-- /wp:group -->
