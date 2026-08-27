<?php
/**
 * Renders the homepage promo banner set from the YeffoPrint admin menu
 * (yeffoprint-core/includes/admin/class-admin-menu.php's Homepage
 * Promo section) — this is the only PHP-capable spot inside the
 * otherwise-static templates/front-page.html, same reasoning as
 * yeffoprint/announcement-bar, yeffoprint/gallery-toolbar, and
 * yeffoprint/template-card.
 *
 * Same structural device as every theme in YeffoPrint_Promo_Themes:
 * the site's own press-proof/registration-mark motif (patterns/
 * hero.php's .yp-proof), recolored per theme via CSS custom
 * properties set once on the root element below — one set of CSS
 * rules (assets/css/patterns.css) covers all 12 themes, rather than a
 * separate stylesheet block per theme.
 */

defined( 'ABSPATH' ) || exit;

if ( ! get_option( YeffoPrint_Admin_Menu::PROMO_ENABLED_OPTION ) ) {
	return;
}

$theme_slug = (string) get_option( YeffoPrint_Admin_Menu::PROMO_THEME_OPTION, YeffoPrint_Admin_Menu::PROMO_THEME_DEFAULT );
$theme      = YeffoPrint_Promo_Themes::get( $theme_slug );
$code       = trim( (string) get_option( YeffoPrint_Admin_Menu::PROMO_CODE_OPTION, '' ) );
$offer      = trim( (string) get_option( YeffoPrint_Admin_Menu::PROMO_OFFER_OPTION, YeffoPrint_Admin_Menu::PROMO_OFFER_DEFAULT ) );

// Enabled is deliberately not enough on its own — an admin flipping
// the checkbox on before filling in an offer/code would otherwise
// publish a banner reading "Ring in the New Year with %s", literally.
if ( ! $theme || '' === $code || '' === $offer ) {
	return;
}

// Every seasonal theme omits these and gets the discount-shop default
// below unchanged; a non-discount theme like `web-design-launch` sets
// both so the button says and does the right thing instead of sending
// that lead into the label shop.
$cta_label = $theme['cta_label'] ?? __( 'Shop the Sale', 'yeffoprint-core' );
$cta_url   = $theme['cta_url'] ?? home_url( '/shop-labels/' );

$style = sprintf(
	'--yp-promo-bg:%1$s;--yp-promo-glow-a:%2$s;--yp-promo-glow-b:%3$s;--yp-promo-ink:%4$s;--yp-promo-ink-soft:%5$s;--yp-promo-accent:%6$s;--yp-promo-accent-ink:%7$s;--yp-promo-bar-1:%8$s;--yp-promo-bar-2:%9$s;--yp-promo-bar-3:%10$s;--yp-promo-code-bg:%11$s;--yp-promo-code-ink:%12$s;',
	esc_attr( $theme['bg'] ),
	esc_attr( $theme['glow_a'] ),
	esc_attr( $theme['glow_b'] ),
	esc_attr( $theme['ink'] ),
	esc_attr( $theme['ink_soft'] ),
	esc_attr( $theme['accent'] ),
	esc_attr( $theme['accent_ink'] ),
	esc_attr( $theme['bars'][0] ),
	esc_attr( $theme['bars'][1] ),
	esc_attr( $theme['bars'][2] ),
	esc_attr( $theme['code_bg'] ),
	esc_attr( $theme['code_ink'] )
);
?>
<section class="yp-promo" style="<?php echo esc_attr( $style ); ?>">
	<div class="yp-promo__grid">

		<div class="yp-promo__content">
			<p class="yp-promo__eyebrow"><span class="yp-promo__dash"></span><?php echo esc_html( $theme['eyebrow'] ); ?></p>
			<h2 class="yp-promo__headline"><?php echo esc_html( sprintf( $theme['headline'], $offer ) ); ?></h2>
			<p class="yp-promo__body"><?php echo esc_html( $theme['body'] ); ?></p>
			<div class="yp-promo__row">
				<span class="yp-promo__code">
					<span class="yp-promo__code-hole"></span>
					<span class="yp-promo__code-label"><?php esc_html_e( 'Code', 'yeffoprint-core' ); ?></span>
					<span class="yp-promo__code-value"><?php echo esc_html( $code ); ?></span>
				</span>
				<a class="yp-promo__cta" href="<?php echo esc_url( $cta_url ); ?>">
					<?php echo esc_html( $cta_label ); ?> &rarr;
				</a>
			</div>
		</div>

		<div class="yp-promo__visual" aria-hidden="true">
			<div class="yp-promo__proof">
				<span class="yp-promo__corner yp-promo__corner--tl"></span>
				<span class="yp-promo__corner yp-promo__corner--tr"></span>
				<span class="yp-promo__corner yp-promo__corner--bl"></span>
				<span class="yp-promo__corner yp-promo__corner--br"></span>
				<div class="yp-promo__mark">
					<div class="yp-promo__bar yp-promo__bar--1"></div>
					<div class="yp-promo__bar yp-promo__bar--2">
						<svg class="yp-promo__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
							<?php echo $theme['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed, developer-authored SVG markup from YeffoPrint_Promo_Themes, never user input. ?>
						</svg>
					</div>
					<div class="yp-promo__bar yp-promo__bar--3"></div>
				</div>
			</div>
		</div>

	</div>
</section>
