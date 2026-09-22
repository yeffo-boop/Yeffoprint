/**
 * Public Progress Reports & Site Activity page — direct request: "let
 * them access all of the changes that have been made on the site."
 * Read-only (no writes here, unlike agreement/staging/go-live): staff
 * compose progress reports from the order drawer, and site-activity
 * entries arrive from the project's own nightly digest webhook
 * (class-web-design-digest-controller.php). Same one-time-link/token
 * trust model as the other three Web Design pages.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintWebDesign === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'yp-wd-updates' );
	if ( ! root ) {
		return;
	}

	var statusEl = root.querySelector( '.yp-configurator__status' );
	var contentEl = root.querySelector( '[data-yp-wd-content]' );

	var params = new URLSearchParams( window.location.search );
	var orderId = params.get( 'order' );
	var token = params.get( 'token' ) || '';

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}

	function showError( message ) {
		statusEl.textContent = message;
		statusEl.setAttribute( 'data-state', 'error' );
		statusEl.hidden = false;
		contentEl.hidden = true;
	}

	function apiUrl( path ) {
		var url = yeffoprintWebDesign.restUrl + 'web-design/' + encodeURIComponent( orderId ) + path;
		return token ? url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'token=' + encodeURIComponent( token ) : url;
	}

	function formatDate( mysqlDate ) {
		if ( ! mysqlDate ) {
			return '';
		}
		return new Date( mysqlDate.replace( ' ', 'T' ) ).toLocaleString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } );
	}

	function reportHtml( report ) {
		return (
			'<div class="yp-wd-card yp-wd-report">' +
				'<p class="yp-wd-report-date">' + escapeHtml( formatDate( report.created_at ) ) + '</p>' +
				'<h3>' + escapeHtml( report.headline ) + '</h3>' +
				( report.message ? '<p>' + escapeHtml( report.message ).replace( /\n/g, '<br>' ) + '</p>' : '' ) +
			'</div>'
		);
	}

	function prChipsHtml( prs ) {
		if ( ! prs || ! prs.length ) {
			return '';
		}
		return '<div class="yp-wd-pr-chips">' + prs.map( function ( pr ) {
			return '<span class="yp-wd-pr-chip">#' + escapeHtml( pr.number ) + ( pr.title ? ' ' + escapeHtml( pr.title ) : '' ) + '</span>';
		} ).join( '' ) + '</div>';
	}

	function dayHtml( day ) {
		var items = day.items || [];
		return (
			'<div class="yp-wd-card yp-wd-update-day">' +
				'<div class="yp-wd-update-day-head">' +
					'<h3>' + escapeHtml( formatDate( day.date ) ) + '</h3>' +
					'<span class="yp-field__hint">' + items.length + ' change' + ( 1 === items.length ? '' : 's' ) + ' shipped</span>' +
				'</div>' +
				items.map( function ( item, i ) {
					return (
						'<div class="yp-wd-change-row">' +
							'<span class="yp-wd-change-num">' + ( i + 1 ) + '</span>' +
							'<div>' +
								'<h4>' + escapeHtml( item.headline ) + '</h4>' +
								( item.description ? '<p>' + escapeHtml( item.description ) + '</p>' : '' ) +
								prChipsHtml( item.prs ) +
							'</div>' +
						'</div>'
					);
				} ).join( '' ) +
			'</div>'
		);
	}

	function render( data ) {
		root.querySelector( '[data-yp-wd-package]' ).textContent = 'Updates for ' + data.package_name;

		var reportsEl = root.querySelector( '[data-yp-wd-reports]' );
		reportsEl.innerHTML = data.progress_reports.length
			? data.progress_reports.map( reportHtml ).join( '' )
			: '<p class="yp-field__hint">No progress reports yet — check back soon.</p>';

		var updatesEl = root.querySelector( '[data-yp-wd-updates-list]' );
		updatesEl.innerHTML = data.site_updates.length
			? data.site_updates.map( dayHtml ).join( '' )
			: '<p class="yp-field__hint">No site activity yet — check back once work is underway.</p>';

		statusEl.hidden = true;
		contentEl.hidden = false;
	}

	function load() {
		if ( ! orderId ) {
			showError( "This link is missing information and can't be loaded. Please use the exact link you were sent." );
			return;
		}

		fetch( apiUrl( '/updates' ), { headers: { 'X-WP-Nonce': yeffoprintWebDesign.nonce } } )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					showError( ( result.data && result.data.message ) || "This page couldn't be loaded." );
					return;
				}
				render( result.data );
			} )
			.catch( function () {
				showError( "Couldn't reach the server — please try again." );
			} );
	}

	root.querySelectorAll( '[data-yp-wd-tab]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var target = button.getAttribute( 'data-yp-wd-tab' );

			root.querySelectorAll( '[data-yp-wd-tab]' ).forEach( function ( b ) { b.classList.toggle( 'is-active', b === button ); } );
			root.querySelectorAll( '[data-yp-wd-panel]' ).forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-yp-wd-panel' ) !== target;
			} );
		} );
	} );

	load();
} )();
