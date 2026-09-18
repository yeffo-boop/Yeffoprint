<?php
/**
 * Title: Web Design Packages
 * Slug: yeffoprint/web-design-packages
 * Categories: yeffoprint
 *
 * Direct request: describe web design packages "from design to
 * execution." Confirmed with the site owner: packages are sold via a
 * quote conversation by default (scope varies too much per client for
 * a fixed price) — so every card's CTA goes to a quote form. When an
 * admin sets a Checkout Price (and the linked WC product syncs), the
 * card swaps to an Order Now button that creates a pending order via
 * class-web-design-order-controller.php and sends the customer to
 * WooCommerce's pay link — same path Manual Order Creator uses.
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
 * through data-yp-drawer-trigger/-close — no new JS.
 *
 * Both badges (Maintenance, Hosting) are now real, admin-editable
 * yp_web_design_addon records (direct follow-up: "remember the add-ons
 * we offer. I'd like to be able to add/edit available add-on options
 * that can be added to web design orders") instead of hardcoded HTML —
 * see class-web-design-addon-meta.php. This loop renders however many
 * are published, in the admin's own drag-order, so adding a third
 * add-on later needs no code change at all. `icon_svg()` below maps
 * each record's own ICON_CHOICES slug to real, hardcoded inline SVG —
 * an admin-editable field never renders as raw markup on this page.
 *
 * Every "Get a Quote" link on this page (the intro paragraph, each
 * package card) points at the `/web-design-quote/` intake form
 * (class-web-design-quote-controller.php) instead of the generic
 * `/contact/` form — direct request for a richer intake than
 * name/email/message. Each add-on's own CTA does the same whenever it
 * has no payment link of its own set (YeffoPrint_Web_Design_Addon_Meta::CTA_URL).
 */

defined( 'ABSPATH' ) || exit;

// A plain function, not a const array, for the same reason $placeholder_price
// below is a local variable: this pattern file can run more than once per
// request (every page that includes it), and a bare function declaration
// would fatal on the second inclusion without this guard.
if ( ! function_exists( 'yeffoprint_web_design_addon_icon_svg' ) ) {
	/** Hardcoded server-side, keyed by YeffoPrint_Web_Design_Addon_Meta::ICON_CHOICES — never raw markup from the admin field itself. */
	function yeffoprint_web_design_addon_icon_svg( string $icon ): string {
		$icons = [
			'wrench' => '<path d="M12.5 3.5a4 4 0 0 0-5.4 4.9L2.5 13a1.8 1.8 0 0 0 2.5 2.5l4.6-4.6a4 4 0 0 0 4.9-5.4l-2.6 2.6-2-2 2.6-2.6z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" />',
			'globe'  => '<circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.6" /><ellipse cx="10" cy="10" rx="3" ry="7.5" stroke="currentColor" stroke-width="1.6" /><line x1="2.5" y1="10" x2="17.5" y2="10" stroke="currentColor" stroke-width="1.6" />',
			'shield' => '<path d="M10 2.5l6 2.2v4.6c0 4-2.6 6.9-6 8.2-3.4-1.3-6-4.2-6-8.2V4.7l6-2.2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />',
			'clock'  => '<circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.6" /><path d="M10 5.5V10l3.2 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />',
			'tag'    => '<path d="M11 3H4.5A1.5 1.5 0 0 0 3 4.5V11l7.3 7.3a1.5 1.5 0 0 0 2.1 0l5.9-5.9a1.5 1.5 0 0 0 0-2.1L11 3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" /><circle cx="7.3" cy="7.3" r="1.1" fill="currentColor" />',
			'star'   => '<path d="M10 2.5l2.2 4.9 5.3.6-4 3.7 1.1 5.3L10 14.3l-4.6 2.7 1.1-5.3-4-3.7 5.3-.6L10 2.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" />',
		];

		return $icons[ $icon ] ?? $icons['tag'];
	}
}

