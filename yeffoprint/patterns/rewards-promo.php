<?php
/**
 * Title: Rewards Promo
 * Slug: yeffoprint/rewards-promo
 * Categories: yeffoprint
 *
 * Navy press-proof panel with live earn rate from the rewards engine.
 */

defined( 'ABSPATH' ) || exit;

$points_per_dollar = function_exists( 'yeffoprint_core_rewards_points_per_dollar_label' )
	? yeffoprint_core_rewards_points_per_dollar_label()
	: '1';
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section">

	<!-- wp:html -->
	<div class="yp-rewards-promo yp-rewards-promo--panel">
		<div class="yp-rewards-promo__copy">
			<span class="yp-rewards-promo__cmy" aria-hidden="true"><i></i><i></i><i></i></span>
			<p class="yp-eyebrow"><?php esc_html_e( 'Rewards', 'yeffoprint' ); ?></p>
			<h2 class="yp-rewards-promo__title"><?php esc_html_e( 'YeffoDesign Rewards', 'yeffoprint' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: points earned per dollar */
					esc_html__( 'Earn %s point(s) for every $1 you spend, automatically — redeem your balance for a discount whenever you’re ready.', 'yeffoprint' ),
					esc_html( $points_per_dollar )
				);
				?>
			</p>
			<p class="yp-rewards-promo__actions">
				<a class="wp-block-button__link is-style-outline yp-rewards-promo__cta" href="<?php echo esc_url( home_url( '/my-account/rewards/' ) ); ?>"><?php esc_html_e( 'View My Rewards', 'yeffoprint' ); ?></a>
			</p>
		</div>
		<div class="yp-rewards-promo__stats">
			<div class="yp-rewards-promo__stat">
				<strong><?php echo esc_html( $points_per_dollar ); ?>×</strong>
				<span><?php esc_html_e( 'points per dollar', 'yeffoprint' ); ?></span>
			</div>
			<div class="yp-rewards-promo__stat">
				<strong>$1</strong>
				<span><?php esc_html_e( 'redeem anytime at checkout', 'yeffoprint' ); ?></span>
			</div>
		</div>
	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
