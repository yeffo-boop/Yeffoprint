/**
 * Inserts Away Mode's delay notice into the block-based Checkout page,
 * right above the Place Order button — see class-away-mode-checkout-
 * notice.php for why this can't just be server-rendered markup (the
 * Checkout block hydrates its own inner blocks client-side, wiping
 * anything placed there ahead of time). No build step, matching the
 * rest of this codebase's JS.
 *
 * The notice's HTML arrives already built and escaped
 * (yeffoprintAwayModeCheckout.html, localized from PHP) — this file
 * only ever inserts it verbatim, never constructs any of it itself.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintAwayModeCheckout === 'undefined' || ! yeffoprintAwayModeCheckout.html ) {
		return;
	}

	var SELECTOR = '.wc-block-checkout__actions';
	var inserted = false;

	function insertNotice() {
		if ( inserted ) {
			return true;
		}

		var actions = document.querySelector( SELECTOR );
		if ( ! actions || actions.previousElementSibling && actions.previousElementSibling.classList.contains( 'yp-away-checkout-notice' ) ) {
			return !! actions;
		}

		actions.insertAdjacentHTML( 'beforebegin', yeffoprintAwayModeCheckout.html );
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
