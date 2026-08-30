<?php
/**
 * Title: Telegram Bot Promo
 * Slug: yeffoprint/telegram-bot-promo
 * Categories: yeffoprint
 *
 * Direct request: "inform people about our telegram YeffoBot... a fun
 * image of a robot that matches the sites color and style that takes
 * people to the telegram bot. A badge added to the front page." Same
 * structural shape as rewards-promo.php (eyebrow/heading/line + button
 * on one side, art on the other) so this reads as part of the same
 * homepage family rather than a bolted-on banner — only the copy and
 * illustration are new.
 *
 * The mascot: solid brand-colored shapes built directly in SVG (same
 * "no raster asset to manage" technique as web-design-hero.php's own
 * illustration) — a friendly robot whose dot eyes are the theme's own
 * CMY accent pair (cyan/magenta) and whose chest panel shows a small
 * printed label peeking out of a slot, a nod to the actual product
 * rather than a generic robot screen. Mounted on the same navy gradient
 * (--wp--custom--gradient-navy) the admin app's own nav already uses,
 * reading as a device/chat screen rather than a fresh invented color.
 *
 * The link itself (YeffoPrint_Telegram_Settings::public_url()) is null
 * until a bot @username is set in Settings → Telegram Bot — this whole
 * section stays hidden rather than ever rendering a dead link.
 */

defined( 'ABSPATH' ) || exit;

$telegram_url = class_exists( 'YeffoPrint_Telegram_Settings' ) ? YeffoPrint_Telegram_Settings::public_url() : null;
if ( ! $telegram_url ) {
	return;
}
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section">

	<!-- wp:group {"className":"yp-telegram-promo yp-section--dark","layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap"}} -->
	<div class="wp-block-group yp-telegram-promo yp-section--dark">

		<!-- wp:group {"layout":{"type":"constrained"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"className":"yp-eyebrow"} -->
			<p class="yp-eyebrow">Say hi</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":2,"fontSize":"x-large"} -->
			<h2 class="wp-block-heading has-x-large-font-size">Chat with YeffoBot on Telegram</h2>
			<!-- /wp:heading -->
			<!-- wp:paragraph -->
			<p>Order status, sizing and material questions, shipping estimates, or just a quick hello — YeffoBot answers instantly, any hour, right in Telegram.</p>
			<!-- /wp:paragraph -->
			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"yp-telegram-promo__button"} -->
				<div class="wp-block-button yp-telegram-promo__button"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $telegram_url ); ?>" target="_blank" rel="noopener">
					<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21.5 4.5L2.7 11.8c-1.2.5-1.2 1.2-.2 1.5l4.8 1.5 1.8 5.6c.2.6.4.8.9.8.4 0 .6-.2.9-.5l2.2-2.1 4.6 3.4c.8.5 1.4.2 1.6-.8l3-14c.3-1.2-.4-1.7-1.4-1.2z" fill="currentColor"/></svg>
					Chat on Telegram
				</a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->

		<!-- wp:html -->
		<div class="yp-telegram-promo__art" aria-hidden="true">
			<svg viewBox="0 0 200 220">
				<defs>
					<linearGradient id="yp-bot-grad" x1="0%" y1="0%" x2="100%" y2="100%">
						<stop offset="0%" stop-color="#EC008C"/>
						<stop offset="100%" stop-color="#00AEEF"/>
					</linearGradient>
				</defs>
				<line x1="100" y1="10" x2="100" y2="26" stroke="#FAF9F6" stroke-width="4" stroke-linecap="round"/>
				<circle cx="100" cy="8" r="7" fill="#FFF200"/>
				<rect x="60" y="28" width="80" height="62" rx="22" fill="#FFFFFF" stroke="#141414" stroke-width="4"/>
				<circle cx="52" cy="58" r="8" fill="#FFFFFF" stroke="#141414" stroke-width="4"/>
				<circle cx="148" cy="58" r="8" fill="#FFFFFF" stroke="#141414" stroke-width="4"/>
				<rect x="76" y="46" width="48" height="26" rx="13" fill="#141414"/>
				<circle cx="93" cy="59" r="5.5" fill="#00AEEF"/>
				<circle cx="107" cy="59" r="5.5" fill="#EC008C"/>
				<rect x="90" y="90" width="20" height="14" fill="#FFFFFF" stroke="#141414" stroke-width="4"/>
				<rect x="46" y="100" width="108" height="98" rx="26" fill="#FFFFFF" stroke="#141414" stroke-width="4"/>
				<rect x="70" y="122" width="60" height="42" rx="11" fill="url(#yp-bot-grad)"/>
				<g transform="translate(85,110) rotate(-8)">
					<rect x="0" y="0" width="30" height="20" rx="3" fill="#FFFFFF" stroke="#141414" stroke-width="2.5"/>
					<line x1="6" y1="7" x2="24" y2="7" stroke="#E7E5E1" stroke-width="2" stroke-linecap="round"/>
					<line x1="6" y1="12.5" x2="19" y2="12.5" stroke="#E7E5E1" stroke-width="2" stroke-linecap="round"/>
				</g>
				<path d="M46 130 q-22 -4 -26 18 q-3 18 14 22" fill="none" stroke="#141414" stroke-width="4" stroke-linecap="round"/>
				<circle cx="35" cy="169" r="9" fill="#FFFFFF" stroke="#141414" stroke-width="4"/>
				<path d="M154 118 q26 4 24 26" fill="none" stroke="#141414" stroke-width="4" stroke-linecap="round"/>
				<circle cx="178" cy="144" r="9" fill="#FFFFFF" stroke="#141414" stroke-width="4"/>
			</svg>
		</div>
		<!-- /wp:html -->

	</div>
	<!-- /wp:group -->

</section>
<!-- /wp:group -->
