/**
 * Peptide Calculator service worker, served at /peptide-calculator/sw.js
 * by class-calculator-app.php, which prepends self.YP_PCALC_SW
 * ({ version, appUrl }). Only controls /peptide-calculator/, so the rest
 * of the site and the Dose Tracker's own worker are untouched.
 *
 * The calculator is pure client-side math, so keeping a copy of the page
 * and the files it loads is enough for it to open with no signal:
 * - the page: network first, last good copy when offline;
 * - its CSS, JS, fonts and images: served from the copy, refreshed in the
 *   background so a deploy shows up on the next open.
 */
/* global self, caches */
( function () {
	'use strict';

	var cfg = self.YP_PCALC_SW || { version: '0', appUrl: '/peptide-calculator/' };
	var CACHE = 'yp-pcalc-' + cfg.version;
	var APP_PATH = new URL( cfg.appUrl ).pathname;

	self.addEventListener( 'install', function ( event ) {
		event.waitUntil(
			caches.open( CACHE ).then( function ( cache ) {
				return cache.add( cfg.appUrl ).catch( function () {} );
			} ).then( function () {
				return self.skipWaiting();
			} )
		);
	} );

	self.addEventListener( 'activate', function ( event ) {
		event.waitUntil(
			caches.keys().then( function ( keys ) {
				return Promise.all( keys.filter( function ( k ) {
					return k.indexOf( 'yp-pcalc-' ) === 0 && k !== CACHE;
				} ).map( function ( k ) {
					return caches.delete( k );
				} ) );
			} ).then( function () {
				return self.clients.claim();
			} )
		);
	} );

	function isAsset( req, url ) {
		var dest = req.destination;
		if ( dest !== 'style' && dest !== 'script' && dest !== 'font' && dest !== 'image' ) {
			return false;
		}
		if ( url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com' ) {
			return true;
		}
		return url.origin === self.location.origin && ( url.pathname.indexOf( '/wp-content/' ) !== -1 || url.pathname.indexOf( '/wp-includes/' ) !== -1 );
	}

	self.addEventListener( 'fetch', function ( event ) {
		var req = event.request;
		if ( req.method !== 'GET' ) {
			return;
		}
		var url = new URL( req.url );

		if ( req.mode === 'navigate' ) {
			if ( url.pathname !== APP_PATH ) {
				return;
			}
			event.respondWith(
				fetch( req ).then( function ( res ) {
					if ( res.ok ) {
						var copy = res.clone();
						caches.open( CACHE ).then( function ( c ) {
							c.put( cfg.appUrl, copy );
						} );
					}
					return res;
				} ).catch( function () {
					return caches.match( cfg.appUrl );
				} )
			);
			return;
		}

		if ( ! isAsset( req, url ) ) {
			return;
		}
		event.respondWith(
			caches.open( CACHE ).then( function ( cache ) {
				return cache.match( req ).then( function ( hit ) {
					var fresh = fetch( req ).then( function ( res ) {
						if ( res.ok || res.type === 'opaque' ) {
							cache.put( req, res.clone() );
						}
						return res;
					} );
					if ( hit ) {
						fresh.catch( function () {} );
						return hit;
					}
					return fresh;
				} );
			} )
		);
	} );
}() );
