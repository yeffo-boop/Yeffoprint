<?php
/**
 * "Import fees may apply" note on the block Checkout, shown under the
 * shipping options whenever the shipping address is outside the store's
 * own country. Jeff's ask (2026-11-23): tell international customers their
 * country may charge duties/customs fees and that those are paid by the
 * receiver. He picked the inline amber note (mockup A in
 * mockups/duties-notice/), same tone as the Away Mode checkout notice.
 *
 * Same approach as class-away-mode-checkout-notice.php: the Checkout block
 * hydrates client-side, so PHP only hands over the text and country names
 * and assets/blocks/international-duties-checkout.js places the note and
 * keeps it in sync with the address the customer types.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_International_Duties_Notice {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! function_exists( 'WC' ) || ! WC()->countries ) {
			return;
		}

		$countries = [];
		foreach ( WC()->countries->get_shipping_countries() as $code => $name ) {
			$name = html_entity_decode( wp_strip_all_tags( (string) $name ), ENT_QUOTES, 'UTF-8' );
			// "United Kingdom (UK)" reads better in a sentence as "United Kingdom".
			$countries[ $code ] = trim( (string) preg_replace( '/\s*\([A-Z]{2,3}\)$/', '', $name ) );
		}

		$path = 'assets/blocks/international-duties-checkout.js';
		wp_enqueue_script(
			'yeffoprint-international-duties-checkout',
			YEFFOPRINT_CORE_URL . $path,
			[ 'wc-blocks-checkout', 'wp-data' ],
			yeffoprint_core_asset_version( $path ),
			true
		);

		// Plain text, not HTML: the JS sets these with textContent.
		wp_localize_script( 'yeffoprint-international-duties-checkout', 'yeffoprintDutiesNotice', [
			'homeCountry' => WC()->countries->get_base_country(),
			'countries'   => $countries,
			'title'       => __( 'Import fees may apply', 'yeffoprint-core' ),
			/* translators: %s: the customer's shipping country, e.g. "Canada" */
			'body'        => __( '%s may charge duties, taxes or customs fees when your package arrives. These aren’t part of your total here and are paid by the receiver.', 'yeffoprint-core' ),
			'fallback'    => __( 'Your country', 'yeffoprint-core' ),
			'linkText'    => __( 'Learn more', 'yeffoprint-core' ),
			'linkUrl'     => home_url( '/shipping-policy/#section-4' ),
		] );
	}
}
