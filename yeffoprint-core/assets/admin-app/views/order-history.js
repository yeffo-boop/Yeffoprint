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
		'in-production': 'neutral',
		'on-hold':       'warn',
		pending:         'warn',
		cancelled:       'crit',
		refunded:        'crit',
		failed:          'crit'
	};

	function endpoint( query ) {
		return yeffoprintAdminApp.restUrl + 'admin/orders' + ( query ? '?' + query : '' );
	}

	YP.views[ 'order-history' ] = function ( viewEl ) {
		var page          = 1;
		var searchTimer   = null;
		var requestToken  = 0; // Guards against an earlier, slower request's response landing after a later one's (fast typing, or switching pages quickly).

		viewEl.innerHTML =
			'<p class="yp-app__intro">Every order that has ever come through the store — search by customer, email, phone, or order number, or browse the full history.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search by customer, email, phone, or order #&hellip;" />' +
				'<select data-yp-status-filter>' +
					'<option value="">All statuses</option>' +
					Object.keys( yeffoprintAdminApp.wcOrderStatuses || {} ).map( function ( key ) {
						return '<option value="' + YP.escapeAttr( key ) + '">' + YP.escapeHtml( yeffoprintAdminApp.wcOrderStatuses[ key ] ) + '</option>';
					} ).join( '' ) +
				'</select>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Order</th><th>Customer</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="6">Loading&hellip;</td></tr></tbody></table></div>' +
			'<div class="yp-pagination" data-yp-pagination></div>';

		var rowsEl       = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl     = viewEl.querySelector( '[data-yp-search]' );
		var statusEl     = viewEl.querySelector( '[data-yp-status-filter]' );
		var paginationEl = viewEl.querySelector( '[data-yp-pagination]' );

		function load() {
			var token = ++requestToken;
			rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">Loading&hellip;</td></tr>';
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
					renderRows( response.orders || [] );
					renderPagination( response.total || 0, response.max_num_pages || 0 );
				} )
				.catch( function ( error ) {
					if ( token !== requestToken ) {
						return;
					}
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">Couldn’t load orders: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows( orders ) {
			if ( ! orders.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">No orders match.</td></tr>';
				return;
			}

			rowsEl.innerHTML = orders.map( function ( order ) {
				var pillClass = STATUS_PILLS[ order.status ] || 'neutral';
				return (
					'<tr class="yp-row-clickable" data-yp-open-order="' + order.id + '">' +
						'<td>#' + YP.escapeHtml( order.number ) + '</td>' +
						'<td>' +
							'<div>' + YP.escapeHtml( order.customer_name || '—' ) + '</div>' +
							'<div class="yp-field__hint" style="margin:0;">' + YP.escapeHtml( order.customer_email || '' ) + '</div>' +
						'</td>' +
						'<td>' + ( order.date ? YP.escapeHtml( new Date( order.date ).toLocaleDateString() ) : '—' ) + '</td>' +
						'<td>' + order.item_count + '</td>' +
						'<td>$' + order.total.toFixed( 2 ) + '</td>' +
						'<td><span class="yp-pill yp-pill--' + pillClass + '">' + YP.escapeHtml( order.status_label ) + '</span></td>' +
					'</tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-open-order]' ).forEach( function ( row ) {
				row.addEventListener( 'click', function () {
					YP.openWcOrderDrawer( parseInt( row.getAttribute( 'data-yp-open-order' ), 10 ) );
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
			load();
		} );

		load();
	};
} )();
