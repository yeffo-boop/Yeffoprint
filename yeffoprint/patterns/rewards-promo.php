<?php
/**
 * Title: Rewards Promo
 * Slug: yeffoprint/rewards-promo
 * Categories: yeffoprint
 *
 * Real as of this pass (includes/rewards/class-rewards.php) — the
 * earn rate below is pulled live from the same admin-configurable
 * setting the points engine itself reads (Dashboard → YeffoPrint →
 * Settings), never hardcoded here, so this promo can't drift out of
 * sync with what actually happens at checkout.
 */

defined( 'ABSPATH' ) || exit;

$points_per_dollar = function_exists( 'yeffoprint_core_rewards_points_per_dollar_label' )
	? yeffoprint_core_rewards_points_per_dollar_label()
	: '1';
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section">

	<!-- wp:group {"className":"yp-rewards-promo","layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap"}} -->
	<div class="wp-block-group yp-rewards-promo">

		<!-- wp:group {"layout":{"type":"constrained"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"className":"yp-eyebrow"} -->
			<p class="yp-eyebrow">Rewards</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":2,"fontSize":"x-large"} -->
			<h2 class="wp-block-heading has-x-large-font-size">YeffoPrint Rewards</h2>
			<!-- /wp:heading -->
			<!-- wp:paragraph -->
			<p>Earn <?php echo esc_html( $points_per_dollar ); ?> point(s) for every $1 you spend, automatically — redeem your balance for a discount whenever you're ready. Create an account to start earning.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:buttons -->
		<div class="wp-block-buttons">
			<!-- wp:button {"className":"is-style-outline"} -->
			<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/my-account/rewards/">View My Rewards</a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:buttons -->

	</div>
	<!-- /wp:group -->

</section>
<!-- /wp:group -->
