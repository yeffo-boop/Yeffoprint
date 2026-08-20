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

	var content = window.wp.element.createElement( 'div', { className: 'yp-blocks-payment-description' }, description );

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
