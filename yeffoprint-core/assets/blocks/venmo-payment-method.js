/**
 * Registers Venmo with the Checkout block's own client-side payment
 * method registry (window.wc.wcBlocksRegistry) — see class-manual-
 * payment-blocks-support.php for why this file has to exist at all.
 * No build step, matching the rest of this codebase's JS.
 */
( function () {
	'use strict';

	if ( typeof window.wc === 'undefined' || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings || ! window.wp || ! window.wp.element ) {
		return;
	}

	var settings = window.wc.wcSettings.getSetting( 'yeffoprint_venmo_data', {} );
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function ( value ) { return value; };
	var label = decode( settings.title || 'Venmo' );
	var description = decode( settings.description || '' );
	var handle = decode( settings.handle || '' );

	var descriptionEl = window.wp.element.createElement( 'div', { className: 'yp-blocks-payment-description' }, description );

	// The account to actually pay (WooCommerce → Settings → Payments →
	// Venmo) — direct request: this used to only show up after
	// checkout, leaving nothing here but the admin's generic
	// description. A full "pay now" link/QR only makes sense once an
	// order (and its number, for the payment note) actually exists —
	// see class-manual-payment-gateway.php's thankyou_page()/
	// email_instructions() for that.
	var content = descriptionEl;
	if ( handle ) {
		var handleEl = window.wp.element.createElement(
			'div',
			{ className: 'yp-blocks-payment-handle' },
			'Send payment to: ',
			window.wp.element.createElement( 'strong', null, handle )
		);
		content = window.wp.element.createElement( window.wp.element.Fragment, null, descriptionEl, handleEl );
	}

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'yeffoprint_venmo',
		label: label,
		content: content,
		edit: content,
		canMakePayment: function () { return true; },
		ariaLabel: label,
		supports: {
			features: [ 'products' ]
		}
	} );
} )();
