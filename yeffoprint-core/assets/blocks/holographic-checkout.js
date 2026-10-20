/**
 * Inserts holographic vinyl's "Worth the extra day" note above the Place
 * Order button when the cart has a holographic item. Same approach as
 * away-mode-checkout.js: the Checkout block renders client-side, so the
 * already-escaped HTML (yeffoprintHoloCheckout.html, class-holographic-
 * vinyl.php) is inserted once its actions row exists.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintHoloCheckout === 'undefined' || ! yeffoprintHoloCheckout.html ) {
		return;
	}

	var SELECTOR = '.wc-block-checkout__actions';
	var inserted = false;

	function insertNotice() {
		if ( inserted ) {
			return true;
		}

		var actions = document.querySelector( SELECTOR );
		if ( ! actions || actions.previousElementSibling && actions.previousElementSibling.classList.contains( 'yp-holo-note--checkout' ) ) {
			return !! actions;
		}

		actions.insertAdjacentHTML( 'beforebegin', yeffoprintHoloCheckout.html );
		inserted = true;
		return true;
	}

	// The actions row doesn't exist yet on first paint — the Checkout
	// block renders its inner blocks client-side — so this watches the
	// page until it appears, then disconnects. insertNotice() itself is
	// idempotent (checks `inserted` first) in case Checkout ever
	// re-renders that row (e.g. switching between guest/account tabs).
	if ( insertNotice() ) {
		return;
	}

	var observer = new MutationObserver( function () {
		if ( insertNotice() ) {
			observer.disconnect();
		}
	} );

	observer.observe( document.body, { childList: true, subtree: true } );
} )();
