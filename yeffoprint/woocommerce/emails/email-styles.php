<?php
/**
 * Email styles — theme override of woocommerce/templates/emails/email-styles.php.
 *
 * WooCommerce's own version of this file sources every color from
 * Settings → Emails (get_option( 'woocommerce_email_base_color' ) etc.),
 * so a store owner can recolor emails without touching code. This site
 * intentionally opts out of that — brand colors are a fixed design
 * decision (docs/ARCHITECTURE.md / theme.json palette), not a per-store
 * setting, so they're hardcoded below instead of read from options. That
 * also means the "email preview" transient-swap logic WC's original file
 * has (Settings → Emails → Preview) is dropped: there's nothing for a
 * preview to swap.
 *
 * Selectors mostly match WC core's own IDs/classes on purpose — those are
 * what emails/email-header.php, emails/email-footer.php, and every
 * emails/*-order.php content template actually render, across both of
 * WC's "email improvements" markup generations (Settings-flag dependent,
 * out of this theme's control) — styling both generations' selectors
 * means this doesn't break if that flag's default ever changes upstream.
 *
 * @see https://woocommerce.com/document/template-structure/
 */

defined( 'ABSPATH' ) || exit;

// ---- Brand tokens (theme.json palette) ----
$bg          = '#FAF9F6'; // warm-white — the page area around the card.
$body        = '#FFFFFF'; // white — the content card itself.
$band        = '#141414'; // near-black — header/footer bands.
$band_text   = '#FAF9F6'; // warm-white — text sitting on the bands.
$text        = '#141414'; // near-black — primary body text.
$text_muted  = '#3A3A3C'; // charcoal — secondary/meta text.
$border      = '#E7E5E1'; // light-gray — table/card borders.
$link_color  = '#C2007A'; // magenta-deep — the "text-safe" accent (theme.json), used for links.
$radius      = '16px';    // rounded corners on the header/card/footer stack.

// Most inboxes strip @font-face/custom web fonts entirely (Outlook never
// loads them at all), so — unlike the rest of the site — this can't lean
// on Geist/Inter. Helvetica/Arial is the closest safe system match to the
// site's own sans-serif, and is what will actually render.
$font_family = "'Helvetica Neue', Helvetica, Arial, sans-serif";
?>
body {
	background-color: <?php echo esc_attr( $bg ); ?>;
	padding: 0;
	text-align: center;
}

#outer_wrapper {
	background-color: <?php echo esc_attr( $bg ); ?>;
}

#inner_wrapper {
	background-color: transparent;
}

#wrapper {
	margin: 0 auto;
	padding: 32px 0;
	-webkit-text-size-adjust: none !important;
	width: 100%;
	max-width: 600px;
}

/* ---- Header band: the wordmark/CMY-stripe strip injected by email-header.php ---- */
#template_header_image {
	background-color: <?php echo esc_attr( $band ); ?>;
	border-radius: <?php echo esc_attr( $radius ); ?> <?php echo esc_attr( $radius ); ?> 0 0;
	padding: 0 !important;
	text-align: center;
}

.yp-email-stripe td {
	height: 6px;
	line-height: 6px;
	font-size: 0;
}

.yp-email-stripe td:first-child {
	border-radius: <?php echo esc_attr( $radius ); ?> 0 0 0;
}

.yp-email-stripe td:last-child {
	border-radius: 0 <?php echo esc_attr( $radius ); ?> 0 0;
}

.yp-email-wordmark {
	padding: 20px 24px;
}

.yp-email-wordmark .yp-dot {
	display: inline-block;
	width: 7px;
	height: 7px;
	border-radius: 50%;
	margin: 0 2px;
}

.yp-email-wordmark .yp-word {
	color: <?php echo esc_attr( $band_text ); ?>;
	font-family: <?php echo $font_family; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	font-size: 17px;
	font-weight: 700;
	letter-spacing: .02em;
	margin-left: 8px;
}

/* ---- Card: the white content area ---- */
#template_container {
	background-color: <?php echo esc_attr( $body ); ?>;
	border: 0;
	border-radius: 0 !important;
	box-shadow: none;
}

#template_header {
	background-color: <?php echo esc_attr( $body ); ?>;
	border-radius: 0 !important;
	border-bottom: 0;
	color: <?php echo esc_attr( $text ); ?>;
}

#header_wrapper {
	padding: 32px 32px 0;
	display: block;
}

#template_header h1,
#template_header h1 a,
h1 {
	color: <?php echo esc_attr( $text ); ?>;
	font-family: <?php echo $font_family; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	font-size: 22px;
	font-weight: 700;
	line-height: 130%;
	margin: 0;
	text-align: <?php echo is_rtl() ? 'right' : 'left'; ?>;
}

