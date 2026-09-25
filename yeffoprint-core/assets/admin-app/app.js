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
	 * A small styled confirm dialog, same `.yp-drawer` visual language as
	 * every other modal in this app — direct request: "the pop up window
	 * using the browser method is ugly. Can we use modal windows that
	 * match the sites style when confirming." Replaces window.confirm()
	 * wherever this app asks for a yes/no before a real, hard-to-undo
	 * action (first use: the Shippo purchase/void confirmations).
	 *
	 * @param {Object} options {title, message, confirmLabel, danger, onConfirm}
	 *   `danger` styles the confirm button as a destructive action
	 *   (Void a label) rather than a routine one (Purchase a label).
	 *   `onConfirm` only ever fires from the confirm button — Cancel,
	 *   the backdrop, and Escape all just close the dialog, same as
	 *   every other drawer.
	 */
	YP.confirmModal = function ( options ) {
		var drawer = document.createElement( 'div' );
		drawer.className = 'yp-drawer yp-drawer--center yp-drawer--confirm';
		drawer.setAttribute( 'aria-hidden', 'true' );
		drawer.innerHTML =
			'<div class="yp-drawer__backdrop"></div>' +
			'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="' + YP.escapeAttr( options.title || 'Confirm' ) + '">' +
				'<div class="yp-drawer__header"><span class="yp-drawer__title-group">' + YP.escapeHtml( options.title || 'Confirm' ) + '</span>' +
					'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
				'</div>' +
				'<div class="yp-drawer__body">' +
					'<p class="yp-panel__hint">' + YP.escapeHtml( options.message || '' ) + '</p>' +
					'<div class="yp-confirm-modal__actions">' +
						'<button type="button" class="wp-block-button__link is-style-outline" data-yp-confirm-cancel>Cancel</button>' +
						'<button type="button" class="wp-block-button__link ' + ( options.danger ? 'yp-button--danger' : 'is-style-accent' ) + '" data-yp-confirm-ok>' + YP.escapeHtml( options.confirmLabel || 'Confirm' ) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>';

		document.body.appendChild( drawer );
		YP.initDrawer( drawer );
		YP.openDrawer( drawer );

		drawer.querySelector( '[data-yp-confirm-cancel]' ).addEventListener( 'click', function () { YP.closeDrawer( drawer ); } );
		drawer.querySelector( '[data-yp-confirm-ok]' ).addEventListener( 'click', function () {
			YP.closeDrawer( drawer );
			if ( options.onConfirm ) {
				options.onConfirm();
			}
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
			{ id: 'label-fields', label: 'Label Fields' },
			{ id: 'label-colors', label: 'Label Colors' },
			{ id: 'prints', label: '3D Prints' },
			{ id: 'filament-colors', label: 'Filament Colors' }
		] },
		{ group: 'Sales', items: [
			{ id: 'manual-order', label: 'Create Order' },
			{ id: 'order-history', label: 'Order History' },
			{ id: 'abandoned-carts', label: 'Abandoned Carts' },
			{ id: 'web-design-orders', label: 'Web Design Orders' },
			{ id: 'customers', label: 'Customers' },
			{ id: 'pricing', label: 'Pricing Rules' },
			{ id: 'orders', label: 'Custom Orders' },
			{ id: 'proofs', label: 'Proofs' },
			{ id: 'web-design-packages', label: 'Web Design Packages' },
			{ id: 'web-design-addons', label: 'Web Design Add-ons' },
			{ id: 'maintenance', label: 'Maintenance Subscribers' }
		] },
		{ group: 'Store', items: [
			{ id: 'coupons', label: 'Coupons' },
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
			return { text: '—', overdue: false, overdueBy: 0 };
		}
		var daysOpen = Math.floor( ( Date.now() - new Date( isoDate ).getTime() ) / 86400000 );
		if ( daysOpen > dueDateDays ) {
			var overdueBy = daysOpen - dueDateDays;
			return { text: overdueBy + ( 1 === overdueBy ? ' day overdue' : ' days overdue' ), overdue: true, overdueBy: overdueBy };
		}
		return { text: daysOpen + ( 1 === daysOpen ? ' day ago' : ' days ago' ), overdue: false, overdueBy: 0 };
	}

	function rowHtml( row, dueDateDays, onOrderClick, rowAction, clickAttr ) {
		var age = daysAgoLabel( row.date, dueDateDays );
		var label = onOrderClick
			? '<button type="button" class="yp-row-action" style="padding:0;font-weight:700;" ' + ( clickAttr || 'data-yp-dashboard-order' ) + '="' + row.id + '">' + YP.escapeHtml( row.label ) + '</button>'
			: '<a href="' + YP.escapeAttr( row.edit_url ) + '">' + YP.escapeHtml( row.label ) + '</a>';
		return (
			'<div class="yp-list-row">' +
				'<div class="yp-list-row__text">' +
					'<span class="t">' + label + ( row.express ? ' <span class="yp-pill yp-pill--crit">Express</span>' : '' ) + '</span>' +
					'<span class="s">' + YP.escapeHtml( row.customer || '—' ) + '</span>' +
				'</div>' +
				'<div class="yp-list-row__meta">' + ( age.overdue ? '<span class="yp-pill yp-pill--crit">' + age.text + '</span>' : '<span class="yp-list-row__age">' + age.text + '</span>' ) + '</div>' +
				( rowAction ? '<div class="yp-list-row__action">' + rowAction( row ) + '</div>' : '' ) +
			'</div>'
		);
	}

	/**
	 * "Ship it together" (direct request): Pending Orders rows that share
	 * a ship_group_key (class-admin-dashboard-controller.php's
	 * pending_wc_orders() — only ever set within this one fetched page,
	 * see that method's own docblock) get bracketed into one visual
	 * group so staff notice the pairing before either order ships
	 * separately. No other panel's rows ever carry ship_group_key, so
	 * this degrades to plain rowHtml() there.
	 */
	function renderRowsWithShipGroups( rows, dueDateDays, onOrderClick, rowAction, clickAttr ) {
		var rendered = {};
		return rows.map( function ( row ) {
			if ( rendered[ row.id ] ) {
				return '';
			}

			if ( ! row.ship_group_key ) {
				return rowHtml( row, dueDateDays, onOrderClick, rowAction, clickAttr );
			}

			var group = rows.filter( function ( candidate ) {
				return candidate.ship_group_key === row.ship_group_key;
			} );
			group.forEach( function ( member ) { rendered[ member.id ] = true; } );

			var labels = group.map( function ( member ) { return member.label; } ).join( ' + ' );

			return (
				'<div class="yp-ship-group">' +
					'<div class="yp-ship-group__label">&#128279; Ship together — ' + YP.escapeHtml( labels ) + '</div>' +
					group.map( function ( member ) { return rowHtml( member, dueDateDays, onOrderClick, rowAction, clickAttr ); } ).join( '' ) +
				'</div>'
			);
		} ).join( '' );
	}

	/**
	 * Direct request: "a functional dashboard that gives me what I need
	 * at a glance, looks polished, and just works." Rows used to render
	 * as a plain 4-column table — every panel a different table, none of
	 * them visually related to the "Needs attention" queue above them or
	 * the split-view list rows Custom Orders now uses. `.yp-list-row`
	 * (records.css) is that same compact row shape reused here: a bold
	 * clickable title, a muted customer sub-line, the age/overdue state
	 * and any per-row action lined up on the right — one visual language
	 * across the whole dashboard instead of a table per panel.
	 */
	function dashboardSectionHtml( title, description, viewAllHref, rows, dueDateDays, onOrderClick, rowAction, clickAttr ) {
		var body = rows.length
			? renderRowsWithShipGroups( rows, dueDateDays, onOrderClick, rowAction, clickAttr )
			: '<p class="yp-field__hint">Nothing here right now.</p>';

		return (
			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>' + YP.escapeHtml( title ) + '</h2>' + ( viewAllHref ? '<a href="' + YP.escapeAttr( viewAllHref ) + '">View all &rarr;</a>' : '' ) + '</div>' +
				'<p class="yp-panel__hint">' + YP.escapeHtml( description ) + '</p>' +
				'<div class="yp-list-rows">' + body + '</div>' +
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

	/**
	 * "Needs attention" — direct request: "the dashboard is five separate
	 * flat panels with nothing ranking them... an order stuck for 4 days
	 * looks exactly as urgent as one placed an hour ago." Pulls the
	 * genuinely urgent rows out of the panels below (already-fetched data,
	 * no new endpoint) into one ranked list at the top: a tracking failure
	 * first (there's no "days" to compare it against, and a lost/failed
	 * package is as urgent as this dashboard gets), then every overdue
	 * pending order/proof/approval, most-overdue first. The panels below
	 * are unchanged and still show everything, overdue or not — this is a
	 * "look here first," not a replacement for browsing the full lists.
	 */
	function needsAttentionItems( summary, dueDateDays, sendToPrinterButtonHtml ) {
		var items = [];

		summary.shipped_packages.forEach( function ( pkg ) {
			if ( 'FAILURE' !== pkg.tracking_status && 'RETURNED' !== pkg.tracking_status ) {
				return;
			}
			items.push( {
				sortKey: Infinity,
				title: pkg.order_label + ' — ' + ( 'FAILURE' === pkg.tracking_status ? 'delivery failed' : 'returned to sender' ),
				meta: ( pkg.customer || '—' ) + ' · ' + ( pkg.carrier_label || 'Carrier' ) + ' ' + pkg.tracking_number,
				actionHtml: '<button type="button" class="wp-block-button__link is-style-outline yp-row-action" style="padding:4px 10px;font-size:12px;" data-yp-wc-order="' + pkg.id + '">View order</button>'
			} );
		} );

		function pushOverdue( rows, metaSuffix, actionBuilder ) {
			rows.forEach( function ( row ) {
				var age = daysAgoLabel( row.date, dueDateDays );
				if ( ! age.overdue ) {
					return;
				}
				items.push( {
					sortKey: age.overdueBy,
					title: row.label,
					meta: ( row.customer || '—' ) + ' · ' + age.text + metaSuffix,
					actionHtml: actionBuilder( row )
				} );
			} );
		}

		pushOverdue( summary.pending_orders, '', sendToPrinterButtonHtml );
		pushOverdue( summary.pending_proofs, ' · needs a proof', function ( row ) {
			return '<button type="button" class="wp-block-button__link is-style-outline yp-row-action" style="padding:4px 10px;font-size:12px;" data-yp-dashboard-order="' + row.id + '">View</button>';
		} );
		pushOverdue( summary.awaiting_approval, ' · awaiting customer approval', function ( row ) {
			return '<button type="button" class="wp-block-button__link is-style-outline yp-row-action" style="padding:4px 10px;font-size:12px;" data-yp-dashboard-order="' + row.id + '">View</button>';
		} );

		items.sort( function ( a, b ) { return b.sortKey - a.sortKey; } );

		return items.slice( 0, 6 );
	}

	function needsAttentionHtml( summary, dueDateDays, sendToPrinterButtonHtml ) {
		var items = needsAttentionItems( summary, dueDateDays, sendToPrinterButtonHtml );
		if ( ! items.length ) {
			return '';
		}

		return (
			'<div class="yp-panel yp-attn">' +
				'<div class="yp-panel__head"><h2>Needs attention</h2><span class="yp-panel__hint" style="margin:0;">' + items.length + ( 1 === items.length ? ' item' : ' items' ) + '</span></div>' +
				items.map( function ( item ) {
					return (
						'<div class="yp-attn__row">' +
							'<div class="yp-attn__text">' +
								'<span class="t">' + YP.escapeHtml( item.title ) + '</span>' +
								'<span class="s">' + YP.escapeHtml( item.meta ) + '</span>' +
							'</div>' +
							'<div class="yp-attn__actions">' + item.actionHtml + '</div>' +
						'</div>'
					);
				} ).join( '' ) +
			'</div>'
		);
	}

	/**
	 * Four glanceable counts across the top of the dashboard — direct
	 * request: "a functional dashboard that gives me what I need at a
	 * glance." Same four sections the panels below already cover, just
	 * as a single number each, read in one pass before scrolling into
	 * any of the detail below.
	 */
	function statTilesHtml( summary ) {
		var tiles = [
			{ label: 'Pending Orders', count: summary.pending_orders.length },
			{ label: 'Shipped Packages', count: summary.shipped_packages.length },
			{ label: 'Awaiting Approval', count: summary.awaiting_approval.length },
			{ label: 'Maintenance Subscribers', count: summary.maintenance_subscribers.length }
		];

		return (
			'<div class="yp-stat-tiles">' +
				tiles.map( function ( tile ) {
					return (
						'<div class="yp-stat-tile">' +
							'<span class="yp-stat-tile__count">' + tile.count + '</span>' +
							'<span class="yp-stat-tile__label">' + YP.escapeHtml( tile.label ) + '</span>' +
						'</div>'
					);
				} ).join( '' ) +
			'</div>'
		);
	}

	function renderDashboardSummary( summary, el ) {
		var dueDateDays = summary.due_date_days;

		var shippedBody = summary.shipped_packages.length
			? '<div class="yp-list-rows">' + summary.shipped_packages.map( function ( pkg ) {
					var tracking = pkg.tracking_url
						? '<a href="' + YP.escapeAttr( pkg.tracking_url ) + '" target="_blank" rel="noopener noreferrer" class="mono">' + YP.escapeHtml( pkg.tracking_number ) + '</a>'
						: '<span class="mono">' + YP.escapeHtml( pkg.tracking_number ) + '</span>';
					var checkedAgo = timeAgoLabel( pkg.tracking_checked_at );
					return (
						'<div class="yp-list-row">' +
							'<div class="yp-list-row__text">' +
								// Direct report: clicking an order here used to link straight to the
								// classic WooCommerce edit screen instead of this dashboard's own
								// order-detail drawer (which is where the Shippo "reprint label" panel
								// lives) — a button wired to the same [data-yp-wc-order] handler the
								// Pending Orders panel's rows already use (bound below) instead of a
								// plain <a href="edit_url">.
								'<span class="t"><button type="button" class="yp-row-action" style="padding:0;font-weight:700;" data-yp-wc-order="' + pkg.id + '">' + YP.escapeHtml( pkg.order_label ) + '</button></span>' +
								'<span class="s">' + YP.escapeHtml( pkg.customer || '—' ) + ' · <span class="yp-chip">' + YP.escapeHtml( pkg.carrier_label || '—' ) + '</span> ' + tracking + '</span>' +
							'</div>' +
							'<div class="yp-list-row__meta">' +
								trackingStatusPillHtml( pkg.tracking_status ) +
								( checkedAgo ? '<span class="yp-list-row__age">' + YP.escapeHtml( checkedAgo ) + '</span>' : '' ) +
							'</div>' +
						'</div>'
					);
				} ).join( '' ) + '</div>'
			: '<p class="yp-field__hint">Nothing here right now.</p>';

		var maintenanceBody = summary.maintenance_subscribers.length
			? '<div class="yp-list-rows">' + summary.maintenance_subscribers.map( function ( sub ) {
					return (
						'<div class="yp-list-row">' +
							'<div class="yp-list-row__text">' +
								'<span class="t">' + YP.escapeHtml( sub.name ) + '</span>' +
								'<span class="s">' + YP.escapeHtml( sub.plan || '—' ) + '</span>' +
							'</div>' +
							'<div class="yp-list-row__meta"><span class="yp-list-row__age">' + ( sub.renews ? 'Renews ' + new Date( sub.renews * 1000 ).toLocaleDateString() : '—' ) + '</span></div>' +
						'</div>'
					);
				} ).join( '' ) + '</div>'
			: '<p class="yp-field__hint">Nothing here right now.</p>';

		/**
		 * Direct request: "add upcoming/overdue milestones onto my main
		 * dashboard so I can see those at a glance." One flat, soonest-
		 * first list across every in-flight Web Design project (the
		 * REST endpoint already did the filtering/sorting — see
		 * YeffoPrint_Web_Design_Project_Meta::get_milestone_alerts()),
		 * each row opening straight into the same order drawer via the
		 * generic [data-yp-wc-order] handler bound below.
		 */
		function milestoneDueLabel( alert ) {
			if ( alert.is_overdue ) {
				var daysLate = Math.max( 1, Math.round( ( Date.now() - new Date( alert.due_date + 'T00:00:00' ).getTime() ) / 86400000 ) );
				return { text: daysLate + ( 1 === daysLate ? ' day overdue' : ' days overdue' ), pill: 'crit' };
			}
			var today = new Date().toISOString().slice( 0, 10 );
			if ( alert.due_date === today ) {
				return { text: 'Due today', pill: 'warn' };
			}
			return { text: 'Due ' + new Date( alert.due_date + 'T00:00:00' ).toLocaleDateString( undefined, { month: 'short', day: 'numeric' } ), pill: 'warn' };
		}

		var milestonesBody = ( summary.web_design_milestones || [] ).length
			? '<div class="yp-list-rows">' + summary.web_design_milestones.map( function ( alert ) {
					var due = milestoneDueLabel( alert );
					return (
						'<div class="yp-list-row">' +
							'<div class="yp-list-row__text">' +
								'<span class="t"><button type="button" class="yp-row-action" style="padding:0;font-weight:700;" data-yp-wc-order="' + alert.order_id + '">' + YP.escapeHtml( alert.label ) + '</button></span>' +
								'<span class="s">Order #' + YP.escapeHtml( String( alert.order_number ) ) + ' &middot; ' + YP.escapeHtml( alert.package_name ) + ' &middot; ' + YP.escapeHtml( alert.customer_name || '—' ) + '</span>' +
							'</div>' +
							'<div class="yp-list-row__meta"><span class="yp-pill yp-pill--' + due.pill + '">' + YP.escapeHtml( due.text ) + '</span></div>' +
						'</div>'
					);
				} ).join( '' ) + '</div>'
			: '<p class="yp-field__hint">Nothing due in the next week.</p>';

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
			statTilesHtml( summary ) +
			needsAttentionHtml( summary, dueDateDays, sendToPrinterButtonHtml ) +
			dashboardSectionHtml( 'Pending Orders', 'Paid, not yet shipped — processing or in production.', summary.pending_orders_url, summary.pending_orders, dueDateDays, true, sendToPrinterButtonHtml, 'data-yp-wc-order' ) +
			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>Shipped Packages</h2><button type="button" class="yp-row-action" data-yp-refresh-tracking>Check tracking now</button></div>' +
				'<p class="yp-panel__hint">Shipped, not yet delivered — every label with a tracking number currently in transit. Delivered packages automatically move the order to Completed and drop off this list.</p>' +
				shippedBody +
			'</div>' +
			dashboardSectionHtml( 'Pending Proofs', 'Custom orders staff still owes a proof — brand new, or the customer just requested changes.', '#/orders', summary.pending_proofs, dueDateDays, true ) +
			dashboardSectionHtml( 'Awaiting Customer Approval', 'A proof has been sent — waiting on the customer to approve it or request changes.', '#/orders', summary.awaiting_approval, dueDateDays, true ) +
			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>Web Design Milestones</h2><a href="#/web-design-orders">View all &rarr;</a></div>' +
				'<p class="yp-panel__hint">Overdue, or due within the next 7 days, across every Web Design project not yet live.</p>' +
				milestonesBody +
			'</div>' +
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

	// Same field/value shape as the Custom Orders split-view detail pane's
	// own field() helper (views/orders.js) — reused directly rather than
	// duplicated, since .yp-split__field is a generic label/value pair, not
	// something scoped to that one screen. Direct report: this drawer's old
	// plain <table> "needs to be redesigned... it's clunky" — swapped for
	// the same field-grid look staff already see on the Custom Orders
	// screen, instead of two different visual languages for "an order's
	// details" a click apart from each other.
	function wcOrderField( label, valueHtml ) {
		return '<div class="yp-split__field"><span class="k">' + YP.escapeHtml( label ) + '</span><span class="v">' + valueHtml + '</span></div>';
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

	// Exposed on YP — direct request: the Custom Orders screen's own
	// "Order #123" link used to open the classic WooCommerce edit screen
	// in a new tab; it opens this same drawer in place instead, so staff
	// never have to leave the app's own order view.
	YP.openWcOrderDrawer = openWcOrderDrawer;

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
				'<span class="yp-pill yp-pill--' + ( WC_ORDER_STATUS_PILLS[ order.status ] || 'neutral' ) + '">' + YP.escapeHtml( order.status_label ) + '</span>' +
				( order.express ? '<span class="yp-pill yp-pill--crit">Express</span>' : '' );
		}

		var fieldsHtml = wcOrderField(
			'Customer',
			YP.escapeHtml( order.customer_name || '' ) + ( order.customer_email ? ' — <a href="mailto:' + YP.escapeAttr( order.customer_email ) + '">' + YP.escapeHtml( order.customer_email ) + '</a>' : '' ) +
				( order.customer_phone ? ' — ' + YP.escapeHtml( order.customer_phone ) : '' )
		);
		fieldsHtml += wcOrderField(
			'Shipping Address',
			order.needs_customer_address
				? '<span class="yp-pill yp-pill--warn">Awaiting customer</span> — they’ll be asked for it on the payment page.'
				: ( order.shipping_address ? order.shipping_address.replace( /\n/g, '<br>' ) : '—' )
		);
		fieldsHtml += wcOrderField( 'Payment Method', YP.escapeHtml( order.payment_method_title || '—' ) );
		fieldsHtml += wcOrderField( 'Date', order.date ? new Date( order.date ).toLocaleString() : '—' );
		if ( order.customer_note ) {
			fieldsHtml += wcOrderField( 'Customer Note', YP.escapeHtml( order.customer_note ).replace( /\n/g, '<br>' ) );
		}

		// Single column, direct request: "it should be a one column
		// design... order status change towards the top and shipping
		// options down towards the bottom" — Status right under the
		// order's own fields, ahead of Items, since changing it is the
		// thing staff most often open this drawer to do; both shipping
		// panels (Shipping Label, Shippo) last, since printing/buying a
		// label is the last step in working an order. The two-column
		// grid this replaced put Status behind a full Items table read
		// and split "what was ordered" from "get it out the door" onto
		// two panes that had to be read in parallel instead of in order.
		bodyEl.innerHTML =
			'<div class="yp-panel"><div class="yp-split__fields">' + fieldsHtml + '</div></div>' +

			'<div class="yp-panel yp-panel--compact">' +
				'<div class="yp-panel__head"><h2>Status</h2></div>' +
				'<div class="yp-form__row"><div class="yp-field"><select data-yp-wc-status>' +
					Object.keys( order.statuses ).map( function ( key ) {
						return '<option value="' + YP.escapeAttr( key ) + '"' + ( order.status === key ? ' selected' : '' ) + '>' + YP.escapeHtml( order.statuses[ key ] ) + '</option>';
					} ).join( '' ) +
				'</select></div><div><button type="button" class="wp-block-button__link is-style-accent" data-yp-wc-save-status>Save Status</button></div></div>' +
				'<div data-yp-wc-status-error></div>' +
			'</div>' +

			// Direct request: "add notes to customers so when I print
			// their future orders I can refer to them" — right under
			// Status, at the top of the drawer, since staff need to
			// see this before or while working the order, not after
			// scrolling past everything else.
			customerNotesPanelHtml( order ) +

			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>Items</h2></div>' +
				wcOrderItemsHtml( order.items ) +
				'<p class="yp-panel__hint" style="margin-top:0.75rem;">Subtotal: $' + order.subtotal.toFixed( 2 ) + ' &nbsp;·&nbsp; Shipping: $' + order.shipping_total.toFixed( 2 ) + ' &nbsp;·&nbsp; <strong>Total: $' + order.total.toFixed( 2 ) + '</strong></p>' +
				'<p class="yp-panel__hint">' + wcOrderRewardsLine( order.rewards ) + '</p>' +
			'</div>' +

			webDesignPanelHtml( order ) +

			refundPanelHtml( order ) +

			wcOrderShippingLabelHtml( order ) +
			shippoPanelHtml( order ) +

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
		bindCustomerNotesPanel( order, bodyEl );
		bindRefundPanel( order, bodyEl, drawer );
		loadWebDesignPanel( order, bodyEl );
	}

	/**
	 * Direct request: an Agreement/Staging Site/Go-Live workflow for Web
	 * Design Package orders, right in this same order drawer — staff
	 * build/send the agreement, fill in staged-site credentials, review
	 * the client's response, and collect go-live server access without
	 * ever leaving the order they're looking at.
	 *
	 * order.web_design (class-admin-order-controller.php's own
	 * detail_payload()) is only a `{package_id}` presence flag — the
	 * real agreement/staging/go-live data loads separately from
	 * `/admin/web-design/{id}` (loadWebDesignPanel(), called from
	 * renderWcOrderDetail() above) so a plain "view this order" request
	 * never has to assemble that heavier payload for the vast majority
	 * of orders that aren't Web Design purchases at all.
	 */
	function webDesignPanelHtml( order ) {
		if ( ! order.web_design ) {
			return '';
		}

		return (
			'<div class="yp-panel" data-yp-wd-panel>' +
				'<div class="yp-panel__head"><h2>Web Design Project</h2></div>' +
				'<p class="yp-field__hint" data-yp-wd-loading>Loading&hellip;</p>' +
			'</div>'
		);
	}

	function loadWebDesignPanel( order, bodyEl ) {
		var panel = bodyEl.querySelector( '[data-yp-wd-panel]' );
		if ( ! panel ) {
			return;
		}

		YP.request( yeffoprintAdminApp.restUrl + 'admin/web-design/' + order.id )
			.then( function ( project ) { renderWebDesignPanel( project, panel, order, bodyEl ); } )
			.catch( function ( error ) {
				panel.querySelector( '[data-yp-wd-loading]' ).outerHTML = '<p class="yp-form__error">Couldn’t load this project: ' + YP.escapeHtml( error.message ) + '</p>';
			} );
	}

	var WD_STAGE_PILLS = {
		agreement_pending: 'warn',
		staging_in_progress: 'neutral',
		client_reviewing: 'neutral',
		golive_pending: 'warn',
		golive_received: 'good',
		live: 'good'
	};

	var WD_STAGE_LABELS = {
		agreement_pending: 'Awaiting agreement',
		staging_in_progress: 'Staging in progress',
		client_reviewing: 'Client reviewing',
		golive_pending: 'Ready for go-live access',
		golive_received: 'Go-live access received',
		live: 'Live'
	};

	function renderWebDesignPanel( project, panel, order, bodyEl ) {
		panel.innerHTML =
			'<div class="yp-panel__head">' +
				'<h2>Web Design Project — ' + YP.escapeHtml( project.package_name ) + '</h2>' +
				'<span class="yp-pill yp-pill--' + ( WD_STAGE_PILLS[ project.stage ] || 'neutral' ) + '">' + YP.escapeHtml( WD_STAGE_LABELS[ project.stage ] || project.stage ) + '</span>' +
			'</div>' +
			project.stepper_html +
			webDesignAgreementHtml( project ) +
			webDesignMilestonesHtml( project ) +
			webDesignStagingHtml( project ) +
			webDesignGoLiveHtml( project ) +
			webDesignUpdatesHtml( project );

		bindWebDesignAgreement( project, panel, order );
		bindWebDesignMilestones( project, panel, order );
		bindWebDesignStaging( project, panel, order );
		bindWebDesignGoLive( project, panel, order, bodyEl );
		bindWebDesignUpdates( project, panel, order );
	}

	/* ---------- Agreement ---------- */

	function milestoneRowHtml( row ) {
		row = row || { label: '', due_date: '', done: false };
		return (
			'<tr>' +
				'<td><label class="yp-field--checkbox yp-field" style="margin:0;"><input type="checkbox" data-wd-milestone-done' + ( row.done ? ' checked' : '' ) + ' /></label></td>' +
				'<td><input type="text" data-wd-milestone-label value="' + YP.escapeAttr( row.label ) + '" placeholder="Milestone" /></td>' +
				'<td><input type="date" data-wd-milestone-date value="' + YP.escapeAttr( row.due_date ) + '" /></td>' +
				'<td><button type="button" class="yp-row-action" data-yp-remove-row aria-label="Remove milestone">&times;</button></td>' +
			'</tr>'
		);
	}

	function addonRowHtml( row ) {
		row = row || { label: '', price: '' };
		return (
			'<tr>' +
				'<td><input type="text" data-wd-addon-label value="' + YP.escapeAttr( row.label ) + '" placeholder="Add-on" /></td>' +
				'<td><input type="number" step="0.01" min="0" data-wd-addon-price value="' + YP.escapeAttr( row.price ) + '" placeholder="0.00" /></td>' +
				'<td><button type="button" class="yp-row-action" data-yp-remove-row aria-label="Remove add-on">&times;</button></td>' +
			'</tr>'
		);
	}

	function wireRemoveRowButtons( tbody, minRows ) {
		tbody.querySelectorAll( '[data-yp-remove-row]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( tbody.querySelectorAll( 'tr' ).length > minRows ) {
					button.closest( 'tr' ).remove();
				}
			} );
		} );
	}

	function webDesignAgreementHtml( project ) {
		var a = project.agreement;
		var addons = a.addons.length ? a.addons : [ null ];
		var signed = !! a.signed_at;

		var signedNotice = signed
			? '<p class="yp-panel__hint">Signed by <strong>' + YP.escapeHtml( a.signed_name ) + '</strong> on ' + YP.escapeHtml( new Date( a.signed_at.replace( ' ', 'T' ) ).toLocaleString() ) + '.</p>'
			: ( a.sent_at
				? '<p class="yp-panel__hint">Sent to the customer on ' + YP.escapeHtml( new Date( a.sent_at.replace( ' ', 'T' ) ).toLocaleString() ) + ' — awaiting signature. Link: <code>' + YP.escapeHtml( project.links.agreement ) + '</code></p>'
				: '' );

		return (
			'<div class="yp-panel yp-panel--compact" data-yp-wd-agreement>' +
				'<div class="yp-panel__head"><h3>Agreement</h3></div>' +
				signedNotice +
				'<div class="yp-form__row">' +
					'<div class="yp-field"><label>Kickoff date</label><input type="date" data-wd-kickoff value="' + YP.escapeAttr( a.kickoff_date ) + '"' + ( signed ? ' disabled' : '' ) + ' /></div>' +
					'<div class="yp-field"><label>Staging due</label><input type="date" data-wd-staging-due value="' + YP.escapeAttr( a.staging_due ) + '"' + ( signed ? ' disabled' : '' ) + ' /></div>' +
					'<div class="yp-field"><label>Go-live due</label><input type="date" data-wd-golive-due value="' + YP.escapeAttr( a.golive_due ) + '"' + ( signed ? ' disabled' : '' ) + ' /></div>' +
				'</div>' +
				'<p class="yp-field__hint">Add-ons</p>' +
				'<table class="yp-record-table"><tbody data-wd-addons>' + addons.map( addonRowHtml ).join( '' ) + '</tbody></table>' +
				( signed ? '' : '<button type="button" class="yp-row-action" data-yp-wd-add-addon>+ Add add-on</button>' ) +
				'<div class="yp-field" style="margin-top:0.75rem;"><label>Scope &amp; expectations</label><textarea rows="4" data-wd-scope' + ( signed ? ' disabled' : '' ) + '>' + YP.escapeHtml( a.scope_text ) + '</textarea></div>' +
				( signed
					? ''
					: '<div class="yp-form__row">' +
						'<button type="button" class="wp-block-button__link is-style-outline" data-yp-wd-save-agreement>Save Draft</button>' +
						'<button type="button" class="wp-block-button__link is-style-accent" data-yp-wd-send-agreement>Send Agreement to Customer &rarr;</button>' +
					'</div>' ) +
				'<div data-yp-wd-agreement-error></div>' +
			'</div>'
		);
	}

	function readWebDesignAgreementForm( panel ) {
		function rows( selector, mapRow ) {
			return Array.prototype.slice.call( panel.querySelectorAll( selector + ' tr' ) ).map( mapRow ).filter( function ( row ) { return row; } );
		}

		return {
			kickoff_date: panel.querySelector( '[data-wd-kickoff]' ).value,
			staging_due: panel.querySelector( '[data-wd-staging-due]' ).value,
			golive_due: panel.querySelector( '[data-wd-golive-due]' ).value,
			addons: rows( '[data-wd-addons]', function ( tr ) {
				var label = tr.querySelector( '[data-wd-addon-label]' ).value.trim();
				var price = tr.querySelector( '[data-wd-addon-price]' ).value;
				return label ? { label: label, price: price } : null;
			} ),
			scope_text: panel.querySelector( '[data-wd-scope]' ).value
		};
	}

	function bindWebDesignAgreement( project, panel, order ) {
		var addonsBody = panel.querySelector( '[data-wd-addons]' );
		if ( addonsBody ) {
			wireRemoveRowButtons( addonsBody, 1 );
		}

		var addAddonButton = panel.querySelector( '[data-yp-wd-add-addon]' );
		if ( addAddonButton ) {
			addAddonButton.addEventListener( 'click', function () {
				addonsBody.insertAdjacentHTML( 'beforeend', addonRowHtml( null ) );
				wireRemoveRowButtons( addonsBody, 1 );
			} );
		}

		var errorEl = panel.querySelector( '[data-yp-wd-agreement-error]' );

		var saveButton = panel.querySelector( '[data-yp-wd-save-agreement]' );
		if ( saveButton ) {
			saveButton.addEventListener( 'click', function () {
				saveButton.disabled = true;
				errorEl.innerHTML = '';
				YP.request( yeffoprintAdminApp.restUrl + 'admin/web-design/' + order.id + '/agreement', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( readWebDesignAgreementForm( panel ) )
				} )
					.then( function () { saveButton.disabled = false; } )
					.catch( function ( error ) {
						saveButton.disabled = false;
						errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
					} );
			} );
		}

		var sendButton = panel.querySelector( '[data-yp-wd-send-agreement]' );
		if ( sendButton ) {
			sendButton.addEventListener( 'click', function () {
				sendButton.disabled = true;
				errorEl.innerHTML = '';
				YP.request( yeffoprintAdminApp.restUrl + 'admin/web-design/' + order.id + '/agreement', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( readWebDesignAgreementForm( panel ) )
				} )
					.then( function () {
						return YP.request( yeffoprintAdminApp.restUrl + 'admin/web-design/' + order.id + '/agreement/send', { method: 'POST' } );
					} )
					.then( function ( refreshed ) {
						renderWebDesignPanel( refreshed, panel, order, panel.closest( '[data-yp-body]' ) );
					} )
					.catch( function ( error ) {
						sendButton.disabled = false;
						errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
					} );
			} );
		}
	}

	/* ---------- Milestones ---------- */

	/**
	 * Direct request: milestones need to stay addable/editable — label,
	 * due date, and a done/not-done checkbox — as a project actually
	 * progresses, whether or not the agreement has been signed yet. Its
	 * own panel/save path (class-admin-web-design-controller.php's
	 * save_milestones(), never gated by is_agreement_signed()) rather
	 * than living inside webDesignAgreementHtml() above, which does lock
	 * once signed — a due date slipping a week is a normal update, not a
	 * change to the signed terms.
	 */
	function webDesignMilestonesHtml( project ) {
		var milestones = project.agreement.milestones.length ? project.agreement.milestones : [ null ];

		return (
			'<div class="yp-panel yp-panel--compact" data-yp-wd-milestones-panel>' +
				'<div class="yp-panel__head"><h3>Milestones</h3></div>' +
				'<p class="yp-panel__hint">Visible to the customer on their agreement page as the project progresses.</p>' +
				'<table class="yp-record-table"><tbody data-wd-milestones>' + milestones.map( milestoneRowHtml ).join( '' ) + '</tbody></table>' +
				'<button type="button" class="yp-row-action" data-yp-wd-add-milestone>+ Add milestone</button>' +
				'<div class="yp-form__row">' +
					'<button type="button" class="wp-block-button__link is-style-accent" data-yp-wd-save-milestones>Save Milestones</button>' +
				'</div>' +
				'<div data-yp-wd-milestones-error></div>' +
			'</div>'
		);
	}

	function readWebDesignMilestonesForm( panel ) {
		var tbody = panel.querySelector( '[data-wd-milestones]' );
		return Array.prototype.slice.call( tbody.querySelectorAll( 'tr' ) ).map( function ( tr ) {
			var label = tr.querySelector( '[data-wd-milestone-label]' ).value.trim();
			if ( ! label ) {
				return null;
			}
			return {
				label: label,
				due_date: tr.querySelector( '[data-wd-milestone-date]' ).value,
				done: tr.querySelector( '[data-wd-milestone-done]' ).checked
			};
		} ).filter( function ( row ) { return row; } );
	}

	function bindWebDesignMilestones( project, panel, order ) {
		var milestonesBody = panel.querySelector( '[data-yp-wd-milestones-panel] [data-wd-milestones]' );
		if ( ! milestonesBody ) {
			return;
		}

		wireRemoveRowButtons( milestonesBody, 1 );

		var milestonesPanel = panel.querySelector( '[data-yp-wd-milestones-panel]' );

		milestonesPanel.querySelector( '[data-yp-wd-add-milestone]' ).addEventListener( 'click', function () {
			milestonesBody.insertAdjacentHTML( 'beforeend', milestoneRowHtml( null ) );
			wireRemoveRowButtons( milestonesBody, 1 );
		} );

		var errorEl = milestonesPanel.querySelector( '[data-yp-wd-milestones-error]' );
		var saveButton = milestonesPanel.querySelector( '[data-yp-wd-save-milestones]' );

		saveButton.addEventListener( 'click', function () {
			saveButton.disabled = true;
			errorEl.innerHTML = '';
			YP.request( yeffoprintAdminApp.restUrl + 'admin/web-design/' + order.id + '/milestones', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { milestones: readWebDesignMilestonesForm( milestonesPanel ) } )
			} )
				.then( function ( refreshed ) { renderWebDesignPanel( refreshed, panel, order, panel.closest( '[data-yp-body]' ) ); } )
				.catch( function ( error ) {
					saveButton.disabled = false;
					errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
				} );
		} );
	}

	/* ---------- Staging ---------- */

	function webDesignStagingHtml( project ) {
		var s = project.staging;
		var sent = !! s.sent_at;
		var response = project.client_response.response;

		var responseNotice = '';
		if ( 'approved' === response ) {
			responseNotice = '<p class="yp-panel__hint">Customer approved the staging site on ' + YP.escapeHtml( new Date( project.client_response.at.replace( ' ', 'T' ) ).toLocaleString() ) + '.</p>';
		} else if ( 'changes_requested' === response ) {
			responseNotice = '<p class="yp-panel__hint">Customer requested changes on ' + YP.escapeHtml( new Date( project.client_response.at.replace( ' ', 'T' ) ).toLocaleString() ) + ':</p><p class="yp-panel__hint"><em>' + YP.escapeHtml( project.client_response.notes ) + '</em></p>';
		} else if ( sent ) {
			responseNotice = '<p class="yp-panel__hint">Sent on ' + YP.escapeHtml( new Date( s.sent_at.replace( ' ', 'T' ) ).toLocaleString() ) + ' — awaiting the customer’s response. Review link: <code>' + YP.escapeHtml( project.links.staging_review ) + '</code></p>';
		}

		return (
			'<div class="yp-panel yp-panel--compact" data-yp-wd-staging>' +
				'<div class="yp-panel__head"><h3>Staged Site</h3></div>' +
				'<div class="yp-form__row">' +
					'<div class="yp-field"><label>Staging URL</label><input type="url" data-wd-staging-url value="' + YP.escapeAttr( s.staging_url ) + '" placeholder="https://staging-example.yeffoprint.dev" /></div>' +
					'<div class="yp-field"><label>WP-admin URL</label><input type="url" data-wd-staging-admin-url value="' + YP.escapeAttr( s.staging_admin_url ) + '" /></div>' +
				'</div>' +
				'<p class="yp-field__hint">Internal admin login — never sent to the customer.</p>' +
				'<div class="yp-form__row">' +
					'<div class="yp-field"><label>Username</label><input type="text" data-wd-admin-user value="' + YP.escapeAttr( s.admin_user ) + '" /></div>' +
					'<div class="yp-field"><label>Password' + ( s.has_admin_password ? ' <button type="button" class="yp-row-action" data-yp-wd-reveal="staging_admin">reveal</button>' : '' ) + '</label><input type="password" data-wd-admin-pass placeholder="' + ( s.has_admin_password ? '•••••••• (leave blank to keep)' : 'Set a password' ) + '" /></div>' +
				'</div>' +
				'<p class="yp-field__hint">Client preview login — this is what gets emailed.</p>' +
				'<div class="yp-form__row">' +
					'<div class="yp-field"><label>Username</label><input type="text" data-wd-preview-user value="' + YP.escapeAttr( s.preview_user ) + '" /></div>' +
					'<div class="yp-field"><label>Password' + ( s.has_preview_password ? ' <button type="button" class="yp-row-action" data-yp-wd-reveal="staging_preview">reveal</button>' : '' ) + '</label><input type="password" data-wd-preview-pass placeholder="' + ( s.has_preview_password ? '•••••••• (leave blank to keep)' : 'Set a password' ) + '" /></div>' +
				'</div>' +
				'<div class="yp-field"><label>Note to include in the email</label><textarea rows="2" data-wd-staging-note>' + YP.escapeHtml( s.note ) + '</textarea></div>' +
				'<div data-yp-wd-reveal-output></div>' +
				responseNotice +
				'<div class="yp-form__row">' +
					'<button type="button" class="wp-block-button__link is-style-outline" data-yp-wd-save-staging>Save Draft</button>' +
					'<button type="button" class="wp-block-button__link is-style-accent" data-yp-wd-send-staging>Send Staging Credentials &rarr;</button>' +
				'</div>' +
				'<div data-yp-wd-staging-error></div>' +
			'</div>'
		);
	}

	function readWebDesignStagingForm( panel ) {
		return {
			staging_url: panel.querySelector( '[data-wd-staging-url]' ).value,
			staging_admin_url: panel.querySelector( '[data-wd-staging-admin-url]' ).value,
			admin_user: panel.querySelector( '[data-wd-admin-user]' ).value,
			admin_password: panel.querySelector( '[data-wd-admin-pass]' ).value,
			preview_user: panel.querySelector( '[data-wd-preview-user]' ).value,
			preview_password: panel.querySelector( '[data-wd-preview-pass]' ).value,
			note: panel.querySelector( '[data-wd-staging-note]' ).value
		};
	}

	function bindWebDesignRevealButtons( panel, order ) {
		panel.querySelectorAll( '[data-yp-wd-reveal]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var which = button.getAttribute( 'data-yp-wd-reveal' );
				var outputEl = panel.querySelector( '[data-yp-wd-reveal-output]' );
				button.disabled = true;
				YP.request( yeffoprintAdminApp.restUrl + 'admin/web-design/' + order.id + '/reveal', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { which: which } )
				} )
					.then( function ( result ) {
						button.disabled = false;
						if ( outputEl ) {
							outputEl.innerHTML = '<p class="yp-panel__hint">Password: <code>' + YP.escapeHtml( result.value ) + '</code></p>';
						}
					} )
					.catch( function ( error ) {
						button.disabled = false;
						window.alert( 'Couldn’t reveal this password: ' + error.message );
					} );
			} );
		} );
	}

	function bindWebDesignStaging( project, panel, order ) {
		bindWebDesignRevealButtons( panel, order );

		var errorEl = panel.querySelector( '[data-yp-wd-staging-error]' );

		var saveButton = panel.querySelector( '[data-yp-wd-save-staging]' );
		if ( saveButton ) {
			saveButton.addEventListener( 'click', function () {
				saveButton.disabled = true;
				errorEl.innerHTML = '';
				YP.request( yeffoprintAdminApp.restUrl + 'admin/web-design/' + order.id + '/staging', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( readWebDesignStagingForm( panel ) )
				} )
					.then( function ( refreshed ) { renderWebDesignPanel( refreshed, panel, order, panel.closest( '[data-yp-body]' ) ); } )
					.catch( function ( error ) {
						saveButton.disabled = false;
						errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
					} );
			} );
		}

		var sendButton = panel.querySelector( '[data-yp-wd-send-staging]' );
		if ( sendButton ) {
			sendButton.addEventListener( 'click', function () {
				sendButton.disabled = true;
				errorEl.innerHTML = '';
				YP.request( yeffoprintAdminApp.restUrl + 'admin/web-design/' + order.id + '/staging', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( readWebDesignStagingForm( panel ) )
				} )
					.then( function () {
						return YP.request( yeffoprintAdminApp.restUrl + 'admin/web-design/' + order.id + '/staging/send', { method: 'POST' } );
					} )
					.then( function ( refreshed ) { renderWebDesignPanel( refreshed, panel, order, panel.closest( '[data-yp-body]' ) ); } )
					.catch( function ( error ) {
						sendButton.disabled = false;
						errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
					} );
			} );
		}
	}

	/* ---------- Go-live ---------- */

	function webDesignGoLiveHtml( project ) {
		var g = project.golive;
		var received = !! g.submitted_at && ! g.purged;

		if ( project.is_live ) {
			return (
				'<div class="yp-panel yp-panel--compact" data-yp-wd-golive>' +
					'<div class="yp-panel__head"><h3>Go-Live</h3></div>' +
					'<p class="yp-panel__hint">This site is live. Go-live credentials were deleted once it went live.</p>' +
				'</div>'
			);
		}

		if ( ! received ) {
			return (
				'<div class="yp-panel yp-panel--compact" data-yp-wd-golive>' +
					'<div class="yp-panel__head"><h3>Go-Live</h3></div>' +
					'<p class="yp-panel__hint">Waiting on the customer to approve staging and submit their server access. Link: <code>' + YP.escapeHtml( project.links.golive ) + '</code></p>' +
				'</div>'
			);
		}

		return (
			'<div class="yp-panel yp-panel--compact" data-yp-wd-golive>' +
				'<div class="yp-panel__head"><h3>Go-Live Access Received</h3></div>' +
				'<p class="yp-panel__hint">Submitted ' + YP.escapeHtml( new Date( g.submitted_at.replace( ' ', 'T' ) ).toLocaleString() ) + ' · Method: ' + ( 'wp_admin' === g.method ? 'WordPress admin' : 'FTP / SFTP' ) + '</p>' +
				( 'wp_admin' === g.method
					? '<div class="yp-split__fields">' + wcOrderField( 'WP-admin URL', YP.escapeHtml( g.wp_url ) ) + '</div>'
					: '<div class="yp-split__fields">' + wcOrderField( 'Host', YP.escapeHtml( g.host ) + ( g.port ? ':' + YP.escapeHtml( g.port ) : '' ) ) + '</div>' ) +
				'<div class="yp-split__fields">' + wcOrderField( 'Username', YP.escapeHtml( g.username ) ) + '</div>' +
				( g.notes ? '<div class="yp-split__fields">' + wcOrderField( 'Customer note', YP.escapeHtml( g.notes ) ) + '</div>' : '' ) +
				'<p class="yp-field__hint">Password: ' + ( g.has_password ? '<button type="button" class="yp-row-action" data-yp-wd-reveal="golive">reveal</button>' : '(none stored)' ) + '</p>' +
				'<div data-yp-wd-reveal-output></div>' +
				'<p class="yp-field__hint">Access auto-deletes on ' + YP.escapeHtml( g.expires_at ) + '.</p>' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-wd-mark-live>Mark Site as Live &rarr;</button>' +
				'<div data-yp-wd-golive-error></div>' +
			'</div>'
		);
	}

	function bindWebDesignGoLive( project, panel, order, bodyEl ) {
		bindWebDesignRevealButtons( panel, order );

		var markLiveButton = panel.querySelector( '[data-yp-wd-mark-live]' );
		if ( ! markLiveButton ) {
			return;
		}

		markLiveButton.addEventListener( 'click', function () {
			YP.confirmModal( {
				title: 'Mark this site as live?',
				message: 'This deletes the stored go-live server password — make sure the upload is finished first.',
				confirmLabel: 'Mark as Live',
				onConfirm: function () {
					markLiveButton.disabled = true;
					YP.request( yeffoprintAdminApp.restUrl + 'admin/web-design/' + order.id + '/mark-live', { method: 'POST' } )
						.then( function ( refreshed ) { renderWebDesignPanel( refreshed, panel, order, bodyEl ); } )
						.catch( function ( error ) {
							markLiveButton.disabled = false;
							var errorEl = panel.querySelector( '[data-yp-wd-golive-error]' );
							if ( errorEl ) {
								errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
							}
						} );
				}
			} );
		} );
	}

	/* ---------- Progress reports & site activity ---------- */

	/**
	 * Direct request: "provide progress reports to the customer by
	 * email but also let them access all of the changes that have been
	 * made on the site." Two things live here: a compose form for
	 * staff-written progress reports (project.progress_reports), and
	 * the digest webhook staff hand to the project's own nightly
	 * site-update job (project.digest) — the source of
	 * project.site_updates, which this panel only ever displays, never
	 * edits, since that list is machine-ingested.
	 */
	function webDesignUpdatesHtml( project ) {
		var reports = project.progress_reports || [];
		var updates = project.site_updates || [];
		var digest = project.digest || { url: '', token: '' };

		var reportsList = reports.length
			? '<div class="yp-list-rows">' + reports.map( function ( r ) {
				return (
					'<div class="yp-list-row"><div>' +
						'<strong>' + YP.escapeHtml( r.headline ) + '</strong>' +
						'<p class="yp-field__hint">' + YP.escapeHtml( new Date( r.created_at.replace( ' ', 'T' ) ).toLocaleString() ) + ' &middot; ' + YP.escapeHtml( r.staff_name ) + '</p>' +
					'</div></div>'
				);
			} ).join( '' ) + '</div>'
			: '<p class="yp-field__hint">No progress reports sent yet.</p>';

		var updatesList = updates.length
			? '<div class="yp-list-rows">' + updates.map( function ( u ) {
				var count = ( u.items || [] ).length;
				return (
					'<div class="yp-list-row"><div>' +
						'<strong>' + YP.escapeHtml( u.date ) + '</strong>' +
						'<p class="yp-field__hint">' + count + ' change' + ( 1 === count ? '' : 's' ) + ' ingested</p>' +
					'</div></div>'
				);
			} ).join( '' ) + '</div>'
			: '<p class="yp-field__hint">No site-activity digests received yet.</p>';

		return (
			'<div class="yp-panel yp-panel--compact" data-yp-wd-updates>' +
				'<div class="yp-panel__head"><h3>Progress Reports &amp; Site Activity</h3></div>' +
				'<p class="yp-panel__hint">Customer dashboard: <code>' + YP.escapeHtml( project.links.updates ) + '</code></p>' +

				'<div class="yp-field"><label>Digest webhook URL (this project’s own nightly site-update job POSTs here)</label>' +
					'<input type="text" readonly value="' + YP.escapeAttr( digest.url ) + '" onclick="this.select()" />' +
				'</div>' +
				'<div class="yp-field"><label>Digest token (send as header <code>Authorization: Bearer &lt;token&gt;</code>)</label>' +
					'<input type="text" readonly value="' + YP.escapeAttr( digest.token ) + '" onclick="this.select()" />' +
				'</div>' +
				'<button type="button" class="yp-row-action" data-yp-wd-regen-token>Regenerate token</button>' +

				'<h4 style="margin-top:1.25rem;">Send a progress report</h4>' +
				'<div class="yp-field"><label>Headline</label><input type="text" data-wd-report-headline placeholder="e.g. Product catalog polish is underway" /></div>' +
				'<div class="yp-field"><label>Message</label><textarea rows="3" data-wd-report-message placeholder="What you want the customer to know"></textarea></div>' +
				'<label class="yp-field--checkbox yp-field"><input type="checkbox" data-wd-report-notify checked /> Email this to the customer</label>' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-wd-send-report>Send Progress Report</button>' +
				'<div data-yp-wd-report-error></div>' +

				'<h4 style="margin-top:1.25rem;">Recent progress reports</h4>' + reportsList +
				'<h4 style="margin-top:1.25rem;">Recent site activity</h4>' + updatesList +
			'</div>'
		);
	}

	function bindWebDesignUpdates( project, panel, order ) {
		var errorEl = panel.querySelector( '[data-yp-wd-report-error]' );

		var regenButton = panel.querySelector( '[data-yp-wd-regen-token]' );
		if ( regenButton ) {
			regenButton.addEventListener( 'click', function () {
				YP.confirmModal( {
					title: 'Regenerate the digest token?',
					message: 'The current token stops working immediately — update this project’s nightly site-update job with the new one, or it will stop reaching this dashboard.',
					confirmLabel: 'Regenerate',
					onConfirm: function () {
						regenButton.disabled = true;
						YP.request( yeffoprintAdminApp.restUrl + 'admin/web-design/' + order.id + '/site-update-token/regenerate', { method: 'POST' } )
							.then( function ( refreshed ) { renderWebDesignPanel( refreshed, panel, order, panel.closest( '[data-yp-body]' ) ); } )
							.catch( function ( error ) {
								regenButton.disabled = false;
								errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
							} );
					}
				} );
			} );
		}

		var sendButton = panel.querySelector( '[data-yp-wd-send-report]' );
		if ( sendButton ) {
			sendButton.addEventListener( 'click', function () {
				var headline = panel.querySelector( '[data-wd-report-headline]' ).value.trim();
				if ( ! headline ) {
					errorEl.innerHTML = '<p class="yp-form__error">Add a headline first.</p>';
					return;
				}

				sendButton.disabled = true;
				errorEl.innerHTML = '';
				YP.request( yeffoprintAdminApp.restUrl + 'admin/web-design/' + order.id + '/progress-report', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						headline: headline,
						message: panel.querySelector( '[data-wd-report-message]' ).value,
						notify_customer: panel.querySelector( '[data-wd-report-notify]' ).checked
					} )
				} )
					.then( function ( refreshed ) { renderWebDesignPanel( refreshed, panel, order, panel.closest( '[data-yp-body]' ) ); } )
					.catch( function ( error ) {
						sendButton.disabled = false;
						errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
					} );
			} );
		}
	}

	/**
	 * Direct request: "add notes to customers so when I print their
	 * future orders I can refer to them." Same email-keyed note history
	 * as the Customers screen's own notes panel (views/customers.js) —
	 * order.customer_notes (class-admin-order-controller.php's
	 * detail_payload()) and this panel's own add/delete calls both read
	 * and write YeffoPrint_Customer_Notes, so a note added from either
	 * place immediately shows up in the other.
	 */
	function customerNotesPanelHtml( order ) {
		var notes = order.customer_notes || [];
		var list = notes.length
			? '<div class="yp-customer-notes-list">' + notes.map( function ( note ) {
				return (
					'<div class="yp-customer-note">' +
						'<div class="yp-customer-note__meta">' +
							'<span>' + YP.escapeHtml( note.created_by_name ) + '</span>' +
							'<span>' + YP.escapeHtml( new Date( note.created_at.replace( ' ', 'T' ) ).toLocaleString() ) + '</span>' +
						'</div>' +
						'<div class="yp-customer-note__text">' + YP.escapeHtml( note.note ) + '</div>' +
						'<button type="button" class="yp-row-action" data-yp-delete-note="' + note.id + '">Delete</button>' +
					'</div>'
				);
			} ).join( '' ) + '</div>'
			: '<p class="yp-field__hint">No notes on this customer yet.</p>';

		return (
			'<div class="yp-panel yp-panel--compact" data-yp-notes-panel>' +
				'<div class="yp-panel__head"><h2>Customer Notes</h2></div>' +
				'<div data-yp-notes-list>' + list + '</div>' +
				'<textarea class="yp-customer-note-input" data-yp-note-input placeholder="Add a note about this customer&hellip;" rows="2"></textarea>' +
				'<button type="button" class="wp-block-button__link is-style-outline" data-yp-add-note>Add Note</button>' +
				'<div data-yp-note-error></div>' +
			'</div>'
		);
	}

	function bindCustomerNotesPanel( order, bodyEl ) {
		var panel = bodyEl.querySelector( '[data-yp-notes-panel]' );
		if ( ! panel ) {
			return;
		}

		function bindDeleteButtons() {
			panel.querySelectorAll( '[data-yp-delete-note]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var noteId = button.getAttribute( 'data-yp-delete-note' );
					YP.confirmModal( {
						title: 'Delete this note?',
						message: 'This can’t be undone.',
						confirmLabel: 'Delete Note',
						danger: true,
						onConfirm: function () {
							YP.request( yeffoprintAdminApp.restUrl + 'admin/customer-notes/' + noteId, { method: 'DELETE' } )
								.then( function () {
									button.closest( '.yp-customer-note' ).remove();
									var listEl = panel.querySelector( '[data-yp-notes-list]' );
									if ( listEl && ! listEl.querySelector( '.yp-customer-note' ) ) {
										listEl.innerHTML = '<p class="yp-field__hint">No notes on this customer yet.</p>';
									}
								} )
								.catch( function ( error ) {
									window.alert( 'Couldn’t delete this note: ' + error.message );
								} );
						}
					} );
				} );
			} );
		}

		panel.querySelector( '[data-yp-add-note]' ).addEventListener( 'click', function () {
			var input = panel.querySelector( '[data-yp-note-input]' );
			var errorEl = panel.querySelector( '[data-yp-note-error]' );
			var addButton = panel.querySelector( '[data-yp-add-note]' );
			var note = input.value.trim();
			if ( ! note ) {
				return;
			}

			addButton.disabled = true;
			errorEl.innerHTML = '';

			YP.request( yeffoprintAdminApp.restUrl + 'admin/customer-notes', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { email: order.customer_email, note: note } )
			} )
				.then( function ( notes ) {
					addButton.disabled = false;
					order.customer_notes = notes;
					var listEl = panel.querySelector( '[data-yp-notes-list]' );
					var refreshed = customerNotesPanelHtml( order );
					// Swap only the inner list markup, not the whole
					// panel — replacing the panel wholesale would also
					// wipe out whatever the staff member had mid-typed
					// in the textarea a moment from now.
					var temp = document.createElement( 'div' );
					temp.innerHTML = refreshed;
					listEl.innerHTML = temp.querySelector( '[data-yp-notes-list]' ).innerHTML;
					input.value = '';
					bindDeleteButtons();
				} )
				.catch( function ( error ) {
					addButton.disabled = false;
					errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
				} );
		} );

		bindDeleteButtons();
	}

	/**
	 * Direct request: refund an order without leaving this app. Mirrors
	 * the classic order screen's own refund panel — an amount (defaults
	 * to whatever's still refundable), an optional reason, and, only
	 * when this order's payment method actually supports it
	 * (order.refund_gateway_supported — WooCommerce Payments does,
	 * this store's Manual/Venmo/Zelle/Coinbase gateways don't), a
	 * checkbox to also attempt a real refund through that processor
	 * rather than just recording one locally.
	 */
	function refundPanelHtml( order ) {
		var refundsList = ( order.refunds || [] ).length
			? '<div class="yp-list-rows">' + order.refunds.map( function ( refund ) {
				return (
					'<div class="yp-list-row">' +
						'<div class="yp-list-row__text">' +
							'<span class="t">$' + refund.amount.toFixed( 2 ) + '</span>' +
							'<span class="s">' + ( refund.reason ? YP.escapeHtml( refund.reason ) + ' — ' : '' ) + ( refund.date ? new Date( refund.date ).toLocaleDateString() : '' ) + '</span>' +
						'</div>' +
					'</div>'
				);
			} ).join( '' ) + '</div>'
			: '';

		var remaining = order.remaining_refund_amount || 0;
		var form = remaining > 0
			? (
				'<div class="yp-form__row">' +
					'<div class="yp-field"><label for="yp-refund-amount">Amount</label><input type="number" step="0.01" min="0.01" max="' + remaining.toFixed( 2 ) + '" id="yp-refund-amount" data-yp-refund-amount value="' + remaining.toFixed( 2 ) + '" /></div>' +
					'<div class="yp-field"><label for="yp-refund-reason">Reason (optional)</label><input type="text" id="yp-refund-reason" data-yp-refund-reason /></div>' +
				'</div>' +
				( order.refund_gateway_supported
					? '<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-refund-gateway" data-yp-refund-gateway checked /><label for="yp-refund-gateway">Also refund through ' + YP.escapeHtml( order.payment_method_title || 'the payment processor' ) + '</label></div>'
					: '<p class="yp-field__hint">' + YP.escapeHtml( order.payment_method_title || 'This order’s payment method' ) + ' doesn’t support automatic refunds — this only records the refund here; send the money back to the customer yourself.</p>' ) +
				'<button type="button" class="wp-block-button__link yp-button--danger" data-yp-refund-submit>Refund Order</button>' +
				'<div data-yp-refund-error></div>'
			)
			: '<p class="yp-field__hint">Nothing left to refund on this order.</p>';

		return (
			'<div class="yp-panel yp-panel--compact" data-yp-refund-panel>' +
				'<div class="yp-panel__head"><h2>Refund</h2></div>' +
				'<p class="yp-panel__hint">Total refunded so far: $' + ( order.total_refunded || 0 ).toFixed( 2 ) + ' of $' + order.total.toFixed( 2 ) + '</p>' +
				refundsList +
				form +
			'</div>'
		);
	}

	function bindRefundPanel( order, bodyEl, drawer ) {
		var panel = bodyEl.querySelector( '[data-yp-refund-panel]' );
		var submitButton = panel ? panel.querySelector( '[data-yp-refund-submit]' ) : null;
		if ( ! submitButton ) {
			return;
		}

		submitButton.addEventListener( 'click', function () {
			var amount = parseFloat( panel.querySelector( '[data-yp-refund-amount]' ).value ) || 0;
			var reason = panel.querySelector( '[data-yp-refund-reason]' ).value;
			var gatewayCheckbox = panel.querySelector( '[data-yp-refund-gateway]' );
			var viaGateway = gatewayCheckbox ? gatewayCheckbox.checked : false;
			var errorEl = panel.querySelector( '[data-yp-refund-error]' );

			if ( amount <= 0 ) {
				errorEl.innerHTML = '<p class="yp-form__error">Enter an amount to refund.</p>';
				return;
			}

			YP.confirmModal( {
				title: 'Refund this order?',
				message: 'Refund $' + amount.toFixed( 2 ) + ' on this order?' + ( viaGateway ? ' This will attempt a real refund through ' + ( order.payment_method_title || 'the payment processor' ) + '.' : ' This only records the refund — you’ll need to send the money back yourself.' ),
				confirmLabel: 'Refund Order',
				danger: true,
				onConfirm: function () {
					submitButton.disabled = true;
					submitButton.textContent = 'Refunding…';
					errorEl.innerHTML = '';

					YP.request( yeffoprintAdminApp.restUrl + 'admin/order/' + order.id + '/refund', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( { amount: amount, reason: reason, refund_via_gateway: viaGateway } )
					} )
						.then( function ( updated ) {
							renderWcOrderDetail( updated, drawer, bodyEl );
							loadDashboard();
						} )
						.catch( function ( error ) {
							submitButton.disabled = false;
							submitButton.textContent = 'Refund Order';
							errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
						} );
				}
			} );
		} );
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
	 * text and the styled confirm dialog (YP.confirmModal(),
	 * renderShippoRates() below) both make explicit before it fires.
	 * "(Beta)" dropped from this panel's heading — direct request:
	 * "we're live with shippo."
	 */
	/**
	 * Direct request: "need the ability to go back and print the label
	 * later" plus, in the same round, "the print button is just a
	 * link... style the print button for the labels." Renders every
	 * label ever purchased on this order (class-admin-order-controller.php's
	 * detail_payload(), sourced from YeffoPrint_Order_Tracking::
	 * get_shippo_labels()) as a real row with real buttons — separate
	 * from the "Label purchased" confirmation message purchaseShippoLabel()
	 * below shows, which only exists for the duration of the drawer
	 * session a label was bought in.
	 *
	 * A voided label (direct request: "keep both active by default, give
	 * me a way to void it if necessary") stays in this list rather than
	 * disappearing — dimmed, with a "Voided" badge instead of the Void
	 * button, so the order's full label history stays visible ("I'll
	 * need to see the previous shipping information also").
	 */
	function shippoLabelsListHtml( labels ) {
		if ( ! labels.length ) {
			return '';
		}
		return (
			'<div class="yp-panel__hint" style="margin:0 0 0.5rem;"><strong>Purchased labels</strong></div>' +
			'<ul class="yp-shippo-labels-list">' +
				labels.map( function ( label ) {
					return (
						'<li class="yp-shippo-label-row' + ( label.voided ? ' yp-shippo-label-row--voided' : '' ) + '">' +
							'<span class="yp-shippo-label-row__info">' + YP.escapeHtml( label.carrier_label ) + ' — ' + YP.escapeHtml( label.tracking_number ) + '</span>' +
							'<span class="yp-shippo-label-row__actions">' +
								// A button, not a plain <a target="_blank"> — printLabelUrl()
								// below needs a real click handler to open the tab itself
								// (see that function's own docblock for why).
								'<button type="button" class="wp-block-button__link is-style-outline" data-yp-shippo-print="' + YP.escapeAttr( label.label_url ) + '">Print</button>' +
								( label.voided
									? '<span class="yp-pill yp-pill--crit">Voided</span>'
									: '<button type="button" class="wp-block-button__link yp-button--danger" data-yp-shippo-void="' + YP.escapeAttr( label.tracking_number ) + '" data-yp-shippo-void-carrier="' + YP.escapeAttr( label.carrier_label ) + '">Void</button>' ) +
							'</span>' +
						'</li>'
					);
				} ).join( '' ) +
			'</ul>'
		);
	}

	/**
	 * Direct request: "when I hit print on a shipping label... anyway to
	 * make it automatically print without a new window? Or at least when
	 * the new window opens it automatically has the print dialog open?"
	 * A label PDF is hosted on Shippo's own domain, so a plain
	 * `window.open( url )` tab can't be scripted afterward — calling
	 * `.print()` on a cross-origin window is blocked by every browser,
	 * for the same reason no website can silently print anything at all
	 * without at least the OS/browser's own print dialog appearing (that
	 * restriction can't be worked around from here; it's not this app
	 * withholding it).
	 *
	 * The reliable middle ground: open a blank tab ourselves (same-origin,
	 * since we just created it), write our own HTML into it with the
	 * label loaded in an <iframe>, and let the browser's built-in PDF
	 * viewer load it. The <iframe> *element* stays same-origin to our
	 * script even though its content doesn't — only reaching into the
	 * PDF's own contents would be blocked — so once it fires its `load`
	 * event, `iframe.contentWindow.print()` still works and brings up the
	 * print dialog automatically, no manual click needed once the tab
	 * opens.
	 *
	 * No 'noopener' feature on that first window.open(): with it, the
	 * browser opens the tab but window.open() returns null by spec, so
	 * this code took the "popup blocked" branch below and opened the
	 * label a second time — a blank tab plus a label popup on every
	 * purchase. Clearing .opener by hand right after gives the same
	 * protection while keeping the handle we need to write into the tab.
	 */
	function printLabelUrl( url ) {
		var printWindow = window.open( '', '_blank' );
		if ( ! printWindow ) {
			// Popup blocked — fall back to the old direct-open behavior
			// rather than silently doing nothing.
			window.open( url, '_blank', 'noopener' );
			return;
		}
		printWindow.opener = null;

		printWindow.document.write(
			'<!doctype html><html><head><title>Print Shipping Label</title>' +
			'<style>html,body{margin:0;height:100%;}iframe{border:0;width:100%;height:100%;}</style>' +
			'</head><body><iframe src="' + YP.escapeAttr( url ) + '"></iframe></body></html>'
		);
		printWindow.document.close();

		var iframe = printWindow.document.querySelector( 'iframe' );
		iframe.addEventListener( 'load', function () {
			try {
				printWindow.focus();
				iframe.contentWindow.print();
			} catch ( error ) {
				// Some browser/PDF-viewer combinations still refuse a scripted
				// print — the tab is open and showing the label either way, so
				// printing it manually (Ctrl/Cmd+P) works exactly as before.
			}
		} );
	}

	/**
	 * Delegated/re-bound the same way bindShippoVoidButtons() documents —
	 * the labels list is rebuilt after every purchase or void, so this is
	 * called again at each of those points rather than bound once.
	 */
	function bindShippoPrintButtons( panel ) {
		panel.querySelectorAll( '[data-yp-shippo-print]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				printLabelUrl( button.getAttribute( 'data-yp-shippo-print' ) );
			} );
		} );
	}

	function shippoPanelHtml( order ) {
		if ( ! order.shippo_configured ) {
			return (
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Shippo</h2></div>' +
					'<p class="yp-panel__hint">An independent shipping-label option — add an API token under Settings &rarr; Shipping to turn this on for every order.</p>' +
				'</div>'
			);
		}

		var pkg = order.shippo_default_package;
		var customs = order.shippo_customs || {};

		return (
			'<div class="yp-panel" data-yp-shippo-panel>' +
				'<div class="yp-panel__head"><h2>Shippo</h2></div>' +
				'<div data-yp-shippo-labels>' + shippoLabelsListHtml( order.shippo_labels || [] ) + '</div>' +
				'<p class="yp-panel__hint">Comparing rates below is free. Purchasing a label is a real charge against your Shippo balance/carrier accounts.</p>' +
				'<div class="yp-shippo-dims">' +
					'<div class="yp-field"><label for="yp-shippo-weight">Weight (oz)</label><input type="number" min="0.1" step="0.1" id="yp-shippo-weight" value="' + YP.escapeAttr( pkg.weight_oz ) + '" /></div>' +
					'<div class="yp-field"><label for="yp-shippo-length">Length (in)</label><input type="number" min="0.1" step="0.1" id="yp-shippo-length" value="' + YP.escapeAttr( pkg.length_in ) + '" /></div>' +
					'<div class="yp-field"><label for="yp-shippo-width">Width (in)</label><input type="number" min="0.1" step="0.1" id="yp-shippo-width" value="' + YP.escapeAttr( pkg.width_in ) + '" /></div>' +
					'<div class="yp-field"><label for="yp-shippo-height">Height (in)</label><input type="number" min="0.1" step="0.1" id="yp-shippo-height" value="' + YP.escapeAttr( pkg.height_in ) + '" /></div>' +
				'</div>' +
				( customs.international
					? '<p class="yp-panel__hint">International shipment: this customs info goes on the label.</p>' +
						'<div class="yp-shippo-dims">' +
							'<div class="yp-field"><label for="yp-shippo-customs-description">Contents</label><input type="text" id="yp-shippo-customs-description" value="' + YP.escapeAttr( customs.description ) + '" /></div>' +
							'<div class="yp-field"><label for="yp-shippo-customs-value">Value (' + YP.escapeHtml( customs.currency ) + ')</label><input type="number" min="0.01" step="0.01" id="yp-shippo-customs-value" value="' + YP.escapeAttr( customs.value ) + '" /></div>' +
						'</div>'
					: '' ) +
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

		bindShippoVoidButtons( order, panel );
		bindShippoPrintButtons( panel );
	}

	/**
	 * Delegated onto the labels list itself (rather than bound once at
	 * panel-open time) because that list's innerHTML is rebuilt after
	 * every purchase or void — a plain addEventListener on each button
	 * would only ever cover whatever buttons existed at the moment this
	 * ran, missing every row rendered after it. Re-called after each
	 * re-render instead of using true event delegation on a stable
	 * ancestor, matching this file's existing style elsewhere (e.g.
	 * renderRateList()'s own per-render rebinding).
	 */
	function bindShippoVoidButtons( order, panel ) {
		panel.querySelectorAll( '[data-yp-shippo-void]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var trackingNumber = button.getAttribute( 'data-yp-shippo-void' );
				var carrierLabel = button.getAttribute( 'data-yp-shippo-void-carrier' );
				YP.confirmModal( {
					title: 'Void this label?',
					message: 'Void the ' + carrierLabel + ' label for tracking number ' + trackingNumber + '? This only stops this store from treating it as an active shipment — it does not affect any other label on this order, and does not guarantee the carrier actually cancels it.',
					confirmLabel: 'Void Label',
					danger: true,
					onConfirm: function () {
						voidShippoLabel( order, panel, trackingNumber, button );
					}
				} );
			} );
		} );
	}

	function voidShippoLabel( order, panel, trackingNumber, button ) {
		button.disabled = true;
		button.textContent = 'Voiding…';

		YP.request( yeffoprintAdminApp.restUrl + 'admin/order/' + order.id + '/shippo/void', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { tracking_number: trackingNumber } )
		} )
			.then( function ( response ) {
				order.shippo_labels = response.labels || [];
				var labelsListEl = panel.querySelector( '[data-yp-shippo-labels]' );
				if ( labelsListEl ) {
					labelsListEl.innerHTML = shippoLabelsListHtml( order.shippo_labels );
					bindShippoVoidButtons( order, panel );
					bindShippoPrintButtons( panel );
				}
			} )
			.catch( function ( error ) {
				button.disabled = false;
				button.textContent = 'Void';
				window.alert( 'Couldn’t void this label: ' + error.message );
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

		var customsDescription = panel.querySelector( '#yp-shippo-customs-description' );
		if ( customsDescription ) {
			parcel.customs_description = customsDescription.value;
			parcel.customs_value = panel.querySelector( '#yp-shippo-customs-value' ).value;
		}

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
	 * Strips everything but letters/digits down to lowercase space-
	 * separated words — direct bug report: this was matching "UPS Ground
	 * Saver" instead of the customer's actual "UPS 2nd Day Air", which a
	 * plain substring check (the previous approach) should have gotten
	 * right if both strings were identical. The real culprit is that
	 * WooCommerce's own stored shipping method title and Shippo's
	 * servicelevel name are two different systems' labels for the same
	 * service — one might carry a "®"/"™", different spacing/punctuation,
	 * or wrap the carrier name differently ("UPS® 2nd Day Air®" vs. "UPS
	 * 2nd Day Air") — any of which defeats an exact substring match,
	 * silently falling back to "cheapest" (Ground Saver, almost always
	 * the least expensive UPS ground service) with no visible sign the
	 * match had failed. Normalizing both sides to bare words before
	 * comparing survives exactly that kind of cosmetic mismatch.
	 */
	function normalizeForRateMatch( text ) {
		return ( text || '' ).toLowerCase().replace( /[^a-z0-9]+/g, ' ' ).trim();
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
	 * as equally good signals.
	 *
	 * Service-name matching is word-overlap, not a single substring
	 * check — every word of the Shippo service name ("2nd"/"day"/"air")
	 * is looked up individually in the customer's chosen method text, and
	 * the score scales with how many of them are found, so "UPS® 2nd Day
	 * Air®" still fully matches Shippo's "UPS 2nd Day Air" (punctuation
	 * stripped, same words) and a close-but-not-identical label (an extra
	 * qualifier word, say) still gets partial credit instead of an
	 * all-or-nothing miss that falls all the way back to the cheapest
	 * rate. Returns null (falls back to cheapest, the existing default)
	 * only when nothing matches at all — e.g. a generic WooCommerce
	 * method like "Flat rate" or "Local pickup" was chosen, which no
	 * Shippo rate could ever legitimately match.
	 */
	function findBestMatchingRateId( rates, shippingMethod ) {
		if ( ! shippingMethod ) {
			return null;
		}
		var haystack = normalizeForRateMatch( shippingMethod );
		var haystackWords = haystack ? haystack.split( ' ' ) : [];
		var bestId = null;
		var bestScore = 0;

		rates.forEach( function ( rate ) {
			var carrier = normalizeForRateMatch( rate.carrier_label );
			var service = normalizeForRateMatch( rate.service );
			var score = 0;
			if ( carrier && haystack.indexOf( carrier ) !== -1 ) {
				score += 1;
			}
			if ( service ) {
				var serviceWords = service.split( ' ' );
				var matchedWords = serviceWords.filter( function ( word ) { return haystackWords.indexOf( word ) !== -1; } );
				score += 2 * ( matchedWords.length / serviceWords.length );
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
			if ( ! rate ) {
				return;
			}
			// Direct request: "the pop up window using the browser method
			// is ugly. Can we use modal windows that match the sites
			// style when confirming I want to purchase a label" —
			// replaces the old window.confirm() with the shared styled
			// dialog (YP.confirmModal(), app.js).
			YP.confirmModal( {
				title: 'Purchase this label?',
				message: 'Purchase this ' + rate.carrier_label + ' ' + rate.service + ' label for $' + rate.amount.toFixed( 2 ) + '? This charges your Shippo balance/carrier account immediately.',
				confirmLabel: 'Purchase Label',
				onConfirm: function () {
					purchaseShippoLabel( order, panel, rate );
				}
			} );
		} );
	}

	function purchaseShippoLabel( order, panel, rate ) {
		var purchaseButton = panel.querySelector( '[data-yp-shippo-purchase]' );
		var errorEl = panel.querySelector( '[data-yp-shippo-error]' );
		var resultEl = panel.querySelector( '[data-yp-shippo-result]' );

		purchaseButton.disabled = true;
		purchaseButton.textContent = 'Purchasing…';
		errorEl.innerHTML = '';

		// Direct report: "the shipped packages dashboard doesn't show the
		// carrier for the labels we're making with shippo, it just shows
		// a -" — Shippo's own label-purchase response doesn't reliably
		// echo back which carrier a rate belonged to, so the carrier this
		// panel already displayed for the chosen rate (rate.carrier_id/
		// carrier_label — the exact same rate the confirm() above just
		// named) is sent along with the purchase instead of trying to
		// re-derive it server-side afterward. See
		// YeffoPrint_Shippo_Client::purchase_label()'s own docblock.
		YP.request( yeffoprintAdminApp.restUrl + 'admin/order/' + order.id + '/shippo/purchase', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { rate_id: rate.id, carrier_id: rate.carrier_id, carrier_label: rate.carrier_label } )
		} )
			.then( function ( response ) {
				resultEl.innerHTML =
					'<p class="yp-panel__hint"><strong>Label purchased.</strong> Tracking: ' + YP.escapeHtml( response.label.tracking_number ) + ' (' + YP.escapeHtml( response.label.carrier_label ) + ')</p>' +
					( response.label.label_url ? '<button type="button" class="wp-block-button__link is-style-outline" style="margin-top:0.5rem;" data-yp-shippo-print="' + YP.escapeAttr( response.label.label_url ) + '">Print Label</button>' : '' );
				panel.querySelector( '[data-yp-shippo-rates]' ).innerHTML = '';
				order.status = response.status;

				// Direct request: "can it automatically open the label in a new tab to
				// print?", later refined to "make it automatically print without a new
				// window? Or at least when the new window opens it automatically has
				// the print dialog open?" — printLabelUrl() opens the tab itself *and*
				// triggers the print dialog once the label finishes loading, rather than
				// just opening the raw PDF url. Called immediately instead of waiting for
				// a click, same as before — browsers only allow window.open() unprompted
				// from a real click handler, which this still is (the "Purchase Selected
				// Label" click that kicked off this request).
				if ( response.label.label_url ) {
					printLabelUrl( response.label.label_url );
				}

				bindShippoPrintButtons( panel ); // The "Print Label" button just inserted above.

				// Direct request: "need the ability to go back and print the label
				// later." Folds the new label into the reprint list (shippoLabelsListHtml())
				// right away, so it's there without waiting on a fresh drawer open/re-fetch.
				order.shippo_labels = ( order.shippo_labels || [] ).concat( [ {
					carrier_label:    response.label.carrier_label,
					tracking_number:  response.label.tracking_number,
					label_url:        response.label.label_url,
					transaction_id:   response.label.transaction_id,
					voided:           false
				} ] );
				var labelsListEl = panel.querySelector( '[data-yp-shippo-labels]' );
				if ( labelsListEl ) {
					labelsListEl.innerHTML = shippoLabelsListHtml( order.shippo_labels );
					bindShippoVoidButtons( order, panel );
					bindShippoPrintButtons( panel );
				}
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
