/**
 * Order History — direct request: "I need a way of pulling up previous
 * orders in my YeffoDesgn dashboard so that I can view previous order
 * details and potentially create a new shipping label if there was an
 * issue with the other... This area should be searchable and should
 * also just list out previous orders that I can go through. Would need
 * to be paginated as I expect a lot of orders."
 *
 * Every WooCommerce order regardless of status — unlike the Dashboard's
 * Pending Orders panel, which only ever shows Processing/In Production
 * — backed by the new `/admin/orders` list endpoint
 * (class-admin-order-controller.php's list_orders()). Clicking a row
 * opens the exact same order-detail drawer the Dashboard and Custom
 * Orders screens already use (app.js's YP.openWcOrderDrawer()) — the
 * drawer's own Shippo panel already covers "create a new shipping
 * label if there was an issue with the other" and "see the previous
 * shipping information," so nothing new was needed there beyond
 * reaching this order in the first place.
 *
 * Search and status filtering both happen server-side (list_orders()
 * itself paginates and filters), not client-side over an already-loaded
 * page — direct request explicitly anticipated a large data set, so
 * this never loads more than one page of orders at a time.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var PER_PAGE = 20;

	// Same status → pill-color mapping as app.js's own (internal, not
	// exposed) WC_ORDER_STATUS_PILLS — kept as a small local copy rather
	// than plumbing a new export through YP for one shared object, same
	// "not worth a new shared surface for this" call as other small
	// per-view constants elsewhere in this app.
	var STATUS_PILLS = {
		completed:       'good',
		shipped:         'good',
		processing:      'neutral',
		'in-design':     'neutral',
		'in-production': 'neutral',
		'on-hold':       'warn',
		pending:         'warn',
		'checkout-draft': 'warn',
		cancelled:       'crit',
		refunded:        'crit',
		failed:          'crit'
	};

	// Quick tabs over the same status filter (direct request: "the
	// ability to see draft orders"). Drafts are hidden from "All
	// statuses" by WooCommerce itself, so they need a way in that's
	// easier to spot than one entry in a long dropdown. Awaiting payment
	// sits beside it for manual orders sent out as pay links.
	var QUICK_TABS = [
		{ status: '',               label: 'All orders' },
		{ status: 'pending',        label: 'Awaiting payment' },
		{ status: 'checkout-draft', label: 'Drafts' },
		{ status: 'trash',          label: 'Trash' }
	];

	// Mirrors YeffoPrint_Draft_Order_Retention::RETENTION_DAYS.
	var DRAFT_RETENTION_DAYS = 30;

	function endpoint( query ) {
		return yeffoprintAdminApp.restUrl + 'admin/orders' + ( query ? '?' + query : '' );
	}

	YP.views[ 'order-history' ] = function ( viewEl ) {
		var page          = 1;
		var searchTimer   = null;
		var requestToken  = 0; // Guards against an earlier, slower request's response landing after a later one's (fast typing, or switching pages quickly).

		viewEl.innerHTML =
			'<p class="yp-app__intro">Every order that has ever come through the store — search by customer, email, phone, or order number, or browse the full history.</p>' +
			'<div class="yp-settings-tabs" role="tablist">' +
				QUICK_TABS.map( function ( tab ) {
					return '<button type="button" class="yp-settings-tabs__tab' + ( '' === tab.status ? ' is-active' : '' ) + '" data-yp-quick-tab="' + YP.escapeAttr( tab.status ) + '" role="tab" aria-selected="' + ( '' === tab.status ? 'true' : 'false' ) + '">' + YP.escapeHtml( tab.label ) + '<span data-yp-quick-count></span></button>';
				} ).join( '' ) +
			'</div>' +
			'<p class="yp-field__hint" data-yp-draft-hint hidden>Drafts are checkouts that were started but never paid for. A custom design request sits here from the moment the customer submits it until they check out. A checkout where the customer pressed Place order but never finished paying (card declined, payment window closed) is kept for ' + DRAFT_RETENTION_DAYS + ' days, then removed automatically.</p>' +
			'<div data-yp-unpaid-requests hidden></div>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search by customer, email, phone, or order #&hellip;" />' +
				'<select data-yp-status-filter>' +
					'<option value="">All statuses</option>' +
					Object.keys( yeffoprintAdminApp.wcOrderStatuses || {} ).map( function ( key ) {
						return '<option value="' + YP.escapeAttr( key ) + '">' + YP.escapeHtml( yeffoprintAdminApp.wcOrderStatuses[ key ] ) + '</option>';
					} ).join( '' ) +
					'<option value="trash">Trash</option>' +
				'</select>' +
			'</div>' +
			'<div class="yp-bulk-bar" data-yp-bulk hidden></div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th class="yp-bulk-check"><input type="checkbox" data-yp-check-all aria-label="Select all orders on this page"></th>' +
				'<th>Order</th><th>Customer</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="7">Loading&hellip;</td></tr></tbody></table></div>' +
			'<div class="yp-pagination" data-yp-pagination></div>';

		var rowsEl       = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl     = viewEl.querySelector( '[data-yp-search]' );
		var statusEl     = viewEl.querySelector( '[data-yp-status-filter]' );
		var paginationEl = viewEl.querySelector( '[data-yp-pagination]' );
		var draftHintEl  = viewEl.querySelector( '[data-yp-draft-hint]' );
		var unpaidEl     = viewEl.querySelector( '[data-yp-unpaid-requests]' );
		var tabEls       = viewEl.querySelectorAll( '[data-yp-quick-tab]' );
		var bulkEl       = viewEl.querySelector( '[data-yp-bulk]' );
		var checkAllEl   = viewEl.querySelector( '[data-yp-check-all]' );
		var selected     = {}; // order id => true, for the bulk bar. Cleared on every reload.

		function selectedIds() {
			return Object.keys( selected ).map( Number );
		}

		function drawBulkBar() {
			var ids = selectedIds();
			var boxes = rowsEl.querySelectorAll( '[data-yp-check]' );
			checkAllEl.checked = boxes.length > 0 && ids.length === boxes.length;
			checkAllEl.indeterminate = ids.length > 0 && ids.length < boxes.length;
			bulkEl.hidden = ! ids.length;
			if ( ! ids.length ) {
				bulkEl.innerHTML = '';
				return;
			}
			var inTrash = 'trash' === statusEl.value;
			bulkEl.innerHTML =
				'<b>' + ids.length + ' selected</b>' +
				( inTrash
					? '<button type="button" class="wp-block-button__link is-style-outline" data-yp-bulk-act="restore">Restore</button>' +
						'<button type="button" class="wp-block-button__link yp-button--danger" data-yp-bulk-act="delete">Delete forever</button>'
					: '<span class="yp-bulk-bar__group"><select data-yp-bulk-status aria-label="New status"><option value="">Change status to&hellip;</option>' +
							Object.keys( yeffoprintAdminApp.wcOrderStatuses || {} ).filter( function ( key ) { return 'checkout-draft' !== key; } ).map( function ( key ) {
								return '<option value="' + YP.escapeAttr( key ) + '">' + YP.escapeHtml( yeffoprintAdminApp.wcOrderStatuses[ key ] ) + '</option>';
							} ).join( '' ) +
						'</select><button type="button" class="wp-block-button__link is-style-outline" data-yp-bulk-act="status">Apply</button></span>' +
						'<button type="button" class="wp-block-button__link is-style-outline" data-yp-bulk-print="slip">Print packing slips</button>' +
						'<button type="button" class="wp-block-button__link is-style-outline" data-yp-bulk-print="invoice">Print invoices</button>' +
						'<button type="button" class="wp-block-button__link is-style-outline" data-yp-bulk-act="trash">Move to trash</button>' ) +
				'<button type="button" class="yp-link-button" data-yp-bulk-clear>Clear</button>' +
				'<span data-yp-bulk-status-msg></span>';
		}

		function runBulk( action, button ) {
			var ids = selectedIds();
			var body = { ids: ids, action: action };
			if ( 'status' === action ) {
				body.status = bulkEl.querySelector( '[data-yp-bulk-status]' ).value;
				if ( ! body.status ) {
					bulkEl.querySelector( '[data-yp-bulk-status]' ).focus();
					return;
				}
			}
			var go = function () {
				bulkEl.querySelectorAll( 'button, select' ).forEach( function ( el ) { el.disabled = true; } );
				button.textContent = 'Working…';
				YP.request( yeffoprintAdminApp.restUrl + 'admin/orders/bulk', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( body )
				} ).then( function ( result ) {
					if ( result.failed && result.failed.length ) {
						window.alert( result.done + ' done. ' + result.failed.length + ' couldn’t be changed: #' + result.failed.join( ', #' ) );
					}
					load();
				} ).catch( function ( error ) {
					drawBulkBar();
					window.alert( 'Couldn’t do that: ' + error.message );
				} );
			};
			var n = ids.length + ( 1 === ids.length ? ' order' : ' orders' );
			if ( 'delete' === action ) {
				YP.confirmModal( { title: 'Delete ' + n + ' forever?', message: 'They’re removed for good, with their notes and history. This can’t be undone.', confirmLabel: 'Delete forever', danger: true, onConfirm: go } );
			} else if ( 'trash' === action ) {
				YP.confirmModal( { title: 'Move ' + n + ' to the trash?', message: 'They leave your order lists. You can restore them from the Trash tab.', confirmLabel: 'Move to trash', danger: true, onConfirm: go } );
			} else if ( 'status' === action ) {
				var label = ( yeffoprintAdminApp.wcOrderStatuses || {} )[ body.status ] || body.status;
				YP.confirmModal( { title: 'Change ' + n + ' to ' + label + '?', message: 'Customers get whatever email WooCommerce sends for that status, same as changing them one at a time.', confirmLabel: 'Change status', onConfirm: go } );
			} else {
				go();
			}
		}

		bulkEl.addEventListener( 'click', function ( event ) {
			var act = event.target.closest( '[data-yp-bulk-act]' );
			var print = event.target.closest( '[data-yp-bulk-print]' );
			if ( act ) {
				runBulk( act.getAttribute( 'data-yp-bulk-act' ), act );
			} else if ( print ) {
				window.open( YP.printOrdersUrl( selectedIds(), print.getAttribute( 'data-yp-bulk-print' ) ), '_blank' );
			} else if ( event.target.closest( '[data-yp-bulk-clear]' ) ) {
				selected = {};
				rowsEl.querySelectorAll( '[data-yp-check]' ).forEach( function ( box ) { box.checked = false; } );
				drawBulkBar();
			}
		} );

		checkAllEl.addEventListener( 'change', function () {
			selected = {};
			rowsEl.querySelectorAll( '[data-yp-check]' ).forEach( function ( box ) {
				box.checked = checkAllEl.checked;
				if ( box.checked ) {
					selected[ box.getAttribute( 'data-yp-check' ) ] = true;
				}
			} );
			drawBulkBar();
		} );

		function syncTabs() {
			tabEls.forEach( function ( tabEl ) {
				var isActive = tabEl.getAttribute( 'data-yp-quick-tab' ) === statusEl.value;
				tabEl.classList.toggle( 'is-active', isActive );
				tabEl.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			} );
			draftHintEl.hidden = 'checkout-draft' !== statusEl.value;
		}

		// Submitted custom design requests with no WooCommerce order yet
		// (see list_orders()'s unpaid_custom_requests()). They aren't
		// orders, so they get their own table rather than mixing into
		// the paginated one; a row opens the request in Custom Orders.
		function renderUnpaidRequests( requests ) {
			if ( ! requests || ! requests.length ) {
				unpaidEl.hidden = true;
				unpaidEl.innerHTML = '';
				return;
			}

			unpaidEl.hidden = false;
			unpaidEl.innerHTML =
				'<h3 class="yp-split__subhead">Custom design requests not checked out (' + requests.length + ')</h3>' +
				'<p class="yp-field__hint">Open one to delete it if the customer changed their mind. To drop an unfinished checkout below, open it and press Cancel order.</p>' +
				'<div class="yp-record-card" style="margin-bottom:var(--wp--preset--spacing--sm);"><table class="yp-record-table"><thead><tr>' +
					'<th>Request</th><th>Customer</th><th>Submitted</th><th>Labels</th><th>Status</th>' +
				'</tr></thead><tbody>' +
				requests.map( function ( request ) {
					return (
						'<tr class="yp-row-clickable" data-yp-open-request="' + request.id + '">' +
							'<td>' + YP.escapeHtml( request.title ) + '<div class="yp-field__hint" style="margin:0;">' + YP.escapeHtml( request.order_type_label ) + '</div></td>' +
							'<td>' +
								'<div>' + YP.escapeHtml( request.customer_name || 'Not known yet' ) + '</div>' +
								'<div class="yp-field__hint" style="margin:0;">' + YP.escapeHtml( request.customer_email || '' ) + '</div>' +
							'</td>' +
							'<td>' + ( request.date ? YP.escapeHtml( new Date( request.date ).toLocaleDateString() ) : '—' ) + '</td>' +
							'<td>' + request.quantity + ( request.label_rows > 1 ? ' <span class="yp-field__hint">(' + request.label_rows + ' designs)</span>' : '' ) + '</td>' +
							'<td><span class="yp-pill yp-pill--warn">Awaiting payment</span></td>' +
						'</tr>'
					);
				} ).join( '' ) +
				'</tbody></table></div>' +
				'<h3 class="yp-split__subhead">Unfinished checkouts</h3>';

			unpaidEl.querySelectorAll( '[data-yp-open-request]' ).forEach( function ( row ) {
				row.addEventListener( 'click', function () {
					window.location.hash = '#/orders/' + row.getAttribute( 'data-yp-open-request' );
				} );
			} );
		}

		function renderCounts( counts ) {
			tabEls.forEach( function ( tabEl ) {
				var status  = tabEl.getAttribute( 'data-yp-quick-tab' );
				var countEl = tabEl.querySelector( '[data-yp-quick-count]' );
				countEl.textContent = status && counts && counts[ status ] ? ' (' + counts[ status ] + ')' : '';
			} );
		}

		function load() {
			var token = ++requestToken;
			selected = {};
			drawBulkBar();
			rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="7">Loading&hellip;</td></tr>';
			paginationEl.innerHTML = '';

			var query = 'page=' + page + '&per_page=' + PER_PAGE;
			if ( searchEl.value.trim() ) {
				query += '&search=' + encodeURIComponent( searchEl.value.trim() );
			}
			if ( statusEl.value ) {
				query += '&status=' + encodeURIComponent( statusEl.value );
			}

			YP.request( endpoint( query ) )
				.then( function ( response ) {
					if ( token !== requestToken ) {
						return; // A newer request already landed — this one's now stale.
					}
					renderCounts( response.counts );
					renderUnpaidRequests( response.unpaid_requests );
					renderRows( response.orders || [] );
					renderPagination( response.total || 0, response.max_num_pages || 0 );
				} )
				.catch( function ( error ) {
					if ( token !== requestToken ) {
						return;
					}
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="7">Couldn’t load orders: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows( orders ) {
			if ( ! orders.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="7">No orders match.</td></tr>';
				return;
			}

			rowsEl.innerHTML = orders.map( function ( order ) {
				var pillClass = STATUS_PILLS[ order.status ] || 'neutral';
				return (
					'<tr class="yp-row-clickable" data-yp-open-order="' + order.id + '">' +
						'<td class="yp-bulk-check"><input type="checkbox" data-yp-check="' + order.id + '" aria-label="Select order ' + YP.escapeAttr( order.number ) + '"></td>' +
						'<td>#' + YP.escapeHtml( order.number ) + '</td>' +
						'<td>' +
							'<div>' + YP.escapeHtml( order.customer_name || '—' ) + '</div>' +
							'<div class="yp-field__hint" style="margin:0;">' + YP.escapeHtml( order.customer_email || '' ) + '</div>' +
						'</td>' +
						'<td>' + ( order.date ? YP.escapeHtml( new Date( order.date ).toLocaleDateString() ) : '—' ) + '</td>' +
						'<td>' + order.item_count + '</td>' +
						'<td>$' + order.total.toFixed( 2 ) + '</td>' +
						'<td><span class="yp-pill yp-pill--' + pillClass + '">' + YP.escapeHtml( order.status_label ) + '</span>' + ( order.express ? ' <span class="yp-pill yp-pill--crit">Express</span>' : '' ) + '</td>' +
					'</tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-check]' ).forEach( function ( box ) {
				box.addEventListener( 'change', function () {
					if ( box.checked ) {
						selected[ box.getAttribute( 'data-yp-check' ) ] = true;
					} else {
						delete selected[ box.getAttribute( 'data-yp-check' ) ];
					}
					drawBulkBar();
				} );
			} );

			rowsEl.querySelectorAll( '[data-yp-open-order]' ).forEach( function ( row ) {
				row.addEventListener( 'click', function ( event ) {
					if ( event.target.closest( '.yp-bulk-check' ) ) {
						return; // Ticking a box selects; it doesn't open the order.
					}
					YP.openOrder( parseInt( row.getAttribute( 'data-yp-open-order' ), 10 ) );
				} );
			} );
		}

		function renderPagination( total, maxPages ) {
			if ( maxPages <= 1 ) {
				return;
			}
			paginationEl.innerHTML =
				'<button type="button" class="wp-block-button__link is-style-outline" data-yp-prev-page' + ( 1 === page ? ' disabled' : '' ) + '>&larr; Previous</button>' +
				'<span class="yp-pagination__status">Page ' + page + ' of ' + maxPages + ' &middot; ' + total + ' order' + ( 1 === total ? '' : 's' ) + '</span>' +
				'<button type="button" class="wp-block-button__link is-style-outline" data-yp-next-page' + ( page >= maxPages ? ' disabled' : '' ) + '>Next &rarr;</button>';

			var prevButton = paginationEl.querySelector( '[data-yp-prev-page]' );
			var nextButton = paginationEl.querySelector( '[data-yp-next-page]' );
			if ( prevButton ) {
				prevButton.addEventListener( 'click', function () { page -= 1; load(); } );
			}
			if ( nextButton ) {
				nextButton.addEventListener( 'click', function () { page += 1; load(); } );
			}
		}

		searchEl.addEventListener( 'input', function () {
			window.clearTimeout( searchTimer );
			searchTimer = window.setTimeout( function () {
				page = 1;
				load();
			}, 350 );
		} );

		statusEl.addEventListener( 'change', function () {
			page = 1;
			syncTabs();
			load();
		} );

		tabEls.forEach( function ( tabEl ) {
			tabEl.addEventListener( 'click', function () {
				statusEl.value = tabEl.getAttribute( 'data-yp-quick-tab' );
				page = 1;
				syncTabs();
				load();
			} );
		} );

		load();
	};
} )();
