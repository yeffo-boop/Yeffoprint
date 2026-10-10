/**
 * Messages — contact form messages and web design quote requests
 * (includes/messages/class-messages.php, via class-admin-messages-
 * controller.php). Direct request: run the business from the dashboard
 * without wp-admin; these used to exist only as an email and a Telegram
 * alert. Reply by email (address filled in), then Mark done.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var TABS = [
		{ filter: 'new', label: 'New' },
		{ filter: 'contact', label: 'Contact form' },
		{ filter: 'quote', label: 'Quote requests' },
		{ filter: 'done', label: 'Done' },
		{ filter: 'all', label: 'All' }
	];

	function endpoint( path ) {
		return yeffoprintAdminApp.restUrl + 'admin/messages' + ( path ? '/' + path : '' );
	}

	function mailto( message ) {
		var subject = 'quote' === message.kind ? 'Your web design quote request' : 'Re: your message to YeffoDesign';
		var quoted  = message.message.split( '\n' ).map( function ( line ) {
			return '> ' + line;
		} ).join( '\n' );
		var first = ( message.name || '' ).split( ' ' )[ 0 ];
		return 'mailto:' + encodeURIComponent( message.email ) +
			'?subject=' + encodeURIComponent( subject ) +
			'&body=' + encodeURIComponent( ( first ? 'Hi ' + first + ',' : 'Hi,' ) + '\n\n\n\n' + quoted );
	}

	YP.views.messages = function ( viewEl ) {
		var filter = 'new';
		var page = 1;

		viewEl.innerHTML =
			'<p class="yp-app__intro">Contact form messages and web design quote requests from the website. Reply by email; the address is filled in. They still arrive by email and as a phone alert too.</p>' +
			'<div class="yp-settings-tabs" role="tablist">' +
				TABS.map( function ( tab ) {
					return '<button type="button" class="yp-settings-tabs__tab' + ( tab.filter === filter ? ' is-active' : '' ) + '" data-yp-msg-tab="' + tab.filter + '" role="tab" aria-selected="' + ( tab.filter === filter ? 'true' : 'false' ) + '">' + tab.label + '<span data-yp-msg-count="' + tab.filter + '"></span></button>';
				} ).join( '' ) +
			'</div>' +
			'<div class="yp-review-admin__list" data-yp-msg-list><p class="yp-panel__hint">Loading&hellip;</p></div>' +
			'<div class="yp-pagination" data-yp-msg-pages></div>';

		var listEl = viewEl.querySelector( '[data-yp-msg-list]' );
		var pagesEl = viewEl.querySelector( '[data-yp-msg-pages]' );

		viewEl.querySelectorAll( '[data-yp-msg-tab]' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				filter = tab.getAttribute( 'data-yp-msg-tab' );
				page = 1;
				viewEl.querySelectorAll( '[data-yp-msg-tab]' ).forEach( function ( other ) {
					var active = other === tab;
					other.classList.toggle( 'is-active', active );
					other.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				} );
				load();
			} );
		} );

		function load() {
			YP.request( endpoint() + '?filter=' + encodeURIComponent( filter ) + '&page=' + page )
				.then( render )
				.catch( function ( error ) {
					listEl.innerHTML = '<p class="yp-form__error">Couldn’t load messages: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		function render( data ) {
			// Acting on the last message of a later page leaves that page empty.
			if ( ! data.messages.length && page > 1 ) {
				page = Math.max( 1, data.max_num_pages || 1 );
				load();
				return;
			}
			viewEl.querySelector( '[data-yp-msg-count="new"]' ).textContent = data.new_count ? ' (' + data.new_count + ')' : '';

			pagesEl.innerHTML = data.max_num_pages > 1
				? '<button type="button" class="wp-block-button__link is-style-outline" data-yp-msg-page="-1"' + ( page <= 1 ? ' disabled' : '' ) + '>&larr; Newer</button>' +
					'<span class="yp-pagination__status">Page ' + page + ' of ' + data.max_num_pages + '</span>' +
					'<button type="button" class="wp-block-button__link is-style-outline" data-yp-msg-page="1"' + ( page >= data.max_num_pages ? ' disabled' : '' ) + '>Older &rarr;</button>'
				: '';
			pagesEl.querySelectorAll( '[data-yp-msg-page]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					page += parseInt( button.getAttribute( 'data-yp-msg-page' ), 10 );
					load();
				} );
			} );

			if ( ! data.messages.length ) {
				listEl.innerHTML = '<div class="yp-record-card"><p class="yp-review-admin__empty">' +
					( 'new' === filter ? 'Nothing new. Messages from the Contact page and web design quote form land here.' : 'No messages here yet.' ) +
					'</p></div>';
				return;
			}

			listEl.innerHTML = data.messages.map( function ( m ) {
				var actions = [
					m.email ? '<a class="wp-block-button__link is-style-accent yp-review-admin__publish" href="' + YP.escapeAttr( mailto( m ) ) + '">Reply by email</a>' : '',
					'new' === m.status
						? '<button type="button" class="yp-row-action" data-yp-msg-act="done" data-id="' + m.id + '">Mark done</button>'
						: '<button type="button" class="yp-row-action" data-yp-msg-act="reopen" data-id="' + m.id + '">Reopen</button>',
					'<button type="button" class="yp-row-action" data-yp-msg-act="delete" data-id="' + m.id + '">Delete</button>'
				];

				return (
					'<article class="yp-record-card yp-review-admin">' +
						'<header class="yp-review-admin__head">' +
							'<strong>' + YP.escapeHtml( m.name || 'No name' ) + '</strong>' +
							'<span class="yp-field__hint">' + ( m.date ? YP.escapeHtml( new Date( m.date ).toLocaleString() ) + ' · ' : '' ) + YP.escapeHtml( m.email ) + '</span>' +
							'<span class="yp-pill ' + ( 'quote' === m.kind ? 'yp-pill--warn' : 'yp-pill--neutral' ) + '">' + YP.escapeHtml( m.kind_label ) + '</span>' +
							( 'new' === m.status ? '<span class="yp-pill yp-fb-pill--new">New</span>' : '<span class="yp-pill yp-pill--good">Done</span>' ) +
						'</header>' +
						( m.reply_via ? '<p class="yp-field__hint">Prefers to hear back on ' + YP.escapeHtml( m.reply_via ) + '</p>' : '' ) +
						'<p class="yp-review-admin__text" style="white-space:pre-line">' + YP.escapeHtml( m.message ) + '</p>' +
						'<div class="yp-review-admin__actions">' + actions.join( ' ' ) + '</div>' +
					'</article>'
				);
			} ).join( '' );

			listEl.querySelectorAll( '[data-yp-msg-act]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var action = button.getAttribute( 'data-yp-msg-act' );
					var id     = button.getAttribute( 'data-id' );
					if ( 'delete' !== action ) {
						act( id, action, button );
						return;
					}
					YP.confirmModal( {
						title: 'Delete this message?',
						message: 'It’s removed from the dashboard for good. The email copy stays in your inbox.',
						confirmLabel: 'Delete message',
						danger: true,
						onConfirm: function () {
							act( id, action, button );
						}
					} );
				} );
			} );
		}

		function act( id, action, button ) {
			button.disabled = true;
			YP.request( endpoint( id + '/' + action ), { method: 'POST' } )
				.then( load )
				.catch( function ( error ) {
					button.disabled = false;
					window.alert( 'Couldn’t do that: ' + error.message );
				} );
		}

		load();
	};
} )();
