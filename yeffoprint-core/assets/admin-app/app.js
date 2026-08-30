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
			{ id: 'manual-order', label: 'Create Order' },
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
					'<div class="yp-app__wordmark">YeffoDesign</div>' +
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

	/**
	 * `#/{section}` or `#/{section}/{subId}` — the optional second
	 * segment (Phase 6: `#/orders/123`) lets the Dashboard home view
	 * link a row straight to that record's own detail in the target
	 * screen, rather than only ever landing on a section's bare list.
	 * Not a general nested-router: each view script decides for itself
	 * what its own `subId` means (an id to auto-open, or nothing).
	 */
	function currentSection() {
		var hash  = window.location.hash.replace( /^#\/?/, '' );
		var parts = hash.split( '/' );
		var id    = labelsById[ parts[ 0 ] ] ? parts[ 0 ] : 'dashboard';
		return { id: id, subId: parts[ 1 ] || '' };
	}

	function renderView( id, subId ) {
		titleEl.textContent = labelsById[ id ] || 'Dashboard';

		navEl.querySelectorAll( '[data-yp-nav-item]' ).forEach( function ( button ) {
			button.classList.toggle( 'is-active', button.getAttribute( 'data-yp-nav-item' ) === id );
		} );

		if ( 'dashboard' === id ) {
			renderDashboard();
			return;
		}

		if ( YP.views[ id ] ) {
			YP.views[ id ]( viewEl, subId );
			return;
		}

		viewEl.innerHTML =
			'<div class="yp-placeholder">' +
				'<strong>' + YP.escapeHtml( labelsById[ id ] ) + '</strong>' +
				'<span>This section’s screen ships in a later phase — the nav item is live now so the whole map is navigable from day one.</span>' +
			'</div>';
	}

	function route() {
		var section = currentSection();
		renderView( section.id, section.subId );
	}

	window.addEventListener( 'hashchange', route );

	/* ---------- Dashboard home (Phase 6) ----------
	   Five sections, all from one `/admin/dashboard-summary` call. Four
	   mirror the classic reskin's own YeffoPrint_Dashboard_Widgets
	   (includes/admin/class-dashboard-widgets.php): Pending Orders (native
	   WooCommerce, still "Processing"), Pending Proofs and Awaiting Customer
	   Approval (the two yp_custom_order pipeline stages that need staff
	   action), and Active Maintenance Subscribers. The fifth, Shipped
	   Packages, is new (package tracking, direct request) — every order
	   currently in the "Shipped" status (class-order-shipment-status.php),
	   one row per physical package/tracking number. A custom-order row
	   jumps straight into its own detail via the router's subId
	   (`#/orders/{id}`, app.js's currentSection()) rather than only ever
	   landing on the Orders list.

	   Pending Orders also carries a "Send to Printer" button per row
	   (direct request) — a one-click manual transition from "Processing"
	   to the new "In Production" status (class-order-production-status.php)
	   via YeffoPrint_Admin_Order_Controller::send_to_printer(). It doesn't
	   dispatch anything to a real printer; it's a status flag, and the row
	   simply drops off this panel once it moves (this panel only ever
	   queries "Processing" orders), same as any other status change. */

	function setStatus( state, text ) {
		statusEl.setAttribute( 'data-state', state );
		statusTextEl.textContent = text;
	}

	function ping() {
		YP.request( yeffoprintAdminApp.restUrl + 'admin/ping' )
			.then( function ( data ) { setStatus( 'connected', 'Connected as ' + data.name ); } )
			.catch( function () { setStatus( 'error', 'Connection failed' ); } );
	}

	function renderDashboard() {
		viewEl.innerHTML =
			'<p class="yp-app__intro">Welcome back' + ( yeffoprintAdminApp.currentUserName ? ', ' + YP.escapeHtml( yeffoprintAdminApp.currentUserName ) : '' ) + '. Here’s what needs attention today.</p>' +
			'<div data-yp-dashboard><p class="yp-field__hint">Loading&hellip;</p></div>';

		ping();
		loadDashboard();
	}

	function loadDashboard() {
		var el = viewEl.querySelector( '[data-yp-dashboard]' );
		if ( ! el ) {
			return; // Navigated away before this finished loading.
		}

		YP.request( yeffoprintAdminApp.restUrl + 'admin/dashboard-summary' )
			.then( function ( summary ) { renderDashboardSummary( summary, el ); } )
			.catch( function ( error ) {
				el.innerHTML =
					'<p class="yp-form__error">Couldn’t load the dashboard: ' + YP.escapeHtml( error.message ) + '</p>' +
					'<button type="button" class="wp-block-button__link is-style-outline" data-yp-dashboard-retry>Try again</button>';
				el.querySelector( '[data-yp-dashboard-retry]' ).addEventListener( 'click', loadDashboard );
			} );
	}

	function daysAgoLabel( isoDate, dueDateDays ) {
		if ( ! isoDate ) {
			return { text: '—', overdue: false };
		}
		var daysOpen = Math.floor( ( Date.now() - new Date( isoDate ).getTime() ) / 86400000 );
		if ( daysOpen > dueDateDays ) {
			var overdueBy = daysOpen - dueDateDays;
			return { text: overdueBy + ( 1 === overdueBy ? ' day overdue' : ' days overdue' ), overdue: true };
		}
		return { text: daysOpen + ( 1 === daysOpen ? ' day ago' : ' days ago' ), overdue: false };
	}

	function dashboardSectionHtml( title, description, viewAllHref, rows, dueDateDays, onOrderClick, rowAction, clickAttr, actionHeader ) {
		var body;
		if ( ! rows.length ) {
			body = '<p class="yp-field__hint">Nothing here right now.</p>';
		} else {
			body =
				'<table class="yp-record-table"><thead><tr><th>Order</th><th>Customer</th><th>Date</th>' + ( rowAction ? '<th>' + YP.escapeHtml( actionHeader || '' ) + '</th>' : '' ) + '</tr></thead><tbody>' +
					rows.map( function ( row ) {
						var age = daysAgoLabel( row.date, dueDateDays );
						var label = onOrderClick
							? '<button type="button" class="yp-row-action" style="padding:0;font-weight:600;" ' + ( clickAttr || 'data-yp-dashboard-order' ) + '="' + row.id + '">' + YP.escapeHtml( row.label ) + '</button>'
							: '<a href="' + YP.escapeAttr( row.edit_url ) + '">' + YP.escapeHtml( row.label ) + '</a>';
						return (
							'<tr>' +
								'<td>' + label + '</td>' +
								'<td>' + YP.escapeHtml( row.customer || '—' ) + '</td>' +
								'<td>' + ( age.overdue ? '<span class="yp-pill yp-pill--crit">' + age.text + '</span>' : age.text ) + '</td>' +
								( rowAction ? '<td>' + rowAction( row ) + '</td>' : '' ) +
							'</tr>'
						);
					} ).join( '' ) +
				'</tbody></table>';
		}

		return (
			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>' + YP.escapeHtml( title ) + '</h2>' + ( viewAllHref ? '<a href="' + YP.escapeAttr( viewAllHref ) + '">View all &rarr;</a>' : '' ) + '</div>' +
				'<p class="yp-panel__hint">' + YP.escapeHtml( description ) + '</p>' +
				body +
			'</div>'
		);
	}

	/**
	 * Shippo's live-tracking status vocabulary (class-shippo-client.php's
	 * track(), UPPERCASE) mapped to a pill — direct request: "I want live
	 * tracking to show... so I can keep track of any packages that are
	 * taking too long to deliver or get lost in transit." FAILURE/RETURNED
	 * are exactly that signal, so they get the same crit (red) treatment
	 * as an overdue date elsewhere on this dashboard; DELIVERED barely
	 * matters here in practice since a delivered order drops off this
	 * whole panel the moment the next sweep marks it Completed, but is
	 * still handled for the brief window between "Shippo confirms
	 * delivered" and "the next hourly sweep runs."
	 */
	var TRACKING_STATUS_LABELS = {
		DELIVERED:   [ 'Delivered', 'good' ],
		TRANSIT:     [ 'In Transit', 'neutral' ],
		PRE_TRANSIT: [ 'Label Created', 'neutral' ],
		FAILURE:     [ 'Delivery Failed', 'crit' ],
		RETURNED:    [ 'Returned to Sender', 'crit' ],
		UNKNOWN:     [ 'Unknown', 'neutral' ]
	};

	function trackingStatusPillHtml( status ) {
		var entry = TRACKING_STATUS_LABELS[ status ] || null;
		if ( ! entry ) {
			return '<span class="yp-pill yp-pill--neutral">Not checked yet</span>';
		}
		return '<span class="yp-pill yp-pill--' + entry[ 1 ] + '">' + YP.escapeHtml( entry[ 0 ] ) + '</span>';
	}

	function timeAgoLabel( unixSeconds ) {
		if ( ! unixSeconds ) {
			return '';
		}
		var minutes = Math.round( ( Date.now() / 1000 - unixSeconds ) / 60 );
		if ( minutes < 1 ) {
			return 'checked just now';
		}
		if ( minutes < 60 ) {
			return 'checked ' + minutes + ( 1 === minutes ? ' min ago' : ' mins ago' );
		}
		var hours = Math.round( minutes / 60 );
		return 'checked ' + hours + ( 1 === hours ? ' hour ago' : ' hours ago' );
	}

	function renderDashboardSummary( summary, el ) {
		var dueDateDays = summary.due_date_days;

		var shippedBody = summary.shipped_packages.length
			? '<table class="yp-record-table"><thead><tr><th>Order</th><th>Customer</th><th>Carrier</th><th>Tracking #</th><th>Status</th></tr></thead><tbody>' +
				summary.shipped_packages.map( function ( pkg ) {
					var tracking = pkg.tracking_url
						? '<a href="' + YP.escapeAttr( pkg.tracking_url ) + '" target="_blank" rel="noopener noreferrer">' + YP.escapeHtml( pkg.tracking_number ) + '</a>'
						: YP.escapeHtml( pkg.tracking_number );
					var checkedAgo = timeAgoLabel( pkg.tracking_checked_at );
					return (
						'<tr>' +
							'<td><a href="' + YP.escapeAttr( pkg.edit_url ) + '">' + YP.escapeHtml( pkg.order_label ) + '</a></td>' +
							'<td>' + YP.escapeHtml( pkg.customer || '—' ) + '</td>' +
							'<td><span class="yp-chip">' + YP.escapeHtml( pkg.carrier_label || '—' ) + '</span></td>' +
							'<td>' + tracking + '</td>' +
							'<td>' + trackingStatusPillHtml( pkg.tracking_status ) +
								( checkedAgo ? '<div class="yp-record-name__sub">' + YP.escapeHtml( checkedAgo ) + '</div>' : '' ) +
							'</td>' +
						'</tr>'
					);
				} ).join( '' ) +
			'</tbody></table>'
			: '<p class="yp-field__hint">Nothing here right now.</p>';

		var maintenanceBody = summary.maintenance_subscribers.length
			? '<table class="yp-record-table"><thead><tr><th>Customer</th><th>Plan</th><th>Renews</th></tr></thead><tbody>' +
				summary.maintenance_subscribers.map( function ( sub ) {
					return '<tr><td>' + YP.escapeHtml( sub.name ) + '</td><td>' + YP.escapeHtml( sub.plan || '—' ) + '</td><td>' + ( sub.renews ? new Date( sub.renews * 1000 ).toLocaleDateString() : '—' ) + '</td></tr>';
				} ).join( '' ) +
			'</tbody></table>'
			: '<p class="yp-field__hint">Nothing here right now.</p>';

		/**
		 * Direct report: clicking "Send to Printer" moved an order to
		 * "In Production" (class-order-production-status.php) but this
		 * panel's REST query only ever asked for "processing" orders, so
		 * the row just vanished — staff lost track of it. Now the query
		 * includes both statuses (class-admin-dashboard-controller.php's
		 * pending_wc_orders()), so this only offers the Send to Printer
		 * button on rows still actually in Processing.
		 *
		 * Follow-up direct request: an In Production row's status pill
		 * alone wasn't enough — staff wanted the same one-click
		 * convenience for the *next* pipeline step, printing the actual
		 * shipping label, without opening the drawer first. When
		 * WooCommerce Shipping is active (summary.shipping_label_available,
		 * the same site-wide check the drawer's own panel already gates
		 * on), an In Production row gets a "Print Shipping Label" button
		 * instead of a plain pill; clicking it opens the drawer and
		 * immediately triggers the same embed the drawer's own button
		 * would (openWcOrderDrawer()'s autoPrintLabel param). Falls back
		 * to the plain pill only when that plugin isn't active at all.
		 */
		function sendToPrinterButtonHtml( row ) {
			if ( 'processing' !== row.status ) {
				if ( summary.shipping_label_available ) {
					return '<button type="button" class="wp-block-button__link is-style-outline yp-row-action" style="padding:4px 10px;font-size:12px;" data-yp-print-label-row="' + row.id + '">Print Shipping Label</button>';
				}
				return '<span class="yp-pill yp-pill--good">' + YP.escapeHtml( row.status_label ) + '</span>';
			}
			return '<button type="button" class="wp-block-button__link is-style-outline yp-row-action" style="padding:4px 10px;font-size:12px;" data-yp-send-to-printer="' + row.id + '">Send to Printer</button>';
		}

		el.innerHTML =
			dashboardSectionHtml( 'Pending Orders', 'Paid, not yet shipped — processing or in production.', summary.pending_orders_url, summary.pending_orders, dueDateDays, true, sendToPrinterButtonHtml, 'data-yp-wc-order', 'Status' ) +
			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>Shipped Packages</h2><button type="button" class="yp-row-action" data-yp-refresh-tracking>Check tracking now</button></div>' +
				'<p class="yp-panel__hint">Shipped, not yet delivered — every label with a tracking number currently in transit. Delivered packages automatically move the order to Completed and drop off this list.</p>' +
				shippedBody +
			'</div>' +
			dashboardSectionHtml( 'Pending Proofs', 'Custom orders staff still owes a proof — brand new, or the customer just requested changes.', '#/orders', summary.pending_proofs, dueDateDays, true ) +
			dashboardSectionHtml( 'Awaiting Customer Approval', 'A proof has been sent — waiting on the customer to approve it or request changes.', '#/orders', summary.awaiting_approval, dueDateDays, true ) +
			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>Active Maintenance Subscribers</h2><a href="#/maintenance">View all &rarr;</a></div>' +
				'<p class="yp-panel__hint">Customers currently paying for ongoing site maintenance &amp; monitoring.</p>' +
				maintenanceBody +
			'</div>';

		el.querySelectorAll( '[data-yp-dashboard-order]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				window.location.hash = '#/orders/' + button.getAttribute( 'data-yp-dashboard-order' );
			} );
		} );

		el.querySelectorAll( '[data-yp-wc-order]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				openWcOrderDrawer( parseInt( button.getAttribute( 'data-yp-wc-order' ), 10 ) );
			} );
		} );

		el.querySelectorAll( '[data-yp-print-label-row]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				openWcOrderDrawer( parseInt( button.getAttribute( 'data-yp-print-label-row' ), 10 ), true );
			} );
		} );

		el.querySelectorAll( '[data-yp-send-to-printer]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var orderId = button.getAttribute( 'data-yp-send-to-printer' );
				button.disabled = true;
				button.textContent = 'Sending…';
				YP.request( yeffoprintAdminApp.restUrl + 'admin/order/' + orderId + '/send-to-printer', { method: 'POST' } )
					.then( loadDashboard )
					.catch( function ( error ) {
						button.disabled = false;
						button.textContent = 'Send to Printer';
						window.alert( 'Couldn’t send to printer: ' + error.message );
					} );
			} );
		} );

		var refreshTrackingButton = el.querySelector( '[data-yp-refresh-tracking]' );
		if ( refreshTrackingButton ) {
			refreshTrackingButton.addEventListener( 'click', function () {
				refreshTrackingButton.disabled = true;
				refreshTrackingButton.textContent = 'Checking…';
				YP.request( yeffoprintAdminApp.restUrl + 'admin/dashboard/refresh-tracking', { method: 'POST' } )
					.then( function ( summary ) { renderDashboardSummary( summary, el ); } )
					.catch( function ( error ) {
						refreshTrackingButton.disabled = false;
						refreshTrackingButton.textContent = 'Check tracking now';
						window.alert( 'Couldn’t refresh tracking: ' + error.message );
					} );
			} );
		}
	}

	/* ---------- Pending Orders detail drawer ----------
	   Direct request: staff want the same "click a row, see everything
	   in a sidebar" experience the Custom Orders screen already has
	   (orders.js's openDetail/loadDetail/renderDetail, same pattern
	   replicated here) for a normal paid WooCommerce order too, backed
	   by class-admin-order-controller.php's detail_payload(). */

	function wcOrderRow( label, valueHtml ) {
		return '<tr><th>' + YP.escapeHtml( label ) + '</th><td>' + valueHtml + '</td></tr>';
	}

	/**
	 * `autoPrintLabel` (direct request) — the dashboard's Pending Orders
	 * panel offers a one-click "Print Shipping Label" button on an
	 * In Production row, matching the existing one-click "Send to
	 * Printer" on a Processing row (renderDashboardSummary() below).
	 * Rather than duplicate the drawer/embed logic for a dashboard-only
	 * shortcut, this just opens the same drawer and, once loaded,
	 * immediately triggers the same embedShippingLabel() the drawer's
	 * own "Print Shipping Label" button calls — skipping the extra click
	 * inside the drawer, not a different code path.
	 */
	function openWcOrderDrawer( id, autoPrintLabel ) {
		var drawer = document.createElement( 'div' );
		// --center (global.css's own splash-screen modal treatment) +
		// --wide (widened further specifically for this combination —
		// records.css) together, direct request: "move the whole order
		// screen from a sidebar to a modal window so it's bigger."
		drawer.className = 'yp-drawer yp-drawer--wide yp-drawer--center';
		drawer.setAttribute( 'aria-hidden', 'true' );
		drawer.innerHTML =
			'<div class="yp-drawer__backdrop"></div>' +
			'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="Order detail">' +
				'<div class="yp-drawer__header"><span class="yp-drawer__title-group" data-yp-drawer-title>Order detail</span>' +
					'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
				'</div>' +
				'<div class="yp-drawer__body" data-yp-body><p class="yp-field__hint">Loading&hellip;</p></div>' +
			'</div>';

		document.body.appendChild( drawer );
		YP.initDrawer( drawer );
		YP.openDrawer( drawer );

		loadWcOrderDetail( id, drawer, autoPrintLabel );
	}

	function loadWcOrderDetail( id, drawer, autoPrintLabel ) {
		var bodyEl = drawer.querySelector( '[data-yp-body]' );
		YP.request( yeffoprintAdminApp.restUrl + 'admin/order/' + id )
			.then( function ( order ) { renderWcOrderDetail( order, drawer, bodyEl, autoPrintLabel ); } )
			.catch( function ( error ) {
				bodyEl.innerHTML = '<p class="yp-form__error">Couldn’t load this order: ' + YP.escapeHtml( error.message ) + '</p>';
			} );
	}

	/**
	 * Item image (item.image_url — class-admin-order-controller.php's
	 * item_payload(), the linked product's own image, which for a
	 * Template line item is always that template's featured image) —
	 * direct request: "I'd like to see the picture of the template."
	 * Reuses .yp-swatch, the same circular thumbnail treatment every
	 * list screen already uses (Materials, Sizes, etc.), rather than a
	 * new image style just for this table. A Custom Design/Sticker line
	 * item's linked product has no image, so the swatch renders as a
	 * plain placeholder circle in that case — still reads as "an item
	 * row," not a broken image.
	 */
	function wcOrderItemsHtml( items ) {
		if ( ! items.length ) {
			return '<p class="yp-field__hint">No line items.</p>';
		}
		return '<table class="yp-record-table yp-record-table--top"><thead><tr><th>Item</th><th>Qty</th><th>Total</th></tr></thead><tbody>' +
			items.map( function ( item ) {
				var metaHtml = item.meta.length
					? '<dl class="yp-order-item-meta">' +
						item.meta.map( function ( m ) {
							return '<dt>' + YP.escapeHtml( m.label ) + '</dt><dd>' + m.value + '</dd>';
						} ).join( '' ) +
					'</dl>'
					: '';
				var thumbHtml = item.image_url
					? '<img class="yp-swatch" src="' + YP.escapeAttr( item.image_url ) + '" alt="">'
					: '<span class="yp-swatch" aria-hidden="true"></span>';
				return (
					'<tr>' +
						'<td><div class="yp-record-name">' + thumbHtml + '<div>' + YP.escapeHtml( item.name ) + metaHtml + '</div></div></td>' +
						'<td>' + item.quantity + '</td>' +
						'<td>$' + item.total.toFixed( 2 ) + '</td>' +
					'</tr>'
				);
			} ).join( '' ) +
		'</tbody></table>';
	}

	/**
	 * Direct request: "can we add the rewards info to this screen? Like
	 * how many points this order will receive (or has received)?" Same
	 * processed-vs-pending wording as the classic order screen's own
	 * "Rewards Points" meta box (class-rewards-order-box.php) — "will
	 * earn" before the order's paid (a live estimate, order.rewards.
	 * processed is false), "earned" once it actually has been.
	 */
	function wcOrderRewardsLine( rewards ) {
		if ( ! rewards || rewards.guest ) {
			return 'Rewards: guest order — not eligible for points.';
		}

		if ( ! rewards.earned && ! rewards.redeemed ) {
			return rewards.processed
				? 'Rewards: no points were earned or redeemed on this order.'
				: 'Rewards: no points will be earned or redeemed on this order.';
		}

		var parts = [];
		if ( rewards.earned ) {
			parts.push( ( rewards.processed ? 'Earned: +' : 'Will earn: +' ) + rewards.earned + ' points' );
		}
		if ( rewards.redeemed ) {
			parts.push( ( rewards.processed ? 'Redeemed: −' : 'Will redeem: −' ) + rewards.redeemed + ' points' );
		}

		return 'Rewards: ' + parts.join( ' · ' );
	}

	/**
	 * Semantic color for the order-number/status pill in the drawer
	 * header below — same good/neutral/warn/crit vocabulary as every
	 * other pill in this app (trackingStatusPillHtml() above, the
	 * Pending Orders age pill), not a new one just for this. Falls back
	 * to neutral for any status this doesn't explicitly know (e.g. a
	 * plugin-added custom status), so an unrecognized key never breaks
	 * rendering.
	 */
	var WC_ORDER_STATUS_PILLS = {
		completed:      'good',
		shipped:        'good',
		processing:     'neutral',
		'in-production': 'neutral',
		'on-hold':      'warn',
		pending:        'warn',
		cancelled:      'crit',
		refunded:       'crit',
		failed:         'crit'
	};

	function renderWcOrderDetail( order, drawer, bodyEl, autoPrintLabel ) {
		// Direct request: the modal "wasn't visually pleasing" — the header
		// used to just say the generic "Order detail" the whole time. Now
		// that the order's actually loaded, it names the order and shows
		// its status right where staff are already looking, instead of
		// making them scroll down into the Status panel to see either.
		var titleEl = drawer.querySelector( '[data-yp-drawer-title]' );
		if ( titleEl ) {
			titleEl.innerHTML =
				'Order #' + YP.escapeHtml( String( order.number ) ) +
				'<span class="yp-pill yp-pill--' + ( WC_ORDER_STATUS_PILLS[ order.status ] || 'neutral' ) + '">' + YP.escapeHtml( order.status_label ) + '</span>';
		}

		var rowsHtml = wcOrderRow(
			'Customer',
			YP.escapeHtml( order.customer_name || '' ) + ( order.customer_email ? ' — <a href="mailto:' + YP.escapeAttr( order.customer_email ) + '">' + YP.escapeHtml( order.customer_email ) + '</a>' : '' ) +
				( order.customer_phone ? ' — ' + YP.escapeHtml( order.customer_phone ) : '' )
		);
		rowsHtml += wcOrderRow( 'Shipping Address', order.shipping_address ? order.shipping_address.replace( /\n/g, '<br>' ) : '—' );
		rowsHtml += wcOrderRow( 'Payment Method', YP.escapeHtml( order.payment_method_title || '—' ) );
		if ( order.customer_note ) {
			rowsHtml += wcOrderRow( 'Customer Note', YP.escapeHtml( order.customer_note ).replace( /\n/g, '<br>' ) );
		}
		rowsHtml += wcOrderRow( 'Date', order.date ? new Date( order.date ).toLocaleString() : '—' );

		// Two columns now that the modal (records.css's
		// .yp-drawer--wide.yp-drawer--center override) has the room for
		// them: order/items/status on the left (the "what was ordered"
		// story, read top to bottom), both shipping panels stacked on
		// the right (the "get it out the door" actions) — rather than
		// one long list where the two shipping panels used to push
		// Status halfway down the sidebar.
		bodyEl.innerHTML =
			'<div class="yp-order-detail-grid">' +
				'<div>' +
					'<div class="yp-record-card"><table class="yp-record-table yp-record-table--wrap"><tbody>' + rowsHtml + '</tbody></table></div>' +

					'<div class="yp-panel">' +
						'<div class="yp-panel__head"><h2>Items</h2></div>' +
						wcOrderItemsHtml( order.items ) +
						'<p class="yp-panel__hint" style="margin-top:0.75rem;">Subtotal: $' + order.subtotal.toFixed( 2 ) + ' &nbsp;·&nbsp; Shipping: $' + order.shipping_total.toFixed( 2 ) + ' &nbsp;·&nbsp; <strong>Total: $' + order.total.toFixed( 2 ) + '</strong></p>' +
						'<p class="yp-panel__hint">' + wcOrderRewardsLine( order.rewards ) + '</p>' +
					'</div>' +

					'<div class="yp-panel">' +
						'<div class="yp-panel__head"><h2>Status</h2></div>' +
						'<div class="yp-form__row"><div class="yp-field"><select data-yp-wc-status>' +
							Object.keys( order.statuses ).map( function ( key ) {
								return '<option value="' + YP.escapeAttr( key ) + '"' + ( order.status === key ? ' selected' : '' ) + '>' + YP.escapeHtml( order.statuses[ key ] ) + '</option>';
							} ).join( '' ) +
						'</select></div><div><button type="button" class="wp-block-button__link is-style-accent" data-yp-wc-save-status>Save Status</button></div></div>' +
						'<div data-yp-wc-status-error></div>' +
					'</div>' +
				'</div>' +

				'<div>' +
					wcOrderShippingLabelHtml( order ) +
					shippoPanelHtml( order ) +
				'</div>' +
			'</div>' +

			'<p class="yp-field__hint"><a href="' + YP.escapeAttr( order.edit_url ) + '" target="_blank" rel="noopener noreferrer">Open in WooCommerce &rarr;</a></p>';

		bodyEl.querySelector( '[data-yp-wc-save-status]' ).addEventListener( 'click', function () { saveWcOrderStatus( order, drawer, bodyEl ); } );

		var printButton = bodyEl.querySelector( '[data-yp-print-label]' );
		if ( printButton ) {
			printButton.addEventListener( 'click', function () { embedShippingLabel( order, bodyEl ); } );
			if ( autoPrintLabel ) {
				embedShippingLabel( order, bodyEl );
			}
		}

		bindShippoPanel( order, bodyEl );
	}

	/**
	 * Direct request: print a real shipping label from this drawer
	 * "without having to go to WooCommerce." WooCommerce Shipping (the
	 * plugin already active on this store) only ever renders its
	 * rate-shopping/label-purchase UI — a large proprietary React app,
	 * no public REST API of its own to drive from outside it — as a meta
	 * box (`#woocommerce-order-label`) on the classic order edit screen.
	 * Rather than reimplement that, this embeds the exact same meta box
	 * via a same-origin iframe onto `order.edit_url` and hides the
	 * surrounding wp-admin chrome with injected CSS, so what renders is
	 * that plugin's own real, fully-functional label form.
	 */
	function wcOrderShippingLabelHtml( order ) {
		if ( ! order.shipping_label_available ) {
			return '';
		}
		return (
			'<div class="yp-panel" data-yp-shipping-label-panel>' +
				'<div class="yp-panel__head"><h2>Shipping Label</h2></div>' +
				'<p class="yp-panel__hint">Powered by the WooCommerce Shipping plugin already installed on this store.</p>' +
				// Direct request: default to what the customer picked at checkout. WooCommerce
				// Shipping has no way to auto-select a matching carrier service for a plain
				// (non-live-rate) shipping method like this store's, so this surfaces the choice
				// right above the form instead — a one-glance match, not automation that could
				// silently select (and pay for) the wrong service if it ever guessed wrong.
				'<p style="font-size:0.9rem;margin:0 0 0.75rem;">Customer selected: <strong>' + ( order.shipping_method ? YP.escapeHtml( order.shipping_method ) : 'No shipping method recorded' ) + '</strong></p>' +
				'<button type="button" class="wp-block-button__link is-style-outline" data-yp-print-label>Print Shipping Label</button>' +
				'<div data-yp-shipping-label-frame></div>' +
			'</div>'
		);
	}

	/** The CSS injected into the embedded iframe (see wcOrderShippingLabelHtml() above) — hides every core wp-admin chrome element and every other meta box on the classic order edit screen, leaving only #woocommerce-order-label (WooCommerce Shipping's own meta box id) visible. Every selector here is either a stable WordPress core admin id/class (#wpadminbar, #adminmenumain, .postbox, #postbox-container-1/2) or WooCommerce core's own order-screen meta box id (#woocommerce-order-data) — nothing specific to WooCommerce Shipping's own internal markup, which this never touches. */
	var SHIPPING_LABEL_IFRAME_CSS =
		'#wpadminbar, #adminmenumain, #adminmenuback, #adminmenuwrap, #wpfooter, ' +
		'#screen-meta-links, #screen-meta, .wrap > h1.wp-heading-inline, .wrap > a.page-title-action, ' +
		'.wrap > hr.wp-header-end, #woocommerce-order-data, .notice, #postbox-container-1 ' +
		'{ display: none !important; }' +
		'#wpcontent, #wpbody, #wpbody-content, #wpbody-content .wrap { margin: 0 !important; padding: 0 !important; }' +
		'#poststuff { padding-top: 0 !important; }' +
		'#poststuff .postbox:not(#woocommerce-order-label) { display: none !important; }' +
		'#postbox-container-2 { width: 100% !important; float: none !important; margin: 0 !important; }';

	function embedShippingLabel( order, bodyEl ) {
		var button = bodyEl.querySelector( '[data-yp-print-label]' );
		var frameHost = bodyEl.querySelector( '[data-yp-shipping-label-frame]' );
		if ( ! frameHost || frameHost.querySelector( 'iframe' ) ) {
			return; // Already embedded.
		}

		button.disabled = true;
		button.textContent = 'Loading&hellip;';

		var iframe = document.createElement( 'iframe' );
		iframe.className = 'yp-shipping-label-frame';
		iframe.setAttribute( 'title', 'Shipping Label' );
		iframe.src = order.edit_url + '#woocommerce-order-label';

		iframe.addEventListener( 'load', function () {
			button.style.display = 'none';
			try {
				var doc = iframe.contentDocument;
				var style = doc.createElement( 'style' );
				style.textContent = SHIPPING_LABEL_IFRAME_CSS;
				doc.head.appendChild( style );
				var box = doc.getElementById( 'woocommerce-order-label' );
				if ( box ) {
					box.scrollIntoView();
				}
			} catch ( error ) {
				// Cross-origin or otherwise inaccessible — leave the iframe showing the full
				// classic screen; the "Open in WooCommerce" link elsewhere in this drawer still works.
			}
		} );

		frameHost.appendChild( iframe );
	}

	/**
	 * A second, independent rate-shop/label-purchase panel next to the
	 * WooCommerce Shipping one above — direct request: "can we build
	 * something with the shippo API to replace it? ... I'd like to run
	 * alongside it a bit." Comparing rates never charges anything on the
	 * Shippo account; only clicking "Purchase" does, which the warning
	 * text and the confirm() in bindShippoPanel() below both make explicit
	 * before it fires.
	 */
	function shippoPanelHtml( order ) {
		if ( ! order.shippo_configured ) {
			return (
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Shippo <span style="font-weight:400;color:var(--yp-muted,#767676);">(Beta)</span></h2></div>' +
					'<p class="yp-panel__hint">An independent shipping-label option — add an API token under Settings &rarr; Shipping to turn this on for every order.</p>' +
				'</div>'
			);
		}

		var pkg = order.shippo_default_package;

		return (
			'<div class="yp-panel" data-yp-shippo-panel>' +
				'<div class="yp-panel__head"><h2>Shippo <span style="font-weight:400;color:var(--yp-muted,#767676);">(Beta)</span></h2></div>' +
				'<p class="yp-panel__hint">Comparing rates below is free. Purchasing a label is a real charge against your Shippo balance/carrier accounts.</p>' +
				'<div class="yp-shippo-dims">' +
					'<div class="yp-field"><label for="yp-shippo-weight">Weight (oz)</label><input type="number" min="0.1" step="0.1" id="yp-shippo-weight" value="' + YP.escapeAttr( pkg.weight_oz ) + '" /></div>' +
					'<div class="yp-field"><label for="yp-shippo-length">Length (in)</label><input type="number" min="0.1" step="0.1" id="yp-shippo-length" value="' + YP.escapeAttr( pkg.length_in ) + '" /></div>' +
					'<div class="yp-field"><label for="yp-shippo-width">Width (in)</label><input type="number" min="0.1" step="0.1" id="yp-shippo-width" value="' + YP.escapeAttr( pkg.width_in ) + '" /></div>' +
					'<div class="yp-field"><label for="yp-shippo-height">Height (in)</label><input type="number" min="0.1" step="0.1" id="yp-shippo-height" value="' + YP.escapeAttr( pkg.height_in ) + '" /></div>' +
				'</div>' +
				'<button type="button" class="wp-block-button__link is-style-outline yp-shippo-get-rates" data-yp-shippo-get-rates>Get Rates</button>' +
				'<div data-yp-shippo-rates></div>' +
				'<div data-yp-shippo-error></div>' +
				'<div data-yp-shippo-result></div>' +
			'</div>'
		);
	}

	function bindShippoPanel( order, bodyEl ) {
		var panel = bodyEl.querySelector( '[data-yp-shippo-panel]' );
		if ( ! panel ) {
			return;
		}

		panel.querySelector( '[data-yp-shippo-get-rates]' ).addEventListener( 'click', function () {
			fetchShippoRates( order, panel );
		} );
	}

	function fetchShippoRates( order, panel ) {
		var button = panel.querySelector( '[data-yp-shippo-get-rates]' );
		var ratesEl = panel.querySelector( '[data-yp-shippo-rates]' );
		var errorEl = panel.querySelector( '[data-yp-shippo-error]' );

		// Falls back to the order's own default package (already the value
		// every field starts pre-filled with) rather than 0 if a field gets
		// cleared — a 0-weight/0-dimension parcel is malformed enough that
		// UPS can quietly drop premium service levels for it entirely
		// instead of erroring, so this is worth guarding even though the
		// fields are never blank on a fresh panel.
		var defaults = order.shippo_default_package;
		var parcel = {
			weight_oz: parseFloat( panel.querySelector( '#yp-shippo-weight' ).value ) || defaults.weight_oz,
			length_in: parseFloat( panel.querySelector( '#yp-shippo-length' ).value ) || defaults.length_in,
			width_in: parseFloat( panel.querySelector( '#yp-shippo-width' ).value ) || defaults.width_in,
			height_in: parseFloat( panel.querySelector( '#yp-shippo-height' ).value ) || defaults.height_in
		};

		button.disabled = true;
		button.textContent = 'Getting rates…';
		errorEl.innerHTML = '';
		ratesEl.innerHTML = '';

		YP.request( yeffoprintAdminApp.restUrl + 'admin/order/' + order.id + '/shippo/rates', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( parcel )
		} )
			.then( function ( response ) {
				button.disabled = false;
				button.textContent = 'Get Rates';
				renderShippoRates( order, panel, response.rates || [] );
			} )
			.catch( function ( error ) {
				button.disabled = false;
				button.textContent = 'Get Rates';
				errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
			} );
	}

	/**
	 * Best-guess match between the rates Shippo returned and whatever the
	 * customer actually picked at checkout (order.shipping_method — the
	 * same string already shown on the WooCommerce Shipping panel's own
	 * "Customer selected" line) — direct request: "make the default
	 * shipping selection whatever the customer selected... the default
	 * should match what they picked." A carrier-name match alone counts
	 * for less than a service-name match (a carrier match is common and
	 * weak on its own — e.g. "USPS" appears in most USPS service names —
	 * while a service-name match like "Priority Mail" is far more
	 * specific), so the two are weighted differently rather than treated
	 * as equally good signals. Returns null (falls back to cheapest, the
	 * existing default) when nothing matches at all — e.g. a generic
	 * WooCommerce method like "Flat rate" or "Local pickup" was chosen,
	 * which no Shippo rate could ever legitimately match.
	 */
	function findBestMatchingRateId( rates, shippingMethod ) {
		if ( ! shippingMethod ) {
			return null;
		}
		var haystack = shippingMethod.toLowerCase();
		var bestId = null;
		var bestScore = 0;

		rates.forEach( function ( rate ) {
			var carrier = ( rate.carrier_label || '' ).toLowerCase();
			var service = ( rate.service || '' ).toLowerCase();
			var score = 0;
			if ( carrier && haystack.indexOf( carrier ) !== -1 ) {
				score += 1;
			}
			if ( service && haystack.indexOf( service ) !== -1 ) {
				score += 2;
			}
			if ( score > bestScore ) {
				bestScore = score;
				bestId = rate.id;
			}
		} );

		return bestScore > 0 ? bestId : null;
	}

	function renderShippoRates( order, panel, rates ) {
		var ratesEl = panel.querySelector( '[data-yp-shippo-rates]' );

		if ( ! rates.length ) {
			ratesEl.innerHTML = '<p class="yp-panel__hint">No rates came back for this address/package.</p>';
			return;
		}

		var bestMatchId = findBestMatchingRateId( rates, order.shipping_method );

		// One pill per carrier actually present in this response — direct
		// request: "I'd like to be able to filter carriers from shippo."
		// Built from the rates themselves rather than a fixed USPS/UPS/
		// FedEx/DHL list, so a carrier this store hasn't connected yet
		// never shows an empty, useless filter option.
		var carriers = [];
		rates.forEach( function ( rate ) {
			if ( carriers.indexOf( rate.carrier_label ) === -1 ) {
				carriers.push( rate.carrier_label );
			}
		} );

		var activeCarrier = null; // null = All.

		ratesEl.innerHTML =
			( carriers.length > 1 ?
				'<div class="yp-carrier-filter">' +
					'<button type="button" class="yp-carrier-filter__pill is-active" data-yp-carrier-pill="">All</button>' +
					carriers.map( function ( carrier ) {
						return '<button type="button" class="yp-carrier-filter__pill" data-yp-carrier-pill="' + YP.escapeAttr( carrier ) + '">' + YP.escapeHtml( carrier ) + '</button>';
					} ).join( '' ) +
				'</div>'
				: '' ) +
			'<div data-yp-rate-list></div>' +
			'<button type="button" class="wp-block-button__link is-style-accent" data-yp-shippo-purchase>Purchase Selected Label</button>';

		function renderRateList() {
			var listEl = ratesEl.querySelector( '[data-yp-rate-list]' );
			var visible = activeCarrier ? rates.filter( function ( r ) { return r.carrier_label === activeCarrier; } ) : rates;
			var previouslySelected = listEl.querySelector( 'input:checked' );
			var previousId = previouslySelected ? previouslySelected.value : null;

			// Preference order for which rate starts checked: whatever was
			// already selected before this render (e.g. switching carrier
			// filter pills, so a manual choice survives that) → the rate
			// that best matches what the customer picked at checkout → the
			// cheapest (rates arrive pre-sorted ascending, so that's index 0).
			// Falls through to the next preference whenever the preferred
			// choice isn't even in the currently filtered/visible list.
			var isVisible = function ( id ) { return visible.some( function ( r ) { return r.id === id; } ); };
			var defaultId = previousId && isVisible( previousId )
				? previousId
				: ( bestMatchId && isVisible( bestMatchId ) ? bestMatchId : null );

			listEl.innerHTML = '<div class="yp-rate-list">' +
				visible.map( function ( rate, index ) {
					var checked = defaultId ? rate.id === defaultId : 0 === index;
					return (
						'<label class="yp-rate-card' + ( checked ? ' is-selected' : '' ) + '">' +
							'<input type="radio" name="yp-shippo-rate" value="' + YP.escapeAttr( rate.id ) + '"' + ( checked ? ' checked' : '' ) + ' />' +
							'<span class="yp-rate-card__body">' +
								'<span class="yp-rate-card__carrier">' + YP.escapeHtml( rate.carrier_label ) + '</span> ' +
								'<span class="yp-rate-card__service">' + YP.escapeHtml( rate.service ) + '</span>' +
								( rate.id === bestMatchId ? '<span class="yp-rate-card__match">Matches customer’s choice</span>' : '' ) +
							'</span>' +
							'<span class="yp-rate-card__days">' + ( rate.days ? rate.days + ( 1 === rate.days ? ' day' : ' days' ) : '—' ) + '</span>' +
							'<span class="yp-rate-card__price">$' + rate.amount.toFixed( 2 ) + '</span>' +
						'</label>'
					);
				} ).join( '' ) +
			'</div>';

			listEl.querySelectorAll( '.yp-rate-card' ).forEach( function ( card ) {
				card.addEventListener( 'click', function () {
					listEl.querySelectorAll( '.yp-rate-card' ).forEach( function ( c ) { c.classList.remove( 'is-selected' ); } );
					card.classList.add( 'is-selected' );
				} );
			} );
		}

		renderRateList();

		ratesEl.querySelectorAll( '[data-yp-carrier-pill]' ).forEach( function ( pill ) {
			pill.addEventListener( 'click', function () {
				activeCarrier = pill.getAttribute( 'data-yp-carrier-pill' ) || null;
				ratesEl.querySelectorAll( '[data-yp-carrier-pill]' ).forEach( function ( p ) { p.classList.toggle( 'is-active', p === pill ); } );
				renderRateList();
			} );
		} );

		ratesEl.querySelector( '[data-yp-shippo-purchase]' ).addEventListener( 'click', function () {
			var selected = ratesEl.querySelector( 'input[name="yp-shippo-rate"]:checked' );
			if ( ! selected ) {
				return;
			}
			var rate = rates.filter( function ( r ) { return r.id === selected.value; } )[ 0 ];
			if ( rate && window.confirm( 'Purchase this ' + rate.carrier_label + ' ' + rate.service + ' label for $' + rate.amount.toFixed( 2 ) + '? This charges your Shippo balance/carrier account immediately.' ) ) {
				purchaseShippoLabel( order, panel, selected.value );
			}
		} );
	}

	function purchaseShippoLabel( order, panel, rateId ) {
		var purchaseButton = panel.querySelector( '[data-yp-shippo-purchase]' );
		var errorEl = panel.querySelector( '[data-yp-shippo-error]' );
		var resultEl = panel.querySelector( '[data-yp-shippo-result]' );

		purchaseButton.disabled = true;
		purchaseButton.textContent = 'Purchasing…';
		errorEl.innerHTML = '';

		YP.request( yeffoprintAdminApp.restUrl + 'admin/order/' + order.id + '/shippo/purchase', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { rate_id: rateId } )
		} )
			.then( function ( response ) {
				resultEl.innerHTML =
					'<p class="yp-panel__hint"><strong>Label purchased.</strong> Tracking: ' + YP.escapeHtml( response.label.tracking_number ) + ' (' + YP.escapeHtml( response.label.carrier_label ) + ')' +
					( response.label.label_url ? ' — <a href="' + YP.escapeAttr( response.label.label_url ) + '" target="_blank" rel="noopener">Print label</a>' : '' ) +
					'</p>';
				panel.querySelector( '[data-yp-shippo-rates]' ).innerHTML = '';
				order.status = response.status;
				// Status now lives in the grid's other column (see
				// renderWcOrderDetail()'s two-column layout) — walking up
				// to the shared drawer body ([data-yp-body]) rather than
				// panel.parentNode finds it regardless of which column
				// either element is in.
				var body = panel.closest( '[data-yp-body]' );
				var statusSelect = body ? body.querySelector( '[data-yp-wc-status]' ) : null;
				if ( statusSelect ) {
					statusSelect.value = response.status;
				}
			} )
			.catch( function ( error ) {
				purchaseButton.disabled = false;
				purchaseButton.textContent = 'Purchase Selected Label';
				errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
			} );
	}

	function saveWcOrderStatus( order, drawer, bodyEl ) {
		var select = bodyEl.querySelector( '[data-yp-wc-status]' );
		var button = bodyEl.querySelector( '[data-yp-wc-save-status]' );
		var errorEl = bodyEl.querySelector( '[data-yp-wc-status-error]' );

		button.disabled = true;
		button.textContent = 'Saving…';
		errorEl.innerHTML = '';

		YP.request( yeffoprintAdminApp.restUrl + 'admin/order/' + order.id, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { status: select.value } ) } )
			.then( function ( updated ) {
				renderWcOrderDetail( updated, drawer, bodyEl );
				loadDashboard();
			} )
			.catch( function ( error ) {
				button.disabled = false;
				button.textContent = 'Save Status';
				errorEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
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
