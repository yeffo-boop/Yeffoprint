/**
 * Inserts the live production time card (yeffoprintProductionTime.html,
 * class-production-time.php) above the shipping options, or above the
 * Place Order row when the checkout has none. Same approach as
 * holographic-checkout.js: the Checkout block renders client-side, so
 * this waits for the spot to exist.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintProductionTime === 'undefined' || ! yeffoprintProductionTime.html ) {
		return;
	}

	var TARGETS = [ '.wp-block-woocommerce-checkout-shipping-methods-block', '.wc-block-checkout__actions' ];

	function insertCard() {
		if ( document.querySelector( '.yp-production-time' ) ) {
			return true;
		}

		for ( var i = 0; i < TARGETS.length; i++ ) {
			var target = document.querySelector( TARGETS[ i ] );
			if ( target ) {
				target.insertAdjacentHTML( 'beforebegin', yeffoprintProductionTime.html );
				return true;
			}
		}
		return false;
	}

	// Kept watching: switching between shipping and pickup re-renders the
	// shipping options block, and the card should come back with it.
	insertCard();
	new MutationObserver( insertCard ).observe( document.body, { childList: true, subtree: true } );
} )();
