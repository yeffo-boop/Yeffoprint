/**
 * Registers Zelle with the Checkout block's own client-side payment
 * method registry — see venmo-payment-method.js / class-manual-
 * payment-blocks-support.php.
 */
( function () {
	'use strict';

	if ( typeof window.wc === 'undefined' || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings || ! window.wp || ! window.wp.element ) {
		return;
	}

	var settings = window.wc.wcSettings.getSetting( 'yeffoprint_zelle_data', {} );
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function ( value ) { return value; };
	var label = decode( settings.title || 'Zelle' );
	var description = decode( settings.description || '' );
	var handle = decode( settings.handle || '' );

	var descriptionEl = window.wp.element.createElement( 'div', { className: 'yp-blocks-payment-description' }, description );

	// See venmo-payment-method.js for why this is plain text here (no
	// pay link/QR — Zelle has no cross-bank payment URL to link to,
	// unlike Venmo) and the full instructions only appear once an order
	// exists (class-manual-payment-gateway.php).
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
		name: 'yeffoprint_zelle',
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