$addons = array_map( static function ( $post ) {
	return [
		'id'            => $post->ID,
		'name'          => get_the_title( $post ),
		'price'         => (string) get_post_meta( $post->ID, YeffoPrint_Web_Design_Addon_Meta::PRICE, true ),
		'badge_text'    => (string) get_post_meta( $post->ID, YeffoPrint_Web_Design_Addon_Meta::BADGE_TEXT, true ),
		'modal_heading' => (string) get_post_meta( $post->ID, YeffoPrint_Web_Design_Addon_Meta::MODAL_HEADING, true ),
		'modal_body'    => (string) get_post_meta( $post->ID, YeffoPrint_Web_Design_Addon_Meta::MODAL_BODY, true ),
		'features'      => (array) get_post_meta( $post->ID, YeffoPrint_Web_Design_Addon_Meta::FEATURES, true ),
		'cta_label'     => (string) get_post_meta( $post->ID, YeffoPrint_Web_Design_Addon_Meta::CTA_LABEL, true ),
		'cta_url'       => (string) get_post_meta( $post->ID, YeffoPrint_Web_Design_Addon_Meta::CTA_URL, true ) ?: home_url( '/web-design-quote/' ),
		'icon'          => (string) get_post_meta( $post->ID, YeffoPrint_Web_Design_Addon_Meta::ICON, true ),
	];
}, YeffoPrint_Web_Design_Addon_Meta::get_published() );

// The seed command's own starting value — still exactly this means the
// owner hasn't edited this tier's price yet. A local variable, not a
// top-level const: this file can run more than once per request (every
// page that includes this pattern), and a const would fatal the second
// time.
$placeholder_price = '$X,XXX';

