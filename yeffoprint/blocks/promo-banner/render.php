<?php
/**
 * Renders the homepage promo banner(s) set from the YeffoPrint admin
 * menu (yeffoprint-core/includes/admin/class-admin-menu.php's Homepage
 * Promo section) — this is the only PHP-capable spot inside the
 * otherwise-static templates/front-page.html, same reasoning as
 * yeffoprint/announcement-bar, yeffoprint/gallery-toolbar, and
 * yeffoprint/template-card.
 *
 * Same structural device as every theme in YeffoPrint_Promo_Themes:
 * the site's own press-proof/registration-mark motif (patterns/
 * hero.php's .yp-proof), recolored per theme via CSS custom
 * properties set once per slide below — one set of CSS rules
 * (assets/css/patterns.css) covers all themes, rather than a separate
 * stylesheet block per theme.
 *
 * Direct request: "select more than one active promo banner and have
 * it slide through the active ones." With exactly one active theme
 * this renders a single bare `.yp-promo` section, identical to the
 * pre-rotation markup — no wrapper, no JS needed, nothing changes for
 * the common case. Two or more wraps them in `.yp-promo-rotator`
 * (site.js's initPromoRotator() auto-advances + adds dot nav) instead.
 */

defined( 'ABSPATH' ) || exit;

$active = YeffoPrint_Admin_Menu::active_promo_banners();

if ( ! $active ) {
	return;
}

/**
 * @param array{slug:string, theme:array, offer:string, code:string} $banner
 */
$render_promo_style = static function ( array $banner ): string {
	$theme = $banner['theme'];
	return sprintf(
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
};

$is_rotator = count( $active ) > 1;
?>
<?php if ( $is_rotator ) : ?>
<div class="yp-promo-rotator" data-yp-promo-rotator>
<?php endif; ?>

<?php foreach ( $active as $index => $banner ) :
	$theme = $banner['theme'];
	// Every seasonal theme omits these and gets the discount-shop
	// default below unchanged; a non-discount theme like
	// `web-design-launch` sets both so the button says and does the
	// right thing instead of sending that lead into the label shop.
	$cta_label = $theme['cta_label'] ?? __( 'Shop the Sale', 'yeffoprint-core' );
	$cta_url   = $theme['cta_url'] ?? home_url( '/shop-labels/' );
	?>
	<section
		class="yp-promo<?php echo $is_rotator ? ' yp-promo--slide' . ( 0 === $index ? ' is-active' : '' ) : ''; ?>"
		style="<?php echo esc_attr( $render_promo_style( $banner ) ); ?>"
		<?php if ( $is_rotator ) : ?>data-yp-promo-slide <?php echo 0 !== $index ? 'aria-hidden="true"' : ''; ?><?php endif; ?>
	>
		<div class="yp-promo__grid">

			<div class="yp-promo__content">
				<p class="yp-promo__eyebrow"><span class="yp-promo__dash"></span><?php echo esc_html( $theme['eyebrow'] ); ?></p>
				<h2 class="yp-promo__headline"><?php echo esc_html( sprintf( $theme['headline'], $banner['offer'] ) ); ?></h2>
				<p class="yp-promo__body"><?php echo esc_html( $theme['body'] ); ?></p>
				<div class="yp-promo__row">
					<span class="yp-promo__code">
						<span class="yp-promo__code-hole"></span>
						<span class="yp-promo__code-label"><?php esc_html_e( 'Code', 'yeffoprint-core' ); ?></span>
						<span class="yp-promo__code-value"><?php echo esc_html( $banner['code'] ); ?></span>
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
<?php endforeach; ?>

<?php if ( $is_rotator ) : ?>
	<div class="yp-promo-rotator__dots" data-yp-promo-dots>
		<?php foreach ( $active as $index => $banner ) : ?>
			<button
				type="button"
				class="yp-promo-rotator__dot<?php echo 0 === $index ? ' is-active' : ''; ?>"
				data-yp-promo-dot="<?php echo (int) $index; ?>"
				aria-label="<?php echo esc_attr( sprintf(
					/* translators: 1: this banner's position, 2: total active banners */
					__( 'Show banner %1$d of %2$d', 'yeffoprint-core' ),
					$index + 1,
					count( $active )
				) ); ?>"
			></button>
		<?php endforeach; ?>
	</div>
</div>
<?php endif; ?>
