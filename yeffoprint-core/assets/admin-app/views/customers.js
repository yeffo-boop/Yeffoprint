/**
 * Customers — direct request: "customer list/CRM... I want to be able
 * to add notes to customers so when I print their future orders I can
 * refer to them." Lists every non-administrator WordPress user
 * (`/admin/customers`, class-admin-customer-controller.php), searchable
 * and paginated server-side, same pattern as views/order-history.js.
 *
 * Clicking a row opens a drawer with that customer's stats, their notes
 * (add/delete right there), and their 10 most recent orders — each of
 * which opens the same familiar order drawer (YP.openWcOrderDrawer())
 * every other screen already uses. Adding/removing a note here and
 * adding one from an order drawer (app.js's own Customer Notes panel)
 * both write to the same email-keyed note history, so either place
 * always shows the same list.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var PER_PAGE = 20;

	function endpoint( path, query ) {
		return yeffoprintAdminApp.restUrl + 'admin/' + path + ( query ? '?' + query : '' );
	}

	function formatMoney( amount ) {
		return '$' + ( parseFloat( amount ) || 0 ).toFixed( 2 );
	}

	// `customer.registered` (WP_User::user_registered) is a raw MySQL
	// "YYYY-MM-DD HH:MM:SS" string, not real ISO 8601 like every other
	// date this app's REST endpoints send (those already go through
	// WC_DateTime::date('c')) — the space-for-T swap is what makes
	// `new Date()` parse it reliably across browsers instead of
	// silently returning "Invalid Date" in stricter ones.
	function formatDate( value ) {
		return value ? new Date( value.replace( ' ', 'T' ) ).toLocaleDateString() : '—';
	}

	YP.views.customers = function ( viewEl ) {
		var page        = 1;
		var searchTimer = null;
		var requestToken = 0;

		viewEl.innerHTML =
			'<p class="yp-app__intro">Every registered customer account — order history, lifetime spend, and staff notes you can refer back to on their next order.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search by name, email, or username&hellip;" />' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Customer</th><th>Orders</th><th>Total Spent</th><th>Last Order</th><th>Notes</th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="5">Loading&hellip;</td></tr></tbody></table></div>' +
			'<div class="yp-pagination" data-yp-pagination></div>';

		var rowsEl       = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl     = viewEl.querySelector( '[data-yp-search]' );
		var paginationEl = viewEl.querySelector( '[data-yp-pagination]' );

		function load() {
			var token = ++requestToken;
			rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">Loading&hellip;</td></tr>';
			paginationEl.innerHTML = '';

			var query = 'page=' + page + '&per_page=' + PER_PAGE;
			if ( searchEl.value.trim() ) {
				query += '&search=' + encodeURIComponent( searchEl.value.trim() );
			}

			YP.request( endpoint( 'customers', query ) )
				.then( function ( response ) {
					if ( token !== requestToken ) {
						return;
					}
					renderRows( response.customers || [] );
					renderPagination( response.total || 0, response.max_num_pages || 0 );
				} )
				.catch( function ( error ) {
					if ( token !== requestToken ) {
						return;
					}
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">Couldn’t load customers: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows( customers ) {
			if ( ! customers.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">No customers match.</td></tr>';
				return;
			}

			rowsEl.innerHTML = customers.map( function ( customer ) {
				return (
					'<tr class="yp-row-clickable" data-yp-open-customer="' + customer.id + '">' +
						'<td>' +
							'<div>' + YP.escapeHtml( customer.name || '—' ) + '</div>' +
							'<div class="yp-field__hint" style="margin:0;">' + YP.escapeHtml( customer.email || '' ) + '</div>' +
						'</td>' +
						'<td>' + customer.order_count + '</td>' +
						'<td>' + formatMoney( customer.total_spent ) + '</td>' +
						'<td>' + formatDate( customer.last_order_date ) + '</td>' +
						'<td>' + ( customer.note_count ? '<span class="yp-pill yp-pill--neutral">' + customer.note_count + '</span>' : '—' ) + '</td>' +
					'</tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-open-customer]' ).forEach( function ( row ) {
				row.addEventListener( 'click', function () {
					openCustomerDrawer( parseInt( row.getAttribute( 'data-yp-open-customer' ), 10 ) );
				} );
			} );
		}

		function renderPagination( total, maxPages ) {
			if ( maxPages <= 1 ) {
				return;
			}
			paginationEl.innerHTML =
				'<button type="button" class="wp-block-button__link is-style-outline" data-yp-prev-page' + ( 1 === page ? ' disabled' : '' ) + '>&larr; Previous</button>' +
				'<span class="yp-pagination__status">Page ' + page + ' of ' + maxPages + ' &middot; ' + total + ' customer' + ( 1 === total ? '' : 's' ) + '</span>' +
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

		function notesListHtml( notes ) {
			if ( ! notes.length ) {
				return '<p class="yp-field__hint">No notes yet.</p>';
			}
			return '<div class="yp-customer-notes-list">' + notes.map( function ( note ) {
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
			} ).join( '' ) + '</div>';
		}

		function openCustomerDrawer( id ) {
			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--wide yp-drawer--center';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="Customer detail">' +
					'<div class="yp-drawer__header"><span class="yp-drawer__title-group" data-yp-drawer-title>Customer detail</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body" data-yp-body><p class="yp-field__hint">Loading&hellip;</p></div>' +
				'</div>';

			document.body.appendChild( drawer );
			YP.initDrawer( drawer );
			YP.openDrawer( drawer );

			loadCustomerDetail( id, drawer );
		}

		function loadCustomerDetail( id, drawer ) {
			var bodyEl = drawer.querySelector( '[data-yp-body]' );

			YP.request( endpoint( 'customer/' + id ) )
				.then( function ( customer ) {
					renderCustomerDetail( customer, drawer, bodyEl );
				} )
				.catch( function ( error ) {
					bodyEl.innerHTML = '<p class="yp-form__error">Couldn’t load this customer: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		function renderCustomerDetail( customer, drawer, bodyEl ) {
			var titleEl = drawer.querySelector( '[data-yp-drawer-title]' );
			if ( titleEl ) {
				titleEl.textContent = customer.name || customer.email;
			}

			var ordersBody = customer.recent_orders.length
				? '<div class="yp-list-rows">' + customer.recent_orders.map( function ( order ) {
					return (
						'<div class="yp-list-row">' +
							'<div class="yp-list-row__text">' +
								'<button type="button" class="yp-row-action" style="padding:0;font-weight:700;" data-yp-open-order="' + order.id + '">#' + YP.escapeHtml( order.number ) + '</button>' +
								'<span class="s">' + formatDate( order.date ) + ' &middot; ' + formatMoney( order.total ) + '</span>' +
							'</div>' +
							'<div class="yp-list-row__meta"><span class="yp-pill yp-pill--neutral">' + YP.escapeHtml( order.status_label ) + '</span></div>' +
						'</div>'
					);
				} ).join( '' ) + '</div>'
				: '<p class="yp-field__hint">No orders yet.</p>';

			bodyEl.innerHTML =
				'<div class="yp-split__field"><span class="k">Email</span><span class="v"><a href="mailto:' + YP.escapeAttr( customer.email ) + '">' + YP.escapeHtml( customer.email ) + '</a></span></div>' +
				'<div class="yp-split__field"><span class="k">Customer since</span><span class="v">' + formatDate( customer.registered ) + '</span></div>' +
				'<div class="yp-split__field"><span class="k">Orders / Lifetime spend</span><span class="v">' + customer.order_count + ' / ' + formatMoney( customer.total_spent ) + '</span></div>' +
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Recent Orders</h2></div>' +
					ordersBody +
				'</div>' +
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Notes</h2></div>' +
					'<p class="yp-panel__hint">Visible here and on every one of this customer’s orders — a handy place to jot anything worth remembering next time they order.</p>' +
					'<div data-yp-notes-list>' + notesListHtml( customer.notes ) + '</div>' +
					'<textarea class="yp-customer-note-input" data-yp-note-input placeholder="Add a note&hellip;" rows="2"></textarea>' +
					'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add-note>Add Note</button>' +
					'<div data-yp-note-error></div>' +
				'</div>';

			bodyEl.querySelectorAll( '[data-yp-open-order]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					YP.openWcOrderDrawer( parseInt( button.getAttribute( 'data-yp-open-order' ), 10 ) );
				} );
			} );

			bindNoteActions( customer, bodyEl );
		}

		function bindNoteActions( customer, bodyEl ) {
			var addButton = bodyEl.querySelector( '[data-yp-add-note]' );
			var input     = bodyEl.querySelector( '[data-yp-note-input]' );
			var errorEl   = bodyEl.querySelector( '[data-yp-note-error]' );
			var listEl    = bodyEl.querySelector( '[data-yp-notes-list]' );

			addButton.addEventListener( 'click', function () {
				var note = input.value.trim();
				if ( ! note ) {
					return;
				}
				addButton.disabled = true;
				errorEl.innerHTML = '';

				YP.request( endpoint( 'customer-notes' ), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { email: customer.email, note: note } )
				} )
					.then( function ( notes ) {
						addButton.disabled = false;
						input.value = '';
						customer.notes = notes;
						listEl.innerHTML = notesListHtml( notes );
						bindDeleteButtons( listEl, bodyEl );
					} )
					.catch( function ( error ) {
						addButton.disabled = false;
						errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
					} );
			} );

			bindDeleteButtons( listEl, bodyEl );
		}

		function bindDeleteButtons( listEl, bodyEl ) {
			listEl.querySelectorAll( '[data-yp-delete-note]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var noteId = button.getAttribute( 'data-yp-delete-note' );
					YP.confirmModal( {
						title: 'Delete this note?',
						message: 'This can’t be undone.',
						confirmLabel: 'Delete Note',
						danger: true,
						onConfirm: function () {
							YP.request( endpoint( 'customer-notes/' + noteId ), { method: 'DELETE' } )
								.then( function () {
									button.closest( '.yp-customer-note' ).remove();
									if ( ! listEl.querySelector( '.yp-customer-note' ) ) {
										listEl.innerHTML = '<p class="yp-field__hint">No notes yet.</p>';
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

		searchEl.addEventListener( 'input', function () {
			window.clearTimeout( searchTimer );
			searchTimer = window.setTimeout( function () {
				page = 1;
				load();
			}, 350 );
		} );

		load();
	};
} )();
