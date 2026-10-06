/**
 * Tracker Feedback — help questions, problem reports and ideas sent from
 * the Dose Tracker's Me > Help & feedback (includes/tracker/class-
 * tracker-feedback.php, via class-admin-tracker-feedback-controller.php).
 * Replies go by email; Mark done also erases any tracker setup the
 * customer chose to include.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var TABS = [
		{ filter: 'new', label: 'New' },
		{ filter: 'problem', label: 'Problems' },
		{ filter: 'help', label: 'Help' },
		{ filter: 'idea', label: 'Ideas' },
		{ filter: 'done', label: 'Done' },
		{ filter: 'all', label: 'All' }
	];

	var TYPE_PILLS = {
		help: '<span class="yp-pill yp-fb-pill--help">Help</span>',
		problem: '<span class="yp-pill yp-fb-pill--problem">Problem</span>',
		idea: '<span class="yp-pill yp-pill--warn">Idea</span>'
	};

	function endpoint( path ) {
		return yeffoprintAdminApp.restUrl + 'admin/tracker-feedback' + ( path ? '/' + path : '' );
	}

	function screenshotUrl( id, n ) {
		return endpoint( id + '/screenshot/' + n ) + '?_wpnonce=' + encodeURIComponent( yeffoprintAdminApp.nonce );
	}

	function mailto( note ) {
		var subject = 'Re: your Dose Tracker note';
		var quoted  = note.message.split( '\n' ).map( function ( line ) {
			return '> ' + line;
		} ).join( '\n' );
		return 'mailto:' + encodeURIComponent( note.email ) +
			'?subject=' + encodeURIComponent( subject ) +
			'&body=' + encodeURIComponent( 'Hi ' + note.name.split( ' ' )[ 0 ] + ',\n\n\n\n' + quoted );
	}

	YP.views[ 'tracker-feedback' ] = function ( viewEl ) {
		var filter = 'new';

		viewEl.innerHTML =
			'<p class="yp-app__intro">Help requests, problems and ideas sent from the Dose Tracker’s Me tab. Reply by email; the customer’s address is filled in. Tracker setup is only attached when the customer turned it on, and Mark done erases it.</p>' +
			'<div data-yp-fb-stats></div>' +
			'<div class="yp-settings-tabs" role="tablist">' +
				TABS.map( function ( tab ) {
					return '<button type="button" class="yp-settings-tabs__tab' + ( tab.filter === filter ? ' is-active' : '' ) + '" data-yp-fb-tab="' + tab.filter + '" role="tab" aria-selected="' + ( tab.filter === filter ? 'true' : 'false' ) + '">' + tab.label + '<span data-yp-fb-count="' + tab.filter + '"></span></button>';
				} ).join( '' ) +
			'</div>' +
			'<div class="yp-review-admin__list" data-yp-fb-list><p class="yp-panel__hint">Loading&hellip;</p></div>';

		var listEl = viewEl.querySelector( '[data-yp-fb-list]' );

		viewEl.querySelectorAll( '[data-yp-fb-tab]' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				filter = tab.getAttribute( 'data-yp-fb-tab' );
				viewEl.querySelectorAll( '[data-yp-fb-tab]' ).forEach( function ( other ) {
					var active = other === tab;
					other.classList.toggle( 'is-active', active );
					other.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				} );
				load();
			} );
		} );

		function load() {
			YP.request( endpoint() + '?filter=' + encodeURIComponent( filter ) )
				.then( render )
				.catch( function ( error ) {
					listEl.innerHTML = '<p class="yp-form__error">Couldn’t load feedback: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		function avgLabel( hours ) {
			if ( null === hours || undefined === hours ) {
				return '—';
			}
			if ( hours < 1 ) {
				return Math.max( 1, Math.round( hours * 60 ) ) + ' min';
			}
			return hours < 24 ? hours.toFixed( 1 ) + ' hrs' : ( hours / 24 ).toFixed( 1 ) + ' days';
		}

		function render( data ) {
			viewEl.querySelector( '[data-yp-fb-stats]' ).innerHTML =
				'<div class="yp-stat-tiles">' +
					[
						[ String( data.stats.new ), 'New' ],
						[ String( data.stats.done ), 'Done' ],
						[ avgLabel( data.stats.avg_hours ), 'Avg. time to done' ]
					].map( function ( tile ) {
						return '<div class="yp-stat-tile"><span class="yp-stat-tile__count">' + tile[ 0 ] + '</span><span class="yp-stat-tile__label">' + tile[ 1 ] + '</span></div>';
					} ).join( '' ) +
				'</div>';

			viewEl.querySelector( '[data-yp-fb-count="new"]' ).textContent = data.stats.new ? ' (' + data.stats.new + ')' : '';

			renderList( data.notes );
		}

		function renderList( notes ) {
			if ( ! notes.length ) {
				listEl.innerHTML = '<div class="yp-record-card"><p class="yp-review-admin__empty">' +
					( 'new' === filter ? 'Nothing new. Customers send notes from the Dose Tracker’s Me tab.' : 'No notes here yet.' ) +
					'</p></div>';
				return;
			}

			listEl.innerHTML = notes.map( function ( note ) {
				var shots = '';
				for ( var i = 0; i < note.screenshots; i++ ) {
					var src = screenshotUrl( note.id, i );
					shots += '<a href="' + YP.escapeAttr( src ) + '" target="_blank" rel="noopener"><img src="' + YP.escapeAttr( src ) + '" alt="Screenshot ' + ( i + 1 ) + '" loading="lazy" /></a>';
				}

				var actions = [
					'<a class="wp-block-button__link is-style-accent yp-review-admin__publish" href="' + YP.escapeAttr( mailto( note ) ) + '">Reply by email</a>',
					'new' === note.status
						? '<button type="button" class="yp-row-action" data-yp-fb-act="done" data-id="' + note.id + '">Mark done</button>'
						: '<button type="button" class="yp-row-action" data-yp-fb-act="reopen" data-id="' + note.id + '">Reopen</button>',
					'<button type="button" class="yp-row-action" data-yp-fb-act="delete" data-id="' + note.id + '">Delete</button>'
				];

				return (
					'<article class="yp-record-card yp-review-admin yp-fb">' +
						'<header class="yp-review-admin__head">' +
							'<strong>' + YP.escapeHtml( note.name ) + '</strong>' +
							'<span class="yp-field__hint">' + YP.escapeHtml( note.created ) + ' · ' + YP.escapeHtml( note.email ) + '</span>' +
							( TYPE_PILLS[ note.type ] || '' ) +
							( 'new' === note.status ? '<span class="yp-pill yp-fb-pill--new">New</span>' : '<span class="yp-pill yp-pill--good">Done</span>' ) +
						'</header>' +
						'<p class="yp-review-admin__text">' + YP.escapeHtml( note.message ) + '</p>' +
						( shots ? '<div class="yp-review-admin__photos yp-fb__shots">' + shots + '</div>' : '' ) +
						( note.device ? '<p class="yp-fb__device">' + YP.escapeHtml( note.device ) + '</p>' : '' ) +
						( note.setup.length ? '<div class="yp-fb__setup"><strong>Tracker setup shared by customer</strong><ul>' + note.setup.map( function ( line ) {
							return '<li>' + YP.escapeHtml( line ) + '</li>';
						} ).join( '' ) + '</ul></div>' : '' ) +
						'<div class="yp-review-admin__actions">' + actions.join( ' ' ) + '</div>' +
					'</article>'
				);
			} ).join( '' );

			listEl.querySelectorAll( '[data-yp-fb-act]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var action = button.getAttribute( 'data-yp-fb-act' );
					var id     = button.getAttribute( 'data-id' );
					if ( 'delete' !== action ) {
						act( id, action, button );
						return;
					}
					YP.confirmModal( {
						title: 'Delete this note?',
						message: 'The note and its screenshots are removed for good.',
						confirmLabel: 'Delete note',
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
