<?php
/**
 * Title: Web Design Maintenance Teaser
 * Slug: yeffoprint/web-design-maintenance-teaser
 * Categories: yeffoprint
 *
 * Direct request: a recurring subscription "to keep the website up to
 * date and monitor for issues." This section only introduces it — the
 * actual purchase happens via a Stripe Payment Link (a separate, direct
 * Stripe connection, not routed through this store's own WooCommerce
 * checkout; see docs/ARCHITECTURE.md for why). The button below reads
 * that link straight from the 'yeffoprint_maintenance_payment_link'
 * option (set on the Settings page once that Stripe setup is done),
 * falling back to /contact/ when it's still empty — so this page always
 * has a working button, even before the subscription is fully wired up.
 */

defined( 'ABSPATH' ) || exit;

$payment_link = get_option( 'yeffoprint_maintenance_payment_link', '' );
$button_url   = $payment_link ? $payment_link : home_url( '/contact/' );
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"680px"}} -->
<section class="wp-block-group yp-section">

	<!-- wp:group {"className":"yp-maintenance-teaser","layout":{"type":"default"}} -->
	<div class="wp-block-group yp-maintenance-teaser">

		<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
		<p class="has-text-align-center yp-eyebrow">Keep It Running</p>
		<!-- /wp:paragraph -->

		<!-- wp:heading {"textAlign":"center","level":2} -->
		<h2 class="wp-block-heading has-text-align-center">Ongoing Maintenance &amp; Monitoring</h2>
		<!-- /wp:heading -->

		<!-- wp:paragraph {"align":"center"} -->
		<p class="has-text-align-center">A launched site still needs attention — plugin and core updates, and someone watching for issues before your customers find them. Our maintenance plan keeps your store current and monitored, month to month.</p>
		<!-- /wp:paragraph -->

		<!-- wp:html -->
		<div class="wp-block-buttons" style="justify-content:center">
			<div class="wp-block-button is-style-accent">
				<a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $button_url ); ?>">Subscribe to Maintenance</a>
			</div>
		</div>
		<!-- /wp:html -->

	</div>
	<!-- /wp:group -->

</section>
<!-- /wp:group -->
