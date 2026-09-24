/**
 * Abandoned cart capture on the block-based Checkout — see
 * class-abandoned-carts.php. The moment the shopper fills in their
 * email, it's sent through the Store API cart-extensions endpoint (the
 * same round trip express-checkout.js uses), so a cart left right
 * after typing an email is still tracked even if the Checkout block
 * hasn't pushed the address yet. Sends once per distinct address. No
 * build step, matching the rest of this codebase's JS.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintAbandonedCart === 'undefined' ) {
		return;
	}
	if ( typeof window.wc === 'undefined' || ! window.wc.blocksCheckout || ! window.wc.blocksCheckout.extensionCartUpdate ) {
		return;
	}

	var lastSent = '';
	var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

	function valueOf( id ) {
		var el = document.getElementById( id );
		return el ? el.value.trim() : '';
	}

	function maybeSend( input ) {
		var email = input.value.trim().toLowerCase();
		if ( ! EMAIL.test( email ) || email === lastSent ) {
			return;
		}
		lastSent = email;
		window.wc.blocksCheckout.extensionCartUpdate( {
			namespace: yeffoprintAbandonedCart.namespace,
			data: {
				email: email,
				first_name: valueOf( 'billing-first_name' ) || valueOf( 'shipping-first_name' ),
				last_name: valueOf( 'billing-last_name' ) || valueOf( 'shipping-last_name' )
			}
		} ).catch( function () {
			lastSent = '';
		} );
	}

	// Delegated, since the Checkout block renders (and re-renders) its
	// fields client-side.
	document.addEventListener( 'change', function ( event ) {
		var target = event.target;
		if ( target && 'email' === target.type && target.closest( '.wc-block-checkout' ) ) {
			maybeSend( target );
		}
	}, true );
} )();
