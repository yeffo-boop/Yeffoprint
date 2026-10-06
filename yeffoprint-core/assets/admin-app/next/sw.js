/**
 * Service worker for the new admin app's phone install, served at
 * /design/sw.js by class-admin-app-shortcut.php with a wp-admin scope.
 *
 * Push alerts only (class-admin-push.php): shows each alert, and opens
 * (or focuses) the admin app at the alert's link when it is tapped.
 * Express order reminders also carry a "Got it" button (shown on
 * Android and desktop; iPhone doesn't show notification buttons) that
 * acknowledges the order without opening the app, through a token the
 * push itself carries (class-telegram-express-alerts.php).
 * Deliberately no fetch handler, so it never changes how any wp-admin
 * page loads or caches.
 */
/* global self, clients */
( function () {
	'use strict';

	self.addEventListener( 'install', function () {
		self.skipWaiting();
	} );

	self.addEventListener( 'activate', function ( event ) {
		event.waitUntil( self.clients.claim() );
	} );

	self.addEventListener( 'push', function ( event ) {
		var data = {};
		try {
			data = event.data ? event.data.json() : {};
		} catch ( e ) {
			data = { title: event.data ? event.data.text() : '' };
		}

		var options = {
			body: data.body || '',
			icon: data.icon || undefined,
			badge: data.badge || undefined,
			data: { url: data.url || self.registration.scope + 'admin.php?page=yeffoprint-next', ackUrl: data.ack_url || '' }
		};
		if ( data.tag ) {
			options.tag = data.tag;
			options.renotify = !! data.renotify;
		}
		if ( data.requireInteraction ) {
			options.requireInteraction = true;
		}
		if ( data.actions && data.actions.length ) {
			options.actions = data.actions;
		}

		event.waitUntil( self.registration.showNotification( data.title || 'YeffoDesign', options ) );
	} );

	self.addEventListener( 'notificationclick', function ( event ) {
		event.notification.close();
		var url = ( event.notification.data && event.notification.data.url ) || self.registration.scope;

		if ( 'ack' === event.action && event.notification.data && event.notification.data.ackUrl ) {
			event.waitUntil(
				fetch( event.notification.data.ackUrl, { method: 'POST', credentials: 'omit' } ).then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'ack failed' );
					}
				} ).catch( function () {
					// Couldn't reach the site: open the order so it can be acknowledged there.
					return clients.openWindow( url );
				} )
			);
			return;
		}

		event.waitUntil(
			clients.matchAll( { type: 'window', includeUncontrolled: true } ).then( function ( list ) {
				for ( var i = 0; i < list.length; i++ ) {
					if ( list[ i ].url.indexOf( 'page=yeffoprint-next' ) !== -1 && 'focus' in list[ i ] ) {
						return list[ i ].navigate( url ).then( function ( client ) {
							return client ? client.focus() : clients.openWindow( url );
						} );
					}
				}
				return clients.openWindow( url );
			} )
		);
	} );
}() );
