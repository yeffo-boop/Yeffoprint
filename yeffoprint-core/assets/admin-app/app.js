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

	function dashboardSectionHtml( title, description, viewAllHref, rows, dueDateDays, onOrderClick, rowAction, clickAttr ) {
		var body;
		if ( ! rows.length ) {
			body = '<p class="yp-field__hint">Nothing here right now.</p>';
		} else {
			body =
				'<table class="yp-record-table"><thead><tr><th>Order</th><th>Customer</th><th>Date</th>' + ( rowAction ? '<th></th>' : '' ) + '</tr></thead><tbody>' +
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

	function renderDashboardSummary( summary, el ) {
		var dueDateDays = summary.due_date_days;

		var shippedBody = summary.shipped_packages.length
			? '<table class="yp-record-table"><thead><tr><th>Order</th><th>Customer</th><th>Carrier</th><th>Tracking #</th></tr></thead><tbody>' +
				summary.shipped_packages.map( function ( pkg ) {
					var tracking = pkg.tracking_url
						? '<a href="' + YP.escapeAttr( pkg.tracking_url ) + '" target="_blank" rel="noopener noreferrer">' + YP.escapeHtml( pkg.tracking_number ) + '</a>'
						: YP.escapeHtml( pkg.tracking_number );
					return (
						'<tr>' +
							'<td><a href="' + YP.escapeAttr( pkg.edit_url ) + '">' + YP.escapeHtml( pkg.order_label ) + '</a></td>' +
							'<td>' + YP.escapeHtml( pkg.customer || '—' ) + '</td>' +
							'<td><span class="yp-chip">' + YP.escapeHtml( pkg.carrier_label || '—' ) + '</span></td>' +
							'<td>' + tracking + '</td>' +
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

		function sendToPrinterButtonHtml( row ) {
			return '<button type="button" class="wp-block-button__link is-style-outline yp-row-action" style="padding:4px 10px;font-size:12px;" data-yp-send-to-printer="' + row.id + '">Send to Printer</button>';
		}

		el.innerHTML =
			dashboardSectionHtml( 'Pending Orders', 'Paid, not yet shipped.', summary.pending_orders_url, summary.pending_orders, dueDateDays, true, sendToPrinterButtonHtml, 'data-yp-wc-order' ) +
			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>Shipped Packages</h2></div>' +
				'<p class="yp-panel__hint">Shipped, not yet delivered — every label with a tracking number currently in transit.</p>' +
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

	function openWcOrderDrawer( id ) {
		var drawer = document.createElement( 'div' );
		drawer.className = 'yp-drawer yp-drawer--wide';
		drawer.setAttribute( 'aria-hidden', 'true' );
		drawer.innerHTML =
			'<div class="yp-drawer__backdrop"></div>' +
			'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="Order detail">' +
				'<div class="yp-drawer__header"><span>Order detail</span>' +
					'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
				'</div>' +
				'<div class="yp-drawer__body" data-yp-body><p class="yp-field__hint">Loading&hellip;</p></div>' +
			'</div>';

		document.body.appendChild( drawer );
		YP.initDrawer( drawer );
		YP.openDrawer( drawer );

		loadWcOrderDetail( id, drawer );
	}

	function loadWcOrderDetail( id, drawer ) {
		var bodyEl = drawer.querySelector( '[data-yp-body]' );
		YP.request( yeffoprintAdminApp.restUrl + 'admin/order/' + id )
			.then( function ( order ) { renderWcOrderDetail( order, drawer, bodyEl ); } )
			.catch( function ( error ) {
				bodyEl.innerHTML = '<p class="yp-form__error">Couldn’t load this order: ' + YP.escapeHtml( error.message ) + '</p>';
			} );
	}

	function wcOrderItemsHtml( items ) {
		if ( ! items.length ) {
			return '<p class="yp-field__hint">No line items.</p>';
		}
		return '<table class="yp-record-table"><thead><tr><th>Item</th><th>Qty</th><th>Total</th></tr></thead><tbody>' +
			items.map( function ( item ) {
				var metaHtml = item.meta.length
					? '<dl class="yp-field__hint" style="margin:0.35rem 0 0;">' +
						item.meta.map( function ( m ) {
							return '<dt style="font-weight:600;display:inline;">' + YP.escapeHtml( m.label ) + ':</dt> <dd style="display:inline;margin:0 0 0.35rem;">' + m.value + '</dd><br>';
						} ).join( '' ) +
					'</dl>'
					: '';
				return (
					'<tr>' +
						'<td>' + YP.escapeHtml( item.name ) + metaHtml + '</td>' +
						'<td>' + item.quantity + '</td>' +
						'<td>$' + item.total.toFixed( 2 ) + '</td>' +
					'</tr>'
				);
			} ).join( '' ) +
		'</tbody></table>';
	}

	function renderWcOrderDetail( order, drawer, bodyEl ) {
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

		bodyEl.innerHTML =
			'<div class="yp-record-card"><table class="yp-record-table"><tbody>' + rowsHtml + '</tbody></table></div>' +

			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>Items</h2></div>' +
				wcOrderItemsHtml( order.items ) +
				'<p class="yp-panel__hint" style="margin-top:0.75rem;">Subtotal: $' + order.subtotal.toFixed( 2 ) + ' &nbsp;·&nbsp; Shipping: $' + order.shipping_total.toFixed( 2 ) + ' &nbsp;·&nbsp; <strong>Total: $' + order.total.toFixed( 2 ) + '</strong></p>' +
			'</div>' +

			wcOrderShippingLabelHtml( order ) +

			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>Status</h2></div>' +
				'<div class="yp-form__row"><div class="yp-field"><select data-yp-wc-status>' +
					Object.keys( order.statuses ).map( function ( key ) {
						return '<option value="' + YP.escapeAttr( key ) + '"' + ( order.status === key ? ' selected' : '' ) + '>' + YP.escapeHtml( order.statuses[ key ] ) + '</option>';
					} ).join( '' ) +
				'</select></div><div><button type="button" class="wp-block-button__link is-style-accent" data-yp-wc-save-status>Save Status</button></div></div>' +
				'<div data-yp-wc-status-error></div>' +
			'</div>' +

			'<p class="yp-field__hint"><a href="' + YP.escapeAttr( order.edit_url ) + '" target="_blank" rel="noopener noreferrer">Open in WooCommerce &rarr;</a></p>';

		bodyEl.querySelector( '[data-yp-wc-save-status]' ).addEventListener( 'click', function () { saveWcOrderStatus( order, drawer, bodyEl ); } );

		var printButton = bodyEl.querySelector( '[data-yp-print-label]' );
		if ( printButton ) {
			printButton.addEventListener( 'click', function () { embedShippingLabel( order, bodyEl ); } );
		}
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
