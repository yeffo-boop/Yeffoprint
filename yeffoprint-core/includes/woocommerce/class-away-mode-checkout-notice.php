<?php
/**
 * Injects Away Mode's delay notice into the block-based Checkout page,
 * right above the Place Order button — direct request: "I'd like people
 * to know before placing their orders when I'll be resuming orders."
 * This is the touchpoint that matters most of the four (storefront top
 * bar, homepage card, this, and the confirmation email): it's the last
 * thing a customer sees before actually committing to buy.
 *
 * The Checkout page here is the block-based Checkout (Store API,
 * confirmed at class-manual-payment-blocks-support.php's own docblock —
 * not the classic `[woocommerce_checkout]` shortcode), which renders
 * its own inner blocks as empty placeholder `<div>`s that React
 * hydrates client-side. There's no `render_block` filter to hook for
 * this reason — any server-rendered markup placed there would be wiped
 * the instant React mounts — so this follows the same shape
 * class-card-surcharge-blocks-integration.php already established for
 * the parallel "server can't reach into Checkout's own DOM" problem:
 * build the (already-escaped) notice HTML here in PHP, hand it to the
 * page via wp_localize_script(), and let a small vanilla JS file
 * (assets/blocks/away-mode-checkout.js) insert it next to the real
 * `.wc-block-checkout__actions` row once React has rendered it.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Away_Mode_Checkout_Notice {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		if ( ! is_checkout() ) {
			return;
		}

		$away = function_exists( 'yeffoprint_core_away_mode' ) ? yeffoprint_core_away_mode() : null;
		if ( ! $away ) {
			return;
		}

		$path = 'assets/blocks/away-mode-checkout.js';
		wp_enqueue_script(
			'yeffoprint-away-mode-checkout',
			YEFFOPRINT_CORE_URL . $path,
			[ 'wc-blocks-checkout' ],
			yeffoprint_core_asset_version( $path ),
			true
		);

		wp_localize_script( 'yeffoprint-away-mode-checkout', 'yeffoprintAwayModeCheckout', [
			'html' => $this->notice_html( $away ),
		] );
	}

	/** Every substitution already escaped — this is the exact string the JS inserts verbatim, so nothing downstream re-escapes it. */
	private function notice_html( array $away ): string {
		return sprintf(
			'<div class="yp-away-checkout-notice"><span class="yp-away-checkout-notice__icon" aria-hidden="true">%1$s</span><div><strong class="yp-away-checkout-notice__title">%2$s</strong><p class="yp-away-checkout-notice__body">%3$s</p></div></div>',
			'&#127769;',
			esc_html__( 'Heads up about timing', 'yeffoprint-core' ),
			sprintf(
				/* translators: %s: the date production resumes, e.g. "March 18, 2026" */
				esc_html__( 'We’re away until %s — your order will be received right away, but production won’t start until then.', 'yeffoprint-core' ),
				esc_html( $away['return_label'] )
			)
		);
	}
}
