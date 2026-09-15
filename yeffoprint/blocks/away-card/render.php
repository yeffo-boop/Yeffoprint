<?php
/**
 * Homepage "we're away" announcement card — the more visible companion
 * to yeffoprint/away-bar's site-wide top strip (direct request: mocked
 * up as two concepts, and both got approved — "let's implement all of
 * them!"). Sits right under the header, above the hero, same slot
 * yeffoprint/promo-banner already uses — this is the only PHP-capable
 * spot inside the otherwise-static templates/front-page.html.
 *
 * yeffoprint_core_away_mode() (yeffoprint-core's template-api.php) is
 * the single gate for "is this actually on and configured right now" —
 * null means render nothing, same early-return idiom as
 * yeffoprint/announcement-bar and yeffoprint/promo-banner.
 */

defined( 'ABSPATH' ) || exit;

$away = function_exists( 'yeffoprint_core_away_mode' ) ? yeffoprint_core_away_mode() : null;

if ( ! $away ) {
	return;
}
?>
<section class="yp-away-card">
	<div class="yp-away-card__icon" aria-hidden="true">&#127769;</div>
	<div class="yp-away-card__text">
		<h2 class="yp-away-card__headline">
			<?php
			printf(
				/* translators: %s: the date production resumes, e.g. "March 18, 2026" */
				esc_html__( 'We’re away until %s', 'yeffoprint' ),
				esc_html( $away['return_label'] )
			);
			?>
		</h2>
		<p class="yp-away-card__body">
			<?php esc_html_e( 'Still placing orders? Go for it — everything queues up and heads to print the moment we’re back at the shop.', 'yeffoprint' ); ?>
		</p>
	</div>
</section>
