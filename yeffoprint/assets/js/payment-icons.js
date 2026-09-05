/**
 * Adds a small "how you can pay" icon strip under the Cart page's
 * "Proceed to Checkout" button — direct request, mockup approved
 * (Option C: plain icon strip, no labels).
 *
 * The WooCommerce Cart block renders client-side (React), so
 * `.wc-block-cart__submit-container` doesn't exist in the initial
 * server-rendered HTML — a MutationObserver waits for it to appear.
 * Kept running for the page's lifetime rather than disconnecting after
 * the first insert: updating a quantity or applying a coupon re-renders
 * that same container from scratch, which would silently wipe the
 * strip back out — insertStrip()'s own "already there" check makes
 * re-observing harmless, just a no-op on every render that isn't a
 * fresh container.
 */
( function () {
	'use strict';

	var ICONS_HTML =
		'<span class="yp-pay-icons__icon" aria-label="Card"><svg width="20" height="15" viewBox="0 0 18 13" fill="none" aria-hidden="true"><rect x="0.5" y="0.5" width="17" height="12" rx="2" stroke="currentColor"/><rect x="0.5" y="3.2" width="17" height="2.2" fill="currentColor"/><rect x="2" y="8.3" width="4.5" height="1.4" rx="0.7" fill="currentColor"/></svg></span>' +
		'<span class="yp-pay-icons__icon" aria-label="Venmo"><svg width="16" height="16" viewBox="0 0 14 14" fill="none" aria-hidden="true"><circle cx="7" cy="7" r="6.5" stroke="currentColor"/><path d="M9.6 4.4c.3.5.45 1.05.45 1.7 0 2-1.75 4.6-3.15 6.4H5.1L4 5l2-.2.6 4.65c.55-.9 1.25-2.3 1.25-3.3 0-.5-.1-.9-.2-1.2L9.6 4.4z" fill="currentColor"/></svg></span>' +
		'<span class="yp-pay-icons__icon" aria-label="Zelle"><svg width="16" height="16" viewBox="0 0 14 14" fill="none" aria-hidden="true"><rect x="0.5" y="0.5" width="13" height="13" rx="3" stroke="currentColor"/><path d="M4.2 4.3h5.6v1L5.6 9.7h4.2v1H4v-1l4.2-4.4H4.2v-1z" fill="currentColor"/></svg></span>' +
		'<span class="yp-pay-icons__icon" aria-label="Crypto (BTC, USDC, USDT)"><svg width="16" height="16" viewBox="0 0 14 14" fill="none" aria-hidden="true"><circle cx="7" cy="7" r="6.5" stroke="currentColor"/><path d="M6.1 3.6h.9v1h.4c.9 0 1.6.5 1.6 1.4 0 .5-.25.9-.65 1.1.55.15.95.6.95 1.25 0 1-.8 1.5-1.8 1.5h-.5v1h-.9v-1H5v-1h.6V4.6H5v-1h1.1v-1zm.9 2v1.4h.35c.55 0 .9-.25.9-.7s-.35-.7-.9-.7h-.35zm0 2.4v1.5h.45c.6 0 1-.3 1-.75s-.4-.75-1-.75h-.45z" fill="currentColor"/></svg></span>';

	function insertStrip( container ) {
		if ( container.querySelector( '.yp-pay-icons' ) ) {
			return;
		}
		var strip = document.createElement( 'div' );
		strip.className = 'yp-pay-icons';
		strip.innerHTML = ICONS_HTML;
		container.appendChild( strip );
	}

	function checkNow() {
		var container = document.querySelector( '.wc-block-cart__submit-container' );
		if ( container ) {
			insertStrip( container );
		}
	}

	checkNow();

	new MutationObserver( checkNow ).observe( document.body, { childList: true, subtree: true } );
} )();
