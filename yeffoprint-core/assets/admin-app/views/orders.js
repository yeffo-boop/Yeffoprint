/**
 * Custom Orders — the Fully Custom Design + Custom Stickers production
 * pipeline (docs/ARCHITECTURE.md, Phase 6). `yp_custom_order` has no
 * REST-registered meta at all (the classic `class-custom-order-editor.php`
 * reads/writes everything with plain `get_post_meta()`), so this
 * screen is backed entirely by the new `/admin/custom-orders`/
 * `/admin/custom-order/{id}` endpoints rather than piggybacking on core
 * REST — same shape as Pricing Rules, just with a list this time.
 *
 * Read-only past Status, mirroring the classic editor exactly: every
 * other field here is what the customer submitted or what payment
 * completion filled in, neither of which staff edit from either UI.
 *
 * Supports being opened at a specific order via the router's `subId`
 * (`#/orders/123`) — the Dashboard home view links a pending/awaiting-
 * approval row straight here instead of only ever landing on the list.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	// Mirrors YeffoPrint_Custom_Order_Meta::STATUSES — stable, in-pipeline-order
	// values (PROJECT_SPEC §13); the detail endpoint also sends this map back
	// on every response so the Status <select> never needs to hardcode a second
	// copy, but the list view needs it before any detail has loaded, same
	// reasoning as views/maintenance.js's own STATUS_LABELS.
	var STATUS_PILLS = {
		design_in_progress: 'yp-pill--warn',
		proof_ready: 'yp-pill--warn',
		awaiting_approval: 'yp-pill--warn',
		approved: 'yp-pill--good',
		printing: 'yp-pill--good',
		shipped: 'yp-pill--good'
	};

	function endpoint( path ) {
		return yeffoprintAdminApp.restUrl + 'admin/' + path;
	}

	YP.views.orders = function ( viewEl, subId ) {
		var allOrders = [];

		viewEl.innerHTML =
			'<p class="yp-app__intro">Fully Custom Design and Custom Sticker requests moving through the design → proof → production pipeline.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search orders&hellip;" />' +
				'<select data-yp-status-filter>' +
					'<option value="">All statuses</option>' +
					Object.keys( yeffoprintAdminApp.customOrderStatuses || {} ).map( function ( key ) {
						return '<option value="' + YP.escapeAttr( key ) + '">' + YP.escapeHtml( yeffoprintAdminApp.customOrderStatuses[ key ] ) + '</option>';
					} ).join( '' ) +
				'</select>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Order</th><th>Type</th><th>Customer</th><th>Status</th><th>Date</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="6">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl        = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl       = viewEl.querySelector( '[data-yp-search]' );
		var statusFilterEl = viewEl.querySelector( '[data-yp-status-filter]' );

		function load( openId ) {
			rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">Loading&hellip;</td></tr>';
			var query = statusFilterEl.value ? '?status=' + encodeURIComponent( statusFilterEl.value ) : '';
			YP.request( endpoint( 'custom-orders' + query ) )
				.then( function ( orders ) {
					allOrders = orders || [];
					renderRows( allOrders );
					if ( openId ) {
						openDetail( parseInt( openId, 10 ) );
					}
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">Couldn’t load orders: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows( orders ) {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? orders.filter( function ( o ) { return ( o.title + ' ' + o.customer_name + ' ' + o.customer_email ).toLowerCase().indexOf( query ) !== -1; } )
				: orders;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">' + ( orders.length ? 'No orders match your filters.' : 'No custom orders yet.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( order ) {
				return (
					'<tr data-id="' + order.id + '">' +
						'<td><div class="yp-record-name">' + YP.escapeHtml( order.title ) + '</div></td>' +
						'<td><span class="yp-chip">' + YP.escapeHtml( order.order_type_label ) + '</span></td>' +
						'<td>' + YP.escapeHtml( order.customer_name || order.customer_email || '—' ) + '</td>' +
						'<td>' +
							( order.paid
								? '<span class="yp-pill ' + ( STATUS_PILLS[ order.status ] || 'yp-pill--neutral' ) + '">' + YP.escapeHtml( order.status_label ) + '</span>'
								: '<span class="yp-pill yp-pill--neutral">Unpaid</span>' ) +
							( order.has_change_request ? ' <span class="yp-pill yp-pill--crit">Changes requested</span>' : '' ) +
						'</td>' +
						'<td>' + ( order.date ? new Date( order.date ).toLocaleDateString() : '—' ) + '</td>' +
						'<td class="yp-row-actions"><button type="button" class="yp-row-action" data-yp-view="' + order.id + '">View</button></td>' +
					'</tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-view]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { openDetail( parseInt( button.getAttribute( 'data-yp-view' ), 10 ) ); } );
			} );
		}

		/* ---------- Detail drawer ---------- */

		function openDetail( id ) {
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

			loadDetail( id, drawer );
		}

		function loadDetail( id, drawer ) {
			var bodyEl = drawer.querySelector( '[data-yp-body]' );
			YP.request( endpoint( 'custom-order/' + id ) )
				.then( function ( order ) { renderDetail( order, drawer, bodyEl ); } )
				.catch( function ( error ) {
					bodyEl.innerHTML = '<p class="yp-form__error">Couldn’t load this order: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		function row( label, valueHtml ) {
			return '<tr><th>' + YP.escapeHtml( label ) + '</th><td>' + valueHtml + '</td></tr>';
		}

		function uploadListHtml( uploads ) {
			if ( ! uploads.length ) {
				return '—';
			}
			return '<ul>' + uploads.map( function ( u ) {
				return '<li><a href="' + YP.escapeAttr( u.url ) + '" target="_blank" rel="noopener noreferrer">' + YP.escapeHtml( u.name ) + '</a></li>';
			} ).join( '' ) + '</ul>';
		}

		function renderDetail( order, drawer, bodyEl ) {
			var rowsHtml = row( 'Type', YP.escapeHtml( order.order_type_label ) );

			rowsHtml += row(
				'Customer',
				YP.escapeHtml( order.customer_name || '' ) + ( order.customer_email ? ' — <a href="mailto:' + YP.escapeAttr( order.customer_email ) + '">' + YP.escapeHtml( order.customer_email ) + '</a>' : '' )
			);

			if ( 'sticker' === order.order_type ) {
				var s = order.sticker;
				rowsHtml += row( 'Sticker Type', YP.escapeHtml( s.sticker_type_label || '—' ) );
				rowsHtml += row( 'Shape', YP.escapeHtml( s.shape_label || '—' ) );
				rowsHtml += row( 'Size', s.is_custom_size ? ( 'Custom: ' + YP.escapeHtml( s.custom_width_in ) + '&Prime; &times; ' + YP.escapeHtml( s.custom_height_in ) + '&Prime;' ) : YP.escapeHtml( s.size_label || '—' ) );
				rowsHtml += row( 'Material', YP.escapeHtml( s.material_label || '—' ) );
				rowsHtml += row( 'Quantity', String( s.quantity ) );
				rowsHtml += row( 'Instructions', s.instructions ? YP.escapeHtml( s.instructions ).replace( /\n/g, '<br>' ) : '—' );
				rowsHtml += row( 'Artwork Files', uploadListHtml( s.artwork_uploads ) );
			} else {
				var l = order.label;
				rowsHtml += row( 'Brand Name', YP.escapeHtml( l.brand_name || '—' ) );
				rowsHtml += row(
					'Batch',
					'<table class="yp-record-table"><thead><tr><th>Size</th><th>Material</th><th>Qty</th><th>Compound / Strength</th></tr></thead><tbody>' +
						l.batch.map( function ( b ) {
							return '<tr><td>' + YP.escapeHtml( b.size_label || '—' ) + '</td><td>' + YP.escapeHtml( b.material_label || '—' ) + '</td><td>' + b.quantity + '</td><td>' + YP.escapeHtml( b.compound_strength || '—' ) + '</td></tr>';
						} ).join( '' ) +
					'</tbody></table>'
				);
				rowsHtml += row( 'Style / Colors', l.style_notes ? YP.escapeHtml( l.style_notes ).replace( /\n/g, '<br>' ) : '—' );
				rowsHtml += row( 'Instructions', l.instructions ? YP.escapeHtml( l.instructions ).replace( /\n/g, '<br>' ) : '—' );
				rowsHtml += row( l.uploads_label, uploadListHtml( l.uploads ) );
			}

			rowsHtml += row(
				'sticker' === order.order_type ? 'Amount Paid' : 'Design Fee',
				order.fee_skipped
					? '$0.00 — fee skipped'
					: ( order.design_fee ? '$' + order.design_fee.toFixed( 2 ) + ' — paid' : 'Awaiting payment' )
			);

			if ( order.wc_order_edit_url ) {
				rowsHtml += row( 'Order', '<a href="' + YP.escapeAttr( order.wc_order_edit_url ) + '" target="_blank" rel="noopener noreferrer">#' + order.wc_order_id + '</a>' );
			}

			var noticesHtml = '';
			if ( order.change_request_notes && 'design_in_progress' === order.status ) {
				noticesHtml += '<div class="yp-form__error" style="background: rgba(181, 121, 10, 0.1); color: #8a5c08;"><strong>Customer requested changes:</strong><br>' + YP.escapeHtml( order.change_request_notes ).replace( /\n/g, '<br>' ) + '</div>';
			}
			if ( order.customer_provided_design ) {
				noticesHtml += '<div class="yp-form__error" style="background: rgba(181, 121, 10, 0.1); color: #8a5c08;"><strong>Customer provided their own print-ready design</strong> — no design work needed.</div>';
			}

			bodyEl.innerHTML =
				noticesHtml +
				'<div class="yp-record-card"><table class="yp-record-table"><tbody>' + rowsHtml + '</tbody></table></div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Status</h2></div>' +
					( order.paid
						? '<div class="yp-form__row"><div class="yp-field"><select data-yp-status>' +
							Object.keys( order.statuses ).map( function ( key ) {
								return '<option value="' + YP.escapeAttr( key ) + '"' + ( order.status === key ? ' selected' : '' ) + '>' + YP.escapeHtml( order.statuses[ key ] ) + '</option>';
							} ).join( '' ) +
						'</select></div><div><button type="button" class="wp-block-button__link is-style-accent" data-yp-save-status>Save Status</button></div></div>'
						: '<p class="yp-field__hint">Awaiting the design fee payment — status is set automatically once paid.</p>' ) +
					'<div data-yp-status-error></div>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Proofs</h2>' + ( order.paid ? '<button type="button" class="wp-block-button__link is-style-outline" data-yp-add-proof">+ Add Proof</button>' : '' ) + '</div>' +
					'<div data-yp-proofs-list>' + proofsListHtml( order.proofs ) + '</div>' +
					( order.approval_url
						? '<p class="yp-field__hint"><strong>Customer approval link:</strong> <input type="text" readonly onclick="this.select();" value="' + YP.escapeAttr( order.approval_url ) + '" style="width:100%;margin-top:0.35rem;padding:0.4rem 0.6rem;font-family:var(--wp--preset--font-family--mono);font-size:0.78rem;border:1.5px solid var(--wp--preset--color--light-gray);border-radius:var(--wp--custom--radius--control);" /></p>'
						: '' ) +
				'</div>';

			if ( order.paid ) {
				bodyEl.querySelector( '[data-yp-save-status]' ).addEventListener( 'click', function () { saveStatus( order, drawer, bodyEl ); } );
				bodyEl.querySelector( '[data-yp-add-proof]' ).addEventListener( 'click', function () { addProof( order, drawer, bodyEl ); } );
			}
		}

		function proofsListHtml( proofs ) {
			if ( ! proofs.length ) {
				return '<p class="yp-field__hint">No proofs uploaded yet.</p>';
			}
			return '<ul>' + proofs.map( function ( p ) {
				return '<li>' + ( p.file_url ? '<a href="' + YP.escapeAttr( p.file_url ) + '" target="_blank" rel="noopener noreferrer">' + YP.escapeHtml( p.title ) + '</a>' : YP.escapeHtml( p.title ) ) + ' — ' + new Date( p.date ).toLocaleDateString() + '</li>';
			} ).join( '' ) + '</ul>';
		}

		function saveStatus( order, drawer, bodyEl ) {
			var select = bodyEl.querySelector( '[data-yp-status]' );
			var button = bodyEl.querySelector( '[data-yp-save-status]' );
			var errorEl = bodyEl.querySelector( '[data-yp-status-error]' );

			button.disabled = true;
			button.textContent = 'Saving…';
			errorEl.innerHTML = '';

			YP.request( endpoint( 'custom-order/' + order.id ), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { status: select.value } ) } )
				.then( function ( updated ) {
					renderDetail( updated, drawer, bodyEl );
					load();
				} )
				.catch( function ( error ) {
					button.disabled = false;
					button.textContent = 'Save Status';
					errorEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		function addProof( order, drawer, bodyEl ) {
			if ( typeof wp === 'undefined' || ! wp.media ) {
				return;
			}

			var frame = wp.media( { title: 'Select proof file', multiple: false, button: { text: 'Use this file' } } );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var listEl = bodyEl.querySelector( '[data-yp-proofs-list]' );
				listEl.innerHTML = '<p class="yp-field__hint">Uploading proof&hellip;</p>';

				YP.request( endpoint( 'proof' ), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { custom_order_id: order.id, file_id: attachment.id } ) } )
					.then( function () { return YP.request( endpoint( 'custom-order/' + order.id ) ); } )
					.then( function ( updated ) {
						renderDetail( updated, drawer, bodyEl );
						load();
					} )
					.catch( function ( error ) {
						listEl.innerHTML = '<p class="yp-form__error">Couldn’t add proof: ' + YP.escapeHtml( error.message ) + '</p>';
					} );
			} );

			frame.open();
		}

		searchEl.addEventListener( 'input', function () { renderRows( allOrders ); } );
		statusFilterEl.addEventListener( 'change', function () { load(); } );

		load( subId );
	};
} )();