h2 {
	color: <?php echo esc_attr( $text ); ?>;
	font-family: <?php echo $font_family; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	font-size: 18px;
	font-weight: bold;
	line-height: 130%;
	margin: 0 0 16px;
	text-align: <?php echo is_rtl() ? 'right' : 'left'; ?>;
}

h3 {
	color: <?php echo esc_attr( $text ); ?>;
	font-family: <?php echo $font_family; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	font-size: 15px;
	font-weight: bold;
	line-height: 130%;
	margin: 16px 0 8px;
	text-align: <?php echo is_rtl() ? 'right' : 'left'; ?>;
}

.email-introduction {
	padding-bottom: 20px;
}

#body_content {
	background-color: <?php echo esc_attr( $body ); ?>;
}

#body_content table td {
	padding: 8px 32px 32px;
}

#body_content table td td {
	padding: 12px;
}

#body_content table td th {
	padding: 12px;
}

#body_content table .email-order-details td,
#body_content table .email-order-details th {
	padding: 10px 12px;
}

#body_content table .email-order-details td:first-child,
#body_content table .email-order-details th:first-child {
	padding-<?php echo is_rtl() ? 'right' : 'left'; ?>: 0;
}

#body_content table .email-order-details td:last-child,
#body_content table .email-order-details th:last-child {
	padding-<?php echo is_rtl() ? 'left' : 'right'; ?>: 0;
}

#body_content .email-order-details tbody tr:last-child td {
	border-bottom: 1px solid <?php echo esc_attr( $border ); ?>;
	padding-bottom: 20px;
}

#body_content .email-order-details tfoot tr:first-child td,
#body_content .email-order-details tfoot tr:first-child th {
	padding-top: 20px;
}

#body_content .order-item-data td {
	border: 0 !important;
	padding: 0 !important;
	vertical-align: middle;
}

#body_content .email-order-details .order-totals td,
#body_content .email-order-details .order-totals th {
	font-weight: normal;
	padding-bottom: 5px;
	padding-top: 5px;
}

#body_content .email-order-details .order-totals-total th,
#body_content .email-order-details .order-totals-total td {
	font-weight: bold;
}

#body_content .email-order-details .order-totals-total td {
	font-size: 17px;
}

#body_content .email-order-details .order-totals-last td,
#body_content .email-order-details .order-totals-last th {
	border-bottom: 1px solid <?php echo esc_attr( $border ); ?>;
	padding-bottom: 20px;
}

#body_content .email-order-details .order-customer-note td {
	border-bottom: 1px solid <?php echo esc_attr( $border ); ?>;
	padding-bottom: 20px;
	padding-top: 20px;
}

#body_content td ul.wc-item-meta {
	font-size: small;
	margin: 1em 0 0;
	padding: 0;
	list-style: none;
	color: <?php echo esc_attr( $text_muted ); ?>;
}

#body_content td ul.wc-item-meta li {
	margin: .5em 0 0;
	padding: 0;
}

#body_content td ul.wc-item-meta li p {
	margin: 0;
}

#body_content .email-order-details .wc-item-meta-label {
	clear: both;
	float: <?php echo is_rtl() ? 'right' : 'left'; ?>;
	font-weight: normal;
	margin-<?php echo is_rtl() ? 'left' : 'right'; ?>: .25em;
}

#body_content p {
	margin: 0 0 16px;
}

#body_content_inner {
	color: <?php echo esc_attr( $text_muted ); ?>;
	font-family: <?php echo $font_family; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	font-size: 14.5px;
	line-height: 150%;
	text-align: <?php echo is_rtl() ? 'right' : 'left'; ?>;
}

.td {
	color: <?php echo esc_attr( $text_muted ); ?>;
	border: 0;
	border-bottom: 1px solid <?php echo esc_attr( $border ); ?>;
	vertical-align: middle;
}

.address {
	color: <?php echo esc_attr( $text ); ?>;
	font-style: normal;
	line-height: 140%;
	padding: 8px 0;
	word-break: break-all;
}

#addresses td + td {
	padding-<?php echo is_rtl() ? 'right' : 'left'; ?>: 10px !important;
}

.text,
.address-title,
.order-item-data {
	color: <?php echo esc_attr( $text ); ?>;
	font-family: <?php echo $font_family; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
}

.order-item-data {
	width: 100%;
}

.order-item-data h3 {
	margin: 0;
}

.link,
a {
	color: <?php echo esc_attr( $link_color ); ?>;
	font-weight: 600;
	text-decoration: none;
}

img {
	border: none;
	display: inline-block;
	height: auto;
	outline: none;
	text-decoration: none;
	vertical-align: middle;
	max-width: 100%;
}

