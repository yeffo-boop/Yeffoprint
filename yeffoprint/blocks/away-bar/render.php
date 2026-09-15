<?php
/**
 * Site-wide "we're away" notice bar — direct request: "I'd like people
 * to know before placing their orders when I'll be resuming orders."
 * Lives in parts/announcement-bar.html, ahead of the existing
 * yeffoprint/announcement-bar block, so it's the very first thing on
 * every page — same reasoning as that block: the only PHP-capable spot
 * inside an otherwise-static template part.
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
<div class="yp-away-bar">
	<span class="yp-away-bar__icon" aria-hidden="true">&#127769;</span>
	<p class="yp-away-bar__text">
		<?php
		printf(
			/* translators: %s: the date production resumes, e.g. "March 18, 2026" */
			esc_html__( 'We’re away until %s — orders placed now will begin production when we’re back.', 'yeffoprint' ),
			esc_html( $away['return_label'] )
		);
		?>
	</p>
</div>
