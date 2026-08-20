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

	var content = window.wp.element.createElement( 'div', { className: 'yp-blocks-payment-description' }, description );

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