/* Reorder link — yeffoprint-core's class-reorder.php prints this class directly; a small, quiet secondary action under each line item. */
p.yp-reorder-link {
	margin: 6px 0 0;
}

p.yp-reorder-link a {
	font-size: 12.5px;
	font-weight: 600;
	color: #0078A4; /* cyan-deep — a second text-safe accent, so it reads distinct from the magenta-deep primary link color. */
}

/* "Track your order" button (class-order-tracking.php) — also reused
   as-is for the "Pay with Venmo" button (class-manual-payment-
   gateway.php's payment_action_html()), same "one clear action" look. */
a.yp-email-button {
	display: inline-block;
	background-color: <?php echo esc_attr( $band ); ?>;
	color: <?php echo esc_attr( $band_text ); ?> !important;
	font-weight: 600;
	font-size: 13.5px;
	text-decoration: none;
	padding: 12px 22px;
	border-radius: 6px;
	letter-spacing: .01em;
}

/* QR code alongside the Venmo button above — scanning it opens the
   exact same link. */
img.yp-payment-qr {
	display: block;
	margin-top: 4px;
	border: 1px solid <?php echo esc_attr( $border ); ?>;
	border-radius: 8px;
	padding: 6px;
	background-color: #FFFFFF;
}

p.yp-payment-qr-caption {
	font-size: 12px;
	color: <?php echo esc_attr( $text_muted ); ?>;
	margin: 6px 0 0;
}

/* Payment-instructions callout (class-manual-payment-gateway.php's email_instructions()). */
table.yp-email-callout {
	margin: 0 0 22px;
}

table.yp-email-callout td {
	background-color: #FDF1F8;
	border: 1px solid #F3C7E1;
	border-radius: 8px;
	padding: 16px 18px;
	text-align: <?php echo is_rtl() ? 'right' : 'left'; ?>;
}

.yp-email-callout-label {
	display: block;
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: .06em;
	color: <?php echo esc_attr( $link_color ); ?>;
	font-weight: 700;
	margin-bottom: 6px;
}

table.yp-email-callout p {
	margin: 0;
	font-size: 14px;
	color: <?php echo esc_attr( $text ); ?>;
}

/* Payment CTA card (customer-invoice.php) — direct request: "can you
   make the payment link more obvious?" Replaces a bare inline link
   mid-paragraph with a dedicated card stating the amount due and a
   button in the site's accent color, so payment is the one thing on the
   email nothing else competes with for attention. */
table.yp-payment-cta {
	margin: 2px 0 22px;
}

table.yp-payment-cta > tbody > tr > td {
	background-color: #FDF1F8;
	border: 1px solid #F3C7E1;
	border-radius: 10px;
	padding: 20px;
	text-align: center;
}

.yp-payment-cta-label {
	display: block;
	font-size: 10.5px;
	font-weight: 700;
	letter-spacing: .08em;
	text-transform: uppercase;
	color: <?php echo esc_attr( $text_muted ); ?>;
	margin: 0 0 6px;
}

.yp-payment-cta-amount {
	display: block;
	font-size: 28px;
	font-weight: 700;
	color: <?php echo esc_attr( $text ); ?>;
	font-family: <?php echo $font_family; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	margin: 0 0 14px;
}

a.yp-payment-cta-button {
	display: inline-block;
	background-color: <?php echo esc_attr( $link_color ); ?>;
	color: #FFFFFF !important;
	font-weight: 700;
	font-size: 15px;
	text-decoration: none;
	padding: 13px 30px;
	border-radius: 8px;
	letter-spacing: .01em;
}

.yp-payment-cta-sub {
	display: block;
	font-size: 11.5px;
	color: <?php echo esc_attr( $text_muted ); ?>;
	margin: 12px 0 0;
}

/* YeffoBot notice card (class-telegram-order-email-badge.php) — bigger
   than .yp-email-callout above since it carries the mascot image
   alongside two explained ways to reach the bot. Direct feedback on an
   earlier draft: "it doesn't really explain what the links do, just
   says telegram and web chat" — each option below is its own full-width
   row with a bold action line plus a caption stating what happens. */
table.yp-bot-callout {
	margin: 0 0 22px;
}

table.yp-bot-callout > tbody > tr > td {
	background-color: #FDF1F8;
	border: 1px solid #F3C7E1;
	border-radius: 12px;
	padding: 18px;
	text-align: <?php echo is_rtl() ? 'right' : 'left'; ?>;
}

.yp-bot-callout-head td {
	padding: 0;
}

.yp-bot-callout-head img {
	display: block;
}

