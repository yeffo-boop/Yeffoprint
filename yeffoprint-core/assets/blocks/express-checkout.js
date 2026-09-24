/**
 * The "Express: skip the line" checkbox on the block-based Checkout —
 * see class-express-order.php. Inserted above the Place Order row the
 * same way away-mode-checkout.js inserts its notice, and each toggle
 * sends the choice through the Store API cart-extensions endpoint so
 * the fee appears in (or leaves) the order totals right away. No build
 * step, matching the rest of this codebase's JS.
 *
 * The option's HTML arrives already built and escaped
 * (yeffoprintExpressCheckout.html, localized from PHP).
 */
( function () {
	'use strict';

	if ( typeof yeffoprintExpressCheckout === 'undefined' || ! yeffoprintExpressCheckout.html ) {
		return;
	}
	if ( typeof window.wc === 'undefined' || ! window.wc.blocksCheckout ) {
		return;
	}

	var config = yeffoprintExpressCheckout;
	var SELECTOR = '.wc-block-checkout__actions';
	var chosen = !! config.chosen;
	var sending = false;
	var pending = null;

	function send( value ) {
		if ( sending ) {
			pending = value;
			return;
		}
		sending = true;
		window.wc.blocksCheckout.extensionCartUpdate( {
			namespace: config.namespace,
			data: { express: value }
		} ).catch( function () {
			// Leave the checkbox as the customer set it; the next toggle retries.
		} ).finally( function () {
			sending = false;
			if ( null !== pending && pending !== value ) {
				var next = pending;
				pending = null;
				send( next );
			} else {
				pending = null;
			}
		} );
	}

	function ensureOption() {
		var actions = document.querySelector( SELECTOR );
		if ( ! actions || actions.parentNode.querySelector( '.yp-express-option' ) ) {
			return;
		}

		// Right above Place Order, below Away Mode's notice when it's showing.
		actions.insertAdjacentHTML( 'beforebegin', config.html );
		var input = actions.parentNode.querySelector( '.yp-express-option__input' );
		input.checked = chosen;
		input.addEventListener( 'change', function () {
			chosen = input.checked;
			send( chosen );
		} );
	}

	// The Checkout block renders client-side and can re-render its
	// actions row (e.g. switching guest/account), so this keeps watching
	// rather than disconnecting after the first insert; ensureOption()
	// is a no-op while the option is still in place.
	ensureOption();
	new MutationObserver( ensureOption ).observe( document.body, { childList: true, subtree: true } );
} )();
