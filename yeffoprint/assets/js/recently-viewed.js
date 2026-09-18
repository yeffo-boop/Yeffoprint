/**
 * Record recently viewed yp_template IDs in a cookie so the gallery /
 * single-template rails can render them server-side on the next page.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintRecentlyViewed === 'undefined' || ! yeffoprintRecentlyViewed.templateId ) {
		return;
	}

	var COOKIE = 'yp_recent_templates';
	var MAX = 8;
	var id = String( parseInt( yeffoprintRecentlyViewed.templateId, 10 ) || 0 );
	if ( '0' === id ) {
		return;
	}

	function readIds() {
		var match = document.cookie.match( /(?:^|; )yp_recent_templates=([^;]*)/ );
		if ( ! match ) {
			return [];
		}
		return decodeURIComponent( match[ 1 ] ).split( ',' ).filter( Boolean );
	}

	var ids = readIds().filter( function ( existing ) {
		return existing !== id;
	} );
	ids.unshift( id );
	ids = ids.slice( 0, MAX );

	var expires = new Date();
	expires.setDate( expires.getDate() + 30 );
	document.cookie = COOKIE + '=' + encodeURIComponent( ids.join( ',' ) ) +
		';path=/;expires=' + expires.toUTCString() + ';SameSite=Lax';
} )();