.yp-bot-callout-eyebrow {
	display: block;
	font-size: 10.5px;
	text-transform: uppercase;
	letter-spacing: .06em;
	color: <?php echo esc_attr( $link_color ); ?>;
	font-weight: 700;
	margin: 0 0 2px;
}

.yp-bot-callout-title {
	display: block;
	font-size: 15px;
	font-weight: 700;
	color: <?php echo esc_attr( $text ); ?>;
	font-family: <?php echo $font_family; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
}

.yp-bot-callout-intro {
	font-size: 12px;
	color: <?php echo esc_attr( $text_muted ); ?>;
	margin: 12px 0 10px;
	line-height: 145%;
}

table.yp-bot-option {
	margin: 8px 0 0;
}

table.yp-bot-option td {
	background-color: #FFFFFF;
	border: 1px solid <?php echo esc_attr( $border ); ?>;
	border-radius: 9px;
	padding: 10px 12px;
}

table.yp-bot-option a {
	display: block;
	text-decoration: none;
}

.yp-bot-option-title {
	display: block;
	font-size: 12.5px;
	font-weight: 700;
	color: <?php echo esc_attr( $text ); ?>;
}

.yp-bot-option-sub {
	display: block;
	font-size: 11px;
	font-weight: normal;
	color: <?php echo esc_attr( $text_muted ); ?>;
	margin-top: 1px;
}

/* Per-label customization detail (class-order-item-meta.php's
   render_customization_email_fields()) — one box per "Customization"/
   "Label N (qty M)" variant, replacing the dense joined-string row WC's
   own item-meta rendering would otherwise show. Same visual language as
   .yp-email-callout above, just a neutral warm-white box instead of pink
   so it doesn't compete with the payment-instructions callout. */
table.yp-email-fields {
	margin: 4px 0 18px;
}

table.yp-email-fields td.yp-email-fields-box {
	background-color: <?php echo esc_attr( $bg ); ?>;
	border: 1px solid <?php echo esc_attr( $border ); ?>;
	border-radius: 8px;
	padding: 14px 16px;
	text-align: <?php echo is_rtl() ? 'right' : 'left'; ?>;
}

table.yp-email-fields tr + tr td.yp-email-fields-box {
	margin-top: 10px;
}

.yp-email-fields-heading {
	display: block;
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: .06em;
	color: <?php echo esc_attr( $link_color ); ?>;
	font-weight: 700;
	margin-bottom: 8px;
}

table.yp-email-fields-rows td {
	padding: 3px 0;
	font-size: 13.5px;
	line-height: 140%;
	vertical-align: top;
}

table.yp-email-fields-rows .yp-email-field-label {
	color: <?php echo esc_attr( $text_muted ); ?>;
	padding-<?php echo is_rtl() ? 'left' : 'right'; ?>: 12px;
	white-space: nowrap;
	width: 1%;
}

table.yp-email-fields-rows .yp-email-field-value {
	color: <?php echo esc_attr( $text ); ?>;
	font-weight: 600;
}

/* A color-picker field's own swatch, next to its hex value — same
   class-plus-inline-background-color technique as the header wordmark's
   own .yp-dot spans, since the color itself is per-order data and can't
   live in this static stylesheet. */
.yp-email-color-swatch {
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 3px;
	border: 1px solid <?php echo esc_attr( $border ); ?>;
	vertical-align: middle;
	margin-right: 5px;
}

/* ---- Footer band ---- */
#template_footer td {
	padding: 0;
	border-radius: 0;
}

#template_footer #credit {
	background-color: <?php echo esc_attr( $band ); ?>;
	border: 0;
	border-radius: 0 0 <?php echo esc_attr( $radius ); ?> <?php echo esc_attr( $radius ); ?>;
	color: #C7C4BE;
	font-family: <?php echo $font_family; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	font-size: 12px;
	line-height: 150%;
	text-align: center;
	padding: 28px 32px;
}

#template_footer #credit a {
	color: <?php echo esc_attr( $band_text ); ?>;
	font-weight: 600;
	margin: 0 6px;
}

#template_footer #credit p {
	margin: 0 0 10px;
}

#template_footer #credit p:last-child {
	margin-bottom: 0;
	color: #6E6C68;
}

/**
 * Media queries aren't supported by every client, but they help on
 * mobile Gmail/Apple Mail, which do support them.
 */
@media screen and (max-width: 600px) {
	.yp-email-wordmark {
		padding: 16px !important;
	}

	#header_wrapper {
		padding: 22px 20px 0 !important;
	}

	#template_header h1,
	h1 {
		font-size: 19px !important;
	}

	#body_content table td {
		padding: 6px 20px 24px !important;
	}

	#body_content_inner {
		font-size: 13px !important;
	}

	#template_footer #credit {
		padding: 22px 20px !important;
	}
}
