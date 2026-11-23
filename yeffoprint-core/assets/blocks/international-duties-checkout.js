/**
 * Shows the "Import fees may apply" note under the Checkout block's
 * shipping options when the shipping country isn't the store's own
 * (see class-international-duties-notice.php). Reads the address from
 * the wc/store/cart data store, so the note appears, hides or renames
 * the country as soon as the customer changes it. Hidden for local pickup.
 * No build step, matching the rest of this codebase's JS.
 */
( function () {
	'use strict';

	var cfg = window.yeffoprintDutiesNotice;
	if ( ! cfg || ! window.wp || ! wp.data ) {
		return;
	}

	var CART_STORE = 'wc/store/cart';
	var NOTE_CLASS = 'yp-duties-notice';
	var ANCHORS = [
		'.wp-block-woocommerce-checkout-shipping-methods-block',
		'.wc-block-checkout__shipping-option',
	];

	function countryCode() {
		var store = wp.data.select( CART_STORE );
		if ( ! store || ! store.getCustomerData ) {
			return '';
		}
		var data = store.getCustomerData() || {};
		var address = data.shippingAddress || {};
		return String( address.country || '' ).toUpperCase();
	}

	function pickupSelected() {
		var store = wp.data.select( CART_STORE );
		var cart = store && store.getCartData ? store.getCartData() : null;
		var packages = ( cart && cart.shippingRates ) || [];
		return packages.some( function ( pkg ) {
			return ( pkg.shipping_rates || [] ).some( function ( rate ) {
				return rate.selected && /pickup/.test( String( rate.method_id || '' ) );
			} );
		} );
	}

	function buildNote() {
		var note = document.createElement( 'div' );
		note.className = NOTE_CLASS;
		note.setAttribute( 'role', 'note' );
		note.innerHTML =
			'<span class="yp-duties-notice__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg></span>' +
			'<div><strong class="yp-duties-notice__title"></strong><p class="yp-duties-notice__body"><span></span> <a></a></p></div>';
		note.querySelector( '.yp-duties-notice__title' ).textContent = cfg.title;
		var link = note.querySelector( 'a' );
		link.textContent = cfg.linkText;
		link.href = cfg.linkUrl;
		link.target = '_blank';
		link.rel = 'noopener';
		return note;
	}

	function anchor() {
		for ( var i = 0; i < ANCHORS.length; i++ ) {
			var el = document.querySelector( ANCHORS[ i ] );
			if ( el ) {
				return el;
			}
		}
		return null;
	}

	var lastText = null;

	function sync() {
		var code = countryCode();
		var show = code && code !== String( cfg.homeCountry || 'US' ).toUpperCase() && ! pickupSelected();
		var existing = document.querySelector( '.' + NOTE_CLASS );

		if ( ! show ) {
			if ( existing ) {
				existing.remove();
			}
			lastText = null;
			return;
		}

		var host = anchor();
		if ( ! host ) {
			return;
		}
		if ( ! existing || ! host.contains( existing ) ) {
			if ( existing ) {
				existing.remove();
			}
			existing = buildNote();
			host.appendChild( existing );
			lastText = null;
		}

		var name = ( cfg.countries && cfg.countries[ code ] ) || cfg.fallback;
		var text = String( cfg.body ).replace( '%s', name );
		if ( text !== lastText ) {
			existing.querySelector( '.yp-duties-notice__body span' ).textContent = text;
			lastText = text;
		}
	}

	var queued = false;
	function schedule() {
		if ( queued ) {
			return;
		}
		queued = true;
		window.requestAnimationFrame( function () {
			queued = false;
			sync();
		} );
	}

	// Address/rate changes come through the data store; the observer
	// covers the Checkout block re-rendering its shipping section.
	wp.data.subscribe( schedule );
	new MutationObserver( function ( mutations ) {
		for ( var i = 0; i < mutations.length; i++ ) {
			var t = mutations[ i ].target;
			if ( t && t.closest && t.closest( '.' + NOTE_CLASS ) ) {
				continue;
			}
			schedule();
			return;
		}
	} ).observe( document.body, { childList: true, subtree: true } );
	schedule();
} )();
