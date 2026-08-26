/**
 * The new custom admin dashboard's shell (docs/ARCHITECTURE.md). Plain
 * script, no bundler/framework/modules — same "no unjustified
 * frameworks" stance, and the same plain-IIFE style, as every other
 * script in this project (assets/js/configurator.js, site.js, …).
 *
 * Hash-routed (`#/materials`, `#/pricing`, …) rather than History API
 * routing — this page is reached at a fixed wp-admin URL
 * (admin.php?page=yeffoprint) and stays there; the hash is purely this
 * app's own internal view state, never sent to the server, so there's
 * no server-side route to keep in sync with.
 *
 * `window.YPAdminApp` is the shared surface every per-section view
 * script (assets/admin-app/views/*.js, each its own plain enqueued
 * script depending on this one) builds on: `request()` for every REST
 * call (nonce header + the same stale-nonce retry Phase 1's ping
 * already used), `escapeHtml`/`escapeAttr`, `bindMediaPicker()` for
 * wp.media fields, `openDrawer()`/`closeDrawer()` for the add/edit
 * forms, and `views` — the registry a view script adds itself to
 * (`YPAdminApp.views.materials = function (viewEl) {...}`) instead of
 * this file needing to know about it in advance.
 */

( function () {
	'use strict';

	if ( typeof yeffoprintAdminApp === 'undefined' ) {
		return;
	}

	var YP = window.YPAdminApp = window.YPAdminApp || {};
	YP.views = YP.views || {};

	/* ---------- Shared helpers (used by every view script) ---------- */

	YP.escapeHtml = function ( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	};

	YP.escapeAttr = function ( value ) {
		return YP.escapeHtml( value ).replace( /"/g, '&quot;' );
	};

	/**
	 * Every REST call in this app goes through here — WP core's own
	 * `/wp/v2/{type}` routes (Materials, Sizes, …) and this plugin's own
	 * `/admin/*` routes alike, since both accept the same `wp_rest`
	 * nonce. `url` is a full URL the caller builds from
	 * `yeffoprintAdminApp.restUrl` (`yeffoprint-core/v1/`) or
	 * `yeffoprintAdminApp.wpApiUrl` (`wp/v2/`).
	 *
	 * @return Promise resolving to the parsed JSON body (or null for a
	 *         204), rejecting with an Error carrying `.status` and
	 *         `.body` (the parsed error JSON, when there is one) on any
	 *         non-2xx response.
	 */
	YP.request = function ( url, options, nonce, isRetry ) {
		options = options || {};
		var headers = {};
		for ( var key in options.headers || {} ) { headers[ key ] = options.headers[ key ]; }
		headers[ 'X-WP-Nonce' ] = nonce || yeffoprintAdminApp.nonce;

		var fetchOptions = {};
		for ( var optKey in options ) { fetchOptions[ optKey ] = options[ optKey ]; }
		fetchOptions.headers = headers;

		return fetch( url, fetchOptions ).then( function ( response ) {
			if ( 403 === response.status && ! isRetry ) {
				// Same stale-nonce recovery the storefront's own REST calls
				// already rely on (class-nonce-controller.php) — the page
				// itself might have been served from a cache that predates
				// this session.
				return fetch( yeffoprintAdminApp.restUrl + 'session/nonce' )
					.then( function ( r ) { return r.json(); } )
					.then( function ( data ) { return YP.request( url, options, data.nonce, true ); } );
			}

			if ( 204 === response.status ) {
				return null;
			}

			return response.json().catch( function () { return null; } ).then( function ( body ) {
				if ( ! response.ok ) {
					var message = ( body && body.message ) || ( response.status + ' ' + response.statusText );
					var error = new Error( message );
					error.status = response.status;
					error.body = body;
					throw error;
				}
				return body;
			} );
		} );
	};

	/**
	 * Binds a wp.media picker to a set of elements by reference, not
	 * fixed DOM ids — unlike assets/admin/vial-mockup-picker.js's
	 * getElementById() version, this app's forms render fresh into a
	 * drawer each time they open, so there's no single stable id to
	 * bind once at DOMContentLoaded. Same wp.media usage otherwise
	 * (single image, "Use this image" button) for a consistent picker
	 * feel with the rest of wp-admin.
	 *
	 * `onSelect`/`onRemove` (optional) — views/templates.js uses these
	 * to keep the field-schema drag-position preview (a separate piece
	 * of the same drawer) in sync with whichever image is currently the
	 * Template's artwork, without this generic helper needing to know
	 * that caller-specific concern exists.
	 */
	YP.bindMediaPicker = function ( config ) {
		if ( typeof wp === 'undefined' || ! wp.media ) {
			return;
		}

		var frame;

		config.selectButton.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media( {
				title: config.title || 'Select image',
				multiple: false,
				library: { type: 'image' },
				button: { text: 'Use this image' }
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				config.idInput.value = attachment.id;
				config.preview.innerHTML = '<img src="' + YP.escapeAttr( attachment.url ) + '" alt="" />';
				if ( config.removeButton ) {
					config.removeButton.hidden = false;
				}
				if ( config.onSelect ) {
					config.onSelect( attachment );
				}
			} );

			frame.open();
		} );

		if ( config.removeButton ) {
			config.removeButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				config.idInput.value = '';
				config.preview.innerHTML = '';
				config.removeButton.hidden = true;
				if ( config.onRemove ) {
					config.onRemove();
				}
			} );
		}
	};

	/* ---------- Drawer primitive (this app's own lightweight version of
	   the storefront's .yp-drawer — same CSS classes/visual language,
	   reused from global.css, but site.js's actual openDrawer()/
	   closeDrawer() aren't loaded here and aren't exposed globally
	   anyway, so this is a small equivalent scoped to this app) ---------- */

	var openDrawerEls = [];

	YP.openDrawer = function ( drawerEl ) {
		drawerEl.dataset.open = 'true';
		drawerEl.setAttribute( 'aria-hidden', 'false' );
		document.body.style.overflow = 'hidden';
		openDrawerEls.push( drawerEl );

		var focusable = drawerEl.querySelector( 'input, select, textarea, button' );
		if ( focusable ) {
			focusable.focus();
		}
	};

	YP.closeDrawer = function ( drawerEl ) {
		drawerEl.dataset.open = 'false';
		drawerEl.setAttribute( 'aria-hidden', 'true' );
		openDrawerEls = openDrawerEls.filter( function ( el ) { return el !== drawerEl; } );
		if ( ! openDrawerEls.length ) {
			document.body.style.overflow = '';
		}
		drawerEl.remove(); // Drawers in this app are built fresh per open — see YP.views.* — so nothing needs the closed markup left in the DOM.
	};

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && openDrawerEls.length ) {
			YP.closeDrawer( openDrawerEls[ openDrawerEls.length - 1 ] );
		}
	} );

	/**
	 * Wires the standard backdrop-click / [data-yp-drawer-close] /
	 * Escape closing behavior onto a freshly-built drawer element — call
	 * once right after inserting it into the DOM.
	 */
	YP.initDrawer = function ( drawerEl ) {
		var backdrop = drawerEl.querySelector( '.yp-drawer__backdrop' );
		if ( backdrop ) {
			backdrop.addEventListener( 'click', function () { YP.closeDrawer( drawerEl ); } );
		}
		drawerEl.querySelectorAll( '[data-yp-drawer-close]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () { YP.closeDrawer( drawerEl ); } );
		} );
	};

	/**
	 * One entry per planned section (docs/ARCHITECTURE.md's phase list).
	 * `id`s with no matching `YP.views[id]` render the shared
	 * placeholder view until their own phase ships. Nothing here is a
	 * promise the *order* work ships in; it mirrors the plan's own
	 * grouping so the whole map is navigable from day one.
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
			'<div class="yp-app__nav-backdrop" data-yp-nav-backdrop></div>' +
			'<nav class="yp-app__nav" data-yp-nav-panel>' +
				'<div class="yp-app__brand">' +
					'<div class="yp-app__mark"></div>' +
					'<div class="yp-app__wordmark">YeffoPrint</div>' +
				'</div>' +
				'<div class="yp-app__groups" data-yp-nav></div>' +
				'<div class="yp-app__foot">' +
					'<a class="yp-app__exit" href="' + YP.escapeAttr( yeffoprintAdminApp.exitUrl ) + '">&larr; Exit to WordPress</a>' +
				'</div>' +
			'</nav>' +
			'<div class="yp-app__main">' +
				'<div class="yp-app__topbar">' +
					'<div class="yp-app__title-row">' +
						'<button type="button" class="yp-app__menu-toggle" data-yp-menu-toggle aria-label="Toggle menu" aria-expanded="false">' +
							'<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><line x1="2" y1="4.5" x2="16" y2="4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" /><line x1="2" y1="9" x2="16" y2="9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" /><line x1="2" y1="13.5" x2="16" y2="13.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" /></svg>' +
						'</button>' +
						'<div class="yp-app__title" data-yp-title></div>' +
					'</div>' +
					'<div class="yp-app__status" data-yp-status data-state="loading"><span class="yp-app__status-dot"></span><span data-yp-status-text>Connecting&hellip;</span></div>' +
				'</div>' +
				'<div class="yp-app__view" data-yp-view></div>' +
			'</div>' +
		'</div>';

	var navEl = root.querySelector( '[data-yp-nav]' );
	var navPanelEl = root.querySelector( '[data-yp-nav-panel]' );
	var navBackdropEl = root.querySelector( '[data-yp-nav-backdrop]' );
	var menuToggleEl = root.querySelector( '[data-yp-menu-toggle]' );
	var titleEl = root.querySelector( '[data-yp-title]' );
	var viewEl = root.querySelector( '[data-yp-view]' );
	var statusEl = root.querySelector( '[data-yp-status]' );
	var statusTextEl = root.querySelector( '[data-yp-status-text]' );

	/* ---------- Mobile off-canvas nav ---------- */

	function openMobileNav() {
		navPanelEl.classList.add( 'is-open' );
		navBackdropEl.classList.add( 'is-open' );
		menuToggleEl.setAttribute( 'aria-expanded', 'true' );
	}

	function closeMobileNav() {
		navPanelEl.classList.remove( 'is-open' );
		navBackdropEl.classList.remove( 'is-open' );
		menuToggleEl.setAttribute( 'aria-expanded', 'false' );
	}

	menuToggleEl.addEventListener( 'click', function () {
		if ( navPanelEl.classList.contains( 'is-open' ) ) {
			closeMobileNav();
		} else {
			openMobileNav();
		}
	} );
	navBackdropEl.addEventListener( 'click', closeMobileNav );
	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key ) {
			closeMobileNav();
		}
	} );

	/* ---------- Nav ---------- */

	navEl.innerHTML = SECTIONS.map( function ( group ) {
		var items = group.items.map( function ( item ) {
			return (
				'<button type="button" class="yp-nav-item" data-yp-nav-item="' + item.id + '">' +
					'<span class="yp-nav-item__dot"></span>' + YP.escapeHtml( item.label ) +
				'</button>'
			);
		} ).join( '' );

		return (
			'<div>' +
				'<div class="yp-app__group-label">' + YP.escapeHtml( group.group ) + '</div>' +
				'<div class="yp-app__items">' + items + '</div>' +
			'</div>'
		);
	} ).join( '' );

	navEl.querySelectorAll( '[data-yp-nav-item]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			window.location.hash = '#/' + button.getAttribute( 'data-yp-nav-item' );
			closeMobileNav(); // No-op above the mobile breakpoint — is-open is never set there.
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

		if ( YP.views[ id ] ) {
			YP.views[ id ]( viewEl );
			return;
		}

		viewEl.innerHTML =
			'<div class="yp-placeholder">' +
				'<strong>' + YP.escapeHtml( labelsById[ id ] ) + '</strong>' +
				'<span>This section’s screen ships in a later phase — the nav item is live now so the whole map is navigable from day one.</span>' +
			'</div>';
	}

	function route() {
		renderView( currentSectionId() );
	}

	window.addEventListener( 'hashchange', route );

	/* ---------- Dashboard: the one real REST round-trip in Phase 1 ---------- */

	function renderDashboard() {
		viewEl.innerHTML =
			'<div class="yp-status-card" data-yp-dashboard-card>' +
				'<h2>Welcome back' + ( yeffoprintAdminApp.currentUserName ? ', ' + YP.escapeHtml( yeffoprintAdminApp.currentUserName ) : '' ) + '</h2>' +
				'<p data-yp-dashboard-text>Checking the connection to YeffoPrint’s admin API&hellip;</p>' +
			'</div>';

		ping();
	}

	function setStatus( state, text ) {
		statusEl.setAttribute( 'data-state', state );
		statusTextEl.textContent = text;
	}

	function ping() {
		YP.request( yeffoprintAdminApp.restUrl + 'admin/ping' )
			.then( function ( data ) {
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
						retryBtn.addEventListener( 'click', renderDashboard );
						card.appendChild( retryBtn );
					}
				}
			} );
	}

	// View scripts (assets/admin-app/views/*.js) are enqueued with a
	// dependency on this one and register into YP.views as soon as they
	// load — but all of them, this file included, load with the
	// `defer` strategy, which the HTML spec guarantees run in order
	// *before* DOMContentLoaded fires. Calling route() only once that
	// event fires (rather than synchronously at the end of this file,
	// Phase 1's own approach) is what guarantees every view script has
	// already had the chance to register before the very first route()
	// call might need it.
	document.addEventListener( 'DOMContentLoaded', route );
} )();
