/**
 * Dose Tracker service worker, served at /tracker/sw.js by
 * class-tracker-app.php, which prepends self.YP_TRACKER_SW
 * ({ version, shell: [css, js], appUrl }).
 *
 * - Caches the app shell (page, CSS, JS, fonts) so the tracker opens
 *   with no signal. API responses are never cached here: the app keeps
 *   its own copy of the customer's data and syncs it.
 * - Shows dose reminders pushed by class-tracker-reminders.php and
 *   opens the tracker when one is tapped. Its "Remind me in 30 min"
 *   button posts the reminder's signed snooze token, without opening
 *   anything (iPhone shows no buttons; Today has the same choice).
 */
/* global self, caches, clients */
( function () {
	'use strict';

	var cfg = self.YP_TRACKER_SW || { version: '0', shell: [], appUrl: '/tracker/', snoozeUrl: '' };
	var CACHE = 'yp-tracker-' + cfg.version;

	self.addEventListener( 'install', function ( event ) {
		event.waitUntil(
			caches.open( CACHE ).then( function ( cache ) {
				return cache.addAll( cfg.shell ).catch( function () {} );
			} ).then( function () {
				return self.skipWaiting();
			} )
		);
	} );

	self.addEventListener( 'activate', function ( event ) {
		event.waitUntil(
			caches.keys().then( function ( keys ) {
				return Promise.all( keys.filter( function ( k ) {
					return k.indexOf( 'yp-tracker-' ) === 0 && k !== CACHE;
				} ).map( function ( k ) {
					return caches.delete( k );
				} ) );
			} ).then( function () {
				return self.clients.claim();
			} )
		);
	} );

	self.addEventListener( 'fetch', function ( event ) {
		var req = event.request;
		if ( req.method !== 'GET' ) {
			return;
		}
		var url = new URL( req.url );

		// Never touch the API — customer data stays out of this cache.
		if ( url.pathname.indexOf( '/wp-json/' ) !== -1 || url.searchParams.has( 'rest_route' ) ) {
			return;
		}

		// The page itself: network first (it carries a fresh nonce and
		// sign-in state), cached copy when offline.
		if ( req.mode === 'navigate' ) {
			event.respondWith(
				fetch( req ).then( function ( res ) {
					// Only the app's own page is the offline copy, never a /tracker/p/ share page.
					if ( res.ok && url.pathname === new URL( cfg.appUrl ).pathname ) {
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

		// Shell assets and fonts: cache first, fill on miss.
		var isShell = cfg.shell.indexOf( req.url ) !== -1;
		var isFont = url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com';
		var isIcon = url.pathname.indexOf( '/assets/tracker/' ) !== -1;
		if ( isShell || isFont || isIcon ) {
			event.respondWith(
				caches.match( req ).then( function ( hit ) {
					return hit || fetch( req ).then( function ( res ) {
						if ( res.ok || res.type === 'opaque' ) {
							var copy = res.clone();
							caches.open( CACHE ).then( function ( c ) {
								c.put( req, copy );
							} );
						}
						return res;
					} );
				} )
			);
		}
	} );

	self.addEventListener( 'push', function ( event ) {
		var data = {};
		try {
			data = event.data ? event.data.json() : {};
		} catch ( e ) {}

		var snooze = data.snooze && cfg.snoozeUrl ? String( data.snooze ) : '';
		var actions = snooze && Array.isArray( data.actions ) ? data.actions.slice( 0, 1 ) : [];
		event.waitUntil(
			self.registration.showNotification( data.title || 'Dose reminder', {
				body: data.body || '',
				tag: data.tag || 'yp-dose',
				renotify: true,
				icon: new URL( 'icons/icon-192.png', cfg.shell[ 0 ] || self.location.href ).href,
				badge: new URL( 'icons/badge-96.png', cfg.shell[ 0 ] || self.location.href ).href,
				actions: actions,
				data: { url: data.url || cfg.appUrl, snooze: snooze },
			} )
		);
	} );

	self.addEventListener( 'notificationclick', function ( event ) {
		event.notification.close();
		var info = event.notification.data || {};
		if ( event.action === 'snooze' && info.snooze ) {
			event.waitUntil(
				fetch( cfg.snoozeUrl, {
					method: 'POST',
					credentials: 'omit',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { token: info.snooze } ),
				} ).then( function ( res ) {
					if ( ! res.ok ) {
						throw new Error( 'snooze' );
					}
				} ).catch( function () {
					// Offline or refused: say so instead of quietly losing the reminder.
					return self.registration.showNotification( 'Couldn’t snooze that reminder', {
						body: 'Open YeffoHealth to log your dose.',
						tag: event.notification.tag,
						icon: new URL( 'icons/icon-192.png', cfg.shell[ 0 ] || self.location.href ).href,
						badge: new URL( 'icons/badge-96.png', cfg.shell[ 0 ] || self.location.href ).href,
						data: { url: info.url || cfg.appUrl },
					} );
				} )
			);
			return;
		}
		var target = ( event.notification.data && event.notification.data.url ) || cfg.appUrl;
		event.waitUntil(
			clients.matchAll( { type: 'window', includeUncontrolled: true } ).then( function ( list ) {
				for ( var i = 0; i < list.length; i++ ) {
					if ( list[ i ].url.indexOf( cfg.appUrl ) === 0 && 'focus' in list[ i ] ) {
						list[ i ].postMessage( { type: 'yp-tracker-refresh' } );
						return list[ i ].focus();
					}
				}
				return clients.openWindow( target );
			} )
		);
	} );
}() );
