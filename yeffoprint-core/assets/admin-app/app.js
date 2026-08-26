/**
 * The new custom admin dashboard's shell (docs/ARCHITECTURE.md, Phase 1
 * of the plan). Plain ES module, no bundler/framework — same "no
 * unjustified frameworks" stance as the rest of this project (see
 * assets/js/configurator.js's own docblock). Later phases are expected
 * to add their own view modules under this same directory and
 * dynamically `import()` them from renderView() below; nothing here
 * needs to change shape to accommodate that.
 *
 * Hash-routed (`#/materials`, `#/pricing`, …) rather than History API
 * routing — this page is reached at a fixed wp-admin URL
 * (admin.php?page=yeffoprint) and stays there; the hash is purely this
 * app's own internal view state, never sent to the server, so there's
 * no server-side route to keep in sync with.
 */

( function () {
	'use strict';

	if ( typeof yeffoprintAdminApp === 'undefined' ) {
		return;
	}

	/**
	 * One entry per planned section (docs/ARCHITECTURE.md's phase list).
	 * `id: 'dashboard'` is the only one with real content in Phase 1 —
	 * every other id renders the shared placeholder view until its own
	 * phase ships. Nothing here is a promise the *order* work ships in;
	 * it mirrors the plan's own grouping so the whole map is navigable
	 * from day one.
	 */
	var SECTIONS = [
		{ group: 'Overview', items: [
			{ id: 'dashboard', label: 'Dashboard' }
		] },
		{ group: 'Catalog', items: [
			{ id: 'materials', label: 'Materials' },
			{ id: 'sizes', label: 'Sizes' },
			{ id: 'sticker-sizes', label: 'Sticker Sizes' },
			{ id: 'templates', label: 'Templates' },
			{ id: 'field-presets', label: 'Field Presets' }
		] },
		{ group: 'Sales', items: [
			{ id: 'pricing', label: 'Pricing Rules' },
			{ id: 'orders', label: 'Custom Orders' },
			{ id: 'proofs', label: 'Proofs' },
			{ id: 'web-design-packages', label: 'Web Design Packages' },
			{ id: 'maintenance', label: 'Maintenance Subscribers' }
		] },
		{ group: 'Store', items: [
			{ id: 'rewards', label: 'Rewards' },
			{ id: 'surcharge', label: 'Card Surcharge' },
			{ id: 'settings', label: 'Settings' }
		] }
	];

	var root = document.getElementById( 'yp-admin-app' );
	if ( ! root ) {
		return;
	}

	var labelsById = {};
	SECTIONS.forEach( function ( group ) {
		group.items.forEach( function ( item ) {
			labelsById[ item.id ] = item.label;
		} );
	} );

	root.innerHTML =
		'<div class="yp-app">' +
			'<nav class="yp-app__nav">' +
				'<div class="yp-app__brand">' +
					'<div class="yp-app__mark"></div>' +
					'<div class="yp-app__wordmark">YeffoPrint</div>' +
				'</div>' +
				'<div class="yp-app__groups" data-yp-nav></div>' +
				'<div class="yp-app__foot">' +
					'<a class="yp-app__exit" href="' + escapeAttr( yeffoprintAdminApp.exitUrl ) + '">&larr; Exit to WordPress</a>' +
				'</div>' +
			'</nav>' +
			'<div class="yp-app__main">' +
				'<div class="yp-app__topbar">' +
					'<div class="yp-app__title" data-yp-title></div>' +
					'<div class="yp-app__status" data-yp-status data-state="loading"><span class="yp-app__status-dot"></span><span data-yp-status-text>Connecting&hellip;</span></div>' +
				'</div>' +
				'<div class="yp-app__view" data-yp-view></div>' +
			'</div>' +
		'</div>';

	var navEl = root.querySelector( '[data-yp-nav]' );
	var titleEl = root.querySelector( '[data-yp-title]' );
	var viewEl = root.querySelector( '[data-yp-view]' );
	var statusEl = root.querySelector( '[data-yp-status]' );
	var statusTextEl = root.querySelector( '[data-yp-status-text]' );

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}

	function escapeAttr( value ) {
		return escapeHtml( value ).replace( /"/g, '&quot;' );
	}

	/* ---------- Nav ---------- */

	navEl.innerHTML = SECTIONS.map( function ( group ) {
		var items = group.items.map( function ( item ) {
			return (
				'<button type="button" class="yp-nav-item" data-yp-nav-item="' + item.id + '">' +
					'<span class="yp-nav-item__dot"></span>' + escapeHtml( item.label ) +
				'</button>'
			);
		} ).join( '' );

		return (
			'<div>' +
				'<div class="yp-app__group-label">' + escapeHtml( group.group ) + '</div>' +
				'<div class="yp-app__items">' + items + '</div>' +
			'</div>'
		);
	} ).join( '' );

	navEl.querySelectorAll( '[data-yp-nav-item]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			window.location.hash = '#/' + button.getAttribute( 'data-yp-nav-item' );
		} );
	} );

	/* ---------- Router ---------- */

	function currentSectionId() {
		var hash = window.location.hash.replace( /^#\/?/, '' );
		return labelsById[ hash ] ? hash : 'dashboard';
	}

	function renderView( id ) {
		titleEl.textContent = labelsById[ id ] || 'Dashboard';

		navEl.querySelectorAll( '[data-yp-nav-item]' ).forEach( function ( button ) {
			button.classList.toggle( 'is-active', button.getAttribute( 'data-yp-nav-item' ) === id );
		} );

		if ( 'dashboard' === id ) {
			renderDashboard();
			return;
		}

		viewEl.innerHTML =
			'<div class="yp-placeholder">' +
				'<strong>' + escapeHtml( labelsById[ id ] ) + '</strong>' +
				'<span>This section’s screen ships in a later phase — the nav item is live now so the whole map is navigable from day one.</span>' +
			'</div>';
	}

	function route() {
		renderView( currentSectionId() );
	}

	window.addEventListener( 'hashchange', route );
	route();

	/* ---------- Dashboard: the one real REST round-trip in Phase 1 ---------- */

	function renderDashboard( retryNonce ) {
		viewEl.innerHTML =
			'<div class="yp-status-card" data-yp-dashboard-card>' +
				'<h2>Welcome back' + ( yeffoprintAdminApp.currentUserName ? ', ' + escapeHtml( yeffoprintAdminApp.currentUserName ) : '' ) + '</h2>' +
				'<p data-yp-dashboard-text>Checking the connection to YeffoPrint’s admin API&hellip;</p>' +
			'</div>';

		ping( retryNonce );
	}

	function setStatus( state, text ) {
		statusEl.setAttribute( 'data-state', state );
		statusTextEl.textContent = text;
	}

	function ping( nonce, isRetry ) {
		fetch( yeffoprintAdminApp.restUrl + 'admin/ping', {
			headers: { 'X-WP-Nonce': nonce || yeffoprintAdminApp.nonce }
		} )
			.then( function ( response ) {
				if ( 403 === response.status && ! isRetry ) {
					// Same stale-nonce recovery the storefront already
					// relies on (class-nonce-controller.php) — the page
					// itself might have been served from a cache that
					// predates this session.
					return fetch( yeffoprintAdminApp.restUrl + 'session/nonce' )
						.then( function ( r ) { return r.json(); } )
						.then( function ( data ) { return ping( data.nonce, true ); } );
				}
				if ( ! response.ok ) {
					throw new Error( 'not-ok' );
				}
				return response.json();
			} )
			.then( function ( data ) {
				if ( ! data || ! data.name ) {
					return; // Already retried above.
				}
				setStatus( 'connected', 'Connected as ' + data.name );
				var textEl = viewEl.querySelector( '[data-yp-dashboard-text]' );
				if ( textEl ) {
					textEl.textContent = 'The admin API is connected and working. Every section in the nav will fill in over the next few phases.';
				}
			} )
			.catch( function () {
				setStatus( 'error', 'Connection failed' );
				var card = viewEl.querySelector( '[data-yp-dashboard-card]' );
				if ( card ) {
					var textEl = card.querySelector( '[data-yp-dashboard-text]' );
					if ( textEl ) {
						textEl.textContent = 'Couldn’t reach the admin API. Refresh the page, or try again below.';
					}
					if ( ! card.querySelector( '[data-yp-retry]' ) ) {
						var retryBtn = document.createElement( 'button' );
						retryBtn.type = 'button';
						retryBtn.className = 'wp-block-button__link is-style-outline yp-status-card__retry';
						retryBtn.setAttribute( 'data-yp-retry', '' );
						retryBtn.textContent = 'Try again';
						retryBtn.addEventListener( 'click', function () { renderDashboard(); } );
						card.appendChild( retryBtn );
					}
				}
			} );
	}
} )();