$packages = array_map( static function ( $post ) {
	$checkout_price = (float) get_post_meta( $post->ID, YeffoPrint_Web_Design_Package_Meta::CHECKOUT_PRICE, true );
	$product_id     = (int) get_post_meta( $post->ID, YeffoPrint_Web_Design_Package_Product::META_LINKED_PRODUCT, true );

	return [
		'id'             => $post->ID,
		'name'           => get_the_title( $post ),
		'price'          => (string) get_post_meta( $post->ID, YeffoPrint_Web_Design_Package_Meta::PRICE, true ),
		'tagline'        => (string) get_post_meta( $post->ID, YeffoPrint_Web_Design_Package_Meta::TAGLINE, true ),
		'features'       => (array) get_post_meta( $post->ID, YeffoPrint_Web_Design_Package_Meta::FEATURES, true ),
		'featured'       => (bool) get_post_meta( $post->ID, YeffoPrint_Web_Design_Package_Meta::FEATURED, true ),
		// Self-serve pay when Checkout Price is set and the linked WC
		// product exists (class-web-design-order-controller.php).
		'orderable'      => $checkout_price > 0 && $product_id > 0,
		'checkout_price' => $checkout_price,
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
	<p class="has-text-align-center">Every project is scoped to what you're actually building — these are starting points, not a fixed menu. Packages with a set Checkout Price can be paid for immediately; everything else starts with a quote.</p>
	<!-- /wp:paragraph -->

	<!-- wp:html -->
	<ol class="yp-web-design-steps" aria-label="What happens next">
		<li><strong>Pick a package</strong> — Order Now if the price is set, or request a custom quote.</li>
		<li><strong>Pay or talk</strong> — Checkout for fixed-price tiers; a short intake form for custom scope.</li>
		<li><strong>We build &amp; launch</strong> — Design through handoff, with optional maintenance after.</li>
	</ol>
	<!-- /wp:html -->

	<?php if ( $addons ) : ?>
		<!-- wp:html -->
		<div class="yp-web-design-badge-row">
			<?php foreach ( $addons as $addon ) : ?>
				<button type="button" class="yp-web-design-maintenance-badge" data-yp-drawer-trigger="yp-addon-modal-<?php echo (int) $addon['id']; ?>" aria-haspopup="dialog">
					<svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
						<?php echo yeffoprint_web_design_addon_icon_svg( $addon['icon'] ); ?>
					</svg>
					<span><?php echo esc_html( $addon['badge_text'] ); ?></span>
					<svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
						<path d="M6 3L11 8L6 13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
					</svg>
				</button>
			<?php endforeach; ?>
		</div>
		<!-- /wp:html -->

		<?php foreach ( $addons as $addon ) : ?>
			<!-- wp:html -->
			<div id="yp-addon-modal-<?php echo (int) $addon['id']; ?>" class="yp-drawer yp-drawer--center" aria-hidden="true">
				<div class="yp-drawer__backdrop"></div>
				<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="yp-addon-modal-<?php echo (int) $addon['id']; ?>-heading">
					<div class="yp-drawer__header">
						<span id="yp-addon-modal-<?php echo (int) $addon['id']; ?>-heading"><?php echo esc_html( $addon['modal_heading'] ); ?></span>
						<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">
							<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
								<line x1="2" y1="2" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
								<line x1="14" y1="2" x2="2" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
							</svg>
						</button>
					</div>
					<div class="yp-drawer__body">
						<p><?php echo esc_html( $addon['modal_body'] ); ?></p>
						<?php if ( $addon['features'] ) : ?>
							<ul class="yp-web-design-package__features">
								<?php foreach ( $addon['features'] as $feature ) : ?>
									<li>
										<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
											<path d="M3 8.5L6.5 12L13 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
										</svg>
										<?php echo esc_html( $feature ); ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<div class="wp-block-buttons">
							<div class="wp-block-button is-style-accent yp-maintenance-modal__cta">
								<a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $addon['cta_url'] ); ?>"><?php echo esc_html( $addon['cta_label'] ); ?></a>
							</div>
						</div>
					</div>
				</div>
			</div>
			<!-- /wp:html -->
		<?php endforeach; ?>
	<?php endif; ?>

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
					<?php if ( ! empty( $package['orderable'] ) ) : ?>
						<button
							type="button"
							class="wp-block-button__link wp-element-button yp-web-design-package__cta"
							data-yp-drawer-trigger="yp-wd-order-modal"
							data-yp-wd-order
							data-package-id="<?php echo esc_attr( (string) $package['id'] ); ?>"
							data-package-name="<?php echo esc_attr( $package['name'] ); ?>"
						><?php esc_html_e( 'Order Now — pay this price', 'yeffoprint' ); ?></button>
						<p class="yp-web-design-package__cta-note"><?php esc_html_e( 'Pay the listed price and we’ll get started.', 'yeffoprint' ); ?></p>
						<a class="yp-web-design-package__quote-link" href="<?php echo esc_url( home_url( '/web-design-quote/' ) ); ?>"><?php esc_html_e( 'Need different scope? Get a quote', 'yeffoprint' ); ?></a>
					<?php else : ?>
						<a class="wp-block-button__link wp-element-button yp-web-design-package__cta" href="<?php echo esc_url( home_url( '/web-design-quote/' ) ); ?>"><?php esc_html_e( 'Get a Quote', 'yeffoprint' ); ?></a>
						<p class="yp-web-design-package__cta-note"><?php esc_html_e( 'Scoped per project — we’ll send a real proposal.', 'yeffoprint' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<div id="yp-wd-order-modal" class="yp-drawer yp-drawer--center" aria-hidden="true" data-yp-wd-order-modal>
			<div class="yp-drawer__backdrop"></div>
			<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="yp-wd-order-heading">
				<div class="yp-drawer__header">
					<span id="yp-wd-order-heading"><?php esc_html_e( 'Order this package', 'yeffoprint' ); ?></span>
					<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="<?php esc_attr_e( 'Close', 'yeffoprint' ); ?>">
						<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
							<line x1="2" y1="2" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
							<line x1="14" y1="2" x2="2" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
						</svg>
					</button>
				</div>
				<div class="yp-drawer__body">
					<p data-yp-wd-order-package-label></p>
					<form id="yp-wd-order-form" class="yp-wd-order-form">
						<input type="hidden" name="package_id" value="" data-yp-wd-order-package-id />
						<div class="yp-field yp-field--honeypot" aria-hidden="true">
							<label for="yp-wd-order-website"><?php esc_html_e( 'Website', 'yeffoprint' ); ?></label>
							<input type="text" id="yp-wd-order-website" name="website" value="" tabindex="-1" autocomplete="off" />
						</div>
						<div class="yp-field">
							<label for="yp-wd-order-name"><?php esc_html_e( 'Your name', 'yeffoprint' ); ?></label>
							<input type="text" id="yp-wd-order-name" name="name" required autocomplete="name" data-yp-wd-order-name />
						</div>
						<div class="yp-field">
							<label for="yp-wd-order-email"><?php esc_html_e( 'Email', 'yeffoprint' ); ?></label>
							<input type="email" id="yp-wd-order-email" name="email" required autocomplete="email" data-yp-wd-order-email />
						</div>
						<p class="yp-configurator__cart-status" data-yp-wd-order-status hidden></p>
						<button type="submit" class="wp-block-button__link wp-element-button" data-yp-wd-order-submit><?php esc_html_e( 'Continue to payment', 'yeffoprint' ); ?></button>
					</form>
				</div>
			</div>
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
