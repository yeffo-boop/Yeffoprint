/**
 * Custom Orders — the Fully Custom Design + Custom Stickers + Template
 * Label production pipeline (docs/ARCHITECTURE.md, Phase 6). `yp_custom_order`
 * has no REST-registered meta at all (the classic `class-custom-order-editor.php`
 * reads/writes everything with plain `get_post_meta()`), so this
 * screen is backed entirely by the new `/admin/custom-orders`/
 * `/admin/custom-order/{id}` endpoints rather than piggybacking on core
 * REST — same shape as Pricing Rules, just with a list this time.
 *
 * Read-only past Status, mirroring the classic editor exactly: every
 * other field here is what the customer submitted or what payment
 * completion filled in, neither of which staff edit from either UI.
 *
 * Split view, not a drawer — direct request: "the design of the admin
 * panel is still very clunky... come up with mockups of a new admin
 * design that's much easier and faster to use," Concept B ("Workspace")
 * chosen. Selecting a row updates the detail pane in place instead of
 * opening a `.yp-drawer` overlay on top of the page — no REST or data
 * shape changed at all, this is purely how the same data gets shown.
 * The list column and detail pane are two halves of the same `.yp-split`
 * container (records.css); on a narrow screen they stack, with the
 * selected row's detail replacing the list until "← Back."
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

	// A small, fixed rotation — purely a visual anchor so a row is easier to
	// spot at a glance in the list column, not meaningful data. Picked by
	// order id so the same order always gets the same color across reloads.
	var AVATAR_COLORS = [ '#C2007A', '#0078A4', '#8A5C08', '#1C7A34', '#5B4B8A' ];

	function avatarColor( id ) {
		return AVATAR_COLORS[ id % AVATAR_COLORS.length ];
	}

	function initial( text ) {
		return ( text || '?' ).trim().charAt( 0 ).toUpperCase() || '?';
	}

	function endpoint( path ) {
		return yeffoprintAdminApp.restUrl + 'admin/' + path;
	}

	YP.views.orders = function ( viewEl, subId ) {
		var allOrders  = [];
		var selectedId = subId ? parseInt( subId, 10 ) : 0;
		var detailOrder = null; // last successfully loaded detail payload, for re-render (e.g. after Save Status) without a full reload.

		viewEl.innerHTML =
			'<p class="yp-app__intro">Fully Custom Design, Custom Sticker, and Template Label requests moving through the design → proof → production pipeline.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search orders&hellip;" />' +
				'<select data-yp-status-filter>' +
					'<option value="">All statuses</option>' +
					Object.keys( yeffoprintAdminApp.customOrderStatuses || {} ).map( function ( key ) {
						return '<option value="' + YP.escapeAttr( key ) + '">' + YP.escapeHtml( yeffoprintAdminApp.customOrderStatuses[ key ] ) + '</option>';
					} ).join( '' ) +
				'</select>' +
			'</div>' +
			'<div class="yp-split" data-yp-split>' +
				'<div class="yp-split__list">' +
					'<div class="yp-split__rows" data-yp-rows><p class="yp-field__hint" style="padding:1rem;">Loading&hellip;</p></div>' +
				'</div>' +
				'<div class="yp-split__detail" data-yp-detail>' +
					'<div class="yp-split__empty"><p class="yp-field__hint">Select an order on the left to see its details.</p></div>' +
				'</div>' +
			'</div>';

		var splitEl        = viewEl.querySelector( '[data-yp-split]' );
		var rowsEl         = viewEl.querySelector( '[data-yp-rows]' );
		var detailEl       = viewEl.querySelector( '[data-yp-detail]' );
		var searchEl       = viewEl.querySelector( '[data-yp-search]' );
		var statusFilterEl = viewEl.querySelector( '[data-yp-status-filter]' );

		function load() {
			rowsEl.innerHTML = '<p class="yp-field__hint" style="padding:1rem;">Loading&hellip;</p>';
			var query = statusFilterEl.value ? '?status=' + encodeURIComponent( statusFilterEl.value ) : '';
			YP.request( endpoint( 'custom-orders' + query ) )
				.then( function ( orders ) {
					allOrders = orders || [];
					renderRows( allOrders );
					if ( selectedId ) {
						loadDetail( selectedId );
					}
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<p class="yp-form__error">Couldn’t load orders: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		function renderRows( orders ) {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? orders.filter( function ( o ) { return ( o.title + ' ' + o.customer_name + ' ' + o.customer_email ).toLowerCase().indexOf( query ) !== -1; } )
				: orders;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<p class="yp-field__hint" style="padding:1rem;">' + ( orders.length ? 'No orders match your filters.' : 'No custom orders yet.' ) + '</p>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( order ) {
				return (
					'<button type="button" class="yp-split__row' + ( order.id === selectedId ? ' is-selected' : '' ) + '" data-id="' + order.id + '">' +
						'<span class="yp-split__row-avatar" style="background:' + avatarColor( order.id ) + ';">' + YP.escapeHtml( initial( order.title ) ) + '</span>' +
						'<span class="yp-split__row-text">' +
							'<span class="t">' + YP.escapeHtml( order.title ) + '</span>' +
							'<span class="s">' + YP.escapeHtml( order.customer_name || order.customer_email || '—' ) + ' · ' + YP.escapeHtml( order.order_type_label ) + '</span>' +
						'</span>' +
						'<span class="yp-split__row-status">' +
							( order.paid
								? '<span class="yp-pill ' + ( STATUS_PILLS[ order.status ] || 'yp-pill--neutral' ) + '">' + YP.escapeHtml( order.status_label ) + '</span>'
								: '<span class="yp-pill yp-pill--neutral">Unpaid</span>' ) +
							( order.has_change_request ? '<span class="yp-pill yp-pill--crit">Changes requested</span>' : '' ) +
						'</span>' +
					'</button>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-id]' ).forEach( function ( row ) {
				row.addEventListener( 'click', function () { selectRow( parseInt( row.getAttribute( 'data-id' ), 10 ) ); } );
			} );
		}

		/* ---------- Selection + detail pane ---------- */

		function selectRow( id ) {
			selectedId = id;
			splitEl.classList.add( 'has-selection' );
			rowsEl.querySelectorAll( '[data-id]' ).forEach( function ( row ) {
				row.classList.toggle( 'is-selected', parseInt( row.getAttribute( 'data-id' ), 10 ) === id );
			} );
			loadDetail( id );
		}

		function loadDetail( id ) {
			detailEl.innerHTML = '<div class="yp-split__empty"><p class="yp-field__hint">Loading&hellip;</p></div>';
			YP.request( endpoint( 'custom-order/' + id ) )
				.then( function ( order ) {
					detailOrder = order;
					renderDetail( order );
				} )
				.catch( function ( error ) {
					detailEl.innerHTML = '<div class="yp-split__empty"><p class="yp-form__error">Couldn’t load this order: ' + YP.escapeHtml( error.message ) + '</p></div>';
				} );
		}

		function field( label, valueHtml ) {
			return '<div class="yp-split__field"><span class="k">' + YP.escapeHtml( label ) + '</span><span class="v">' + valueHtml + '</span></div>';
		}

		function uploadListHtml( uploads ) {
			if ( ! uploads.length ) {
				return '—';
			}
			return '<ul>' + uploads.map( function ( u ) {
				return '<li><a href="' + YP.escapeAttr( u.url ) + '" target="_blank" rel="noopener noreferrer">' + YP.escapeHtml( u.name ) + '</a></li>';
			} ).join( '' ) + '</ul>';
		}

		function renderDetail( order ) {
			var fieldsHtml = '';

			if ( 'sticker' === order.order_type ) {
				var s = order.sticker;
				fieldsHtml += field( 'Sticker Type', YP.escapeHtml( s.sticker_type_label || '—' ) );
				fieldsHtml += field( 'Shape', YP.escapeHtml( s.shape_label || '—' ) );
				fieldsHtml += field( 'Size', s.is_custom_size ? ( 'Custom: ' + YP.escapeHtml( s.custom_width_in ) + '&Prime; &times; ' + YP.escapeHtml( s.custom_height_in ) + '&Prime;' ) : YP.escapeHtml( s.size_label || '—' ) );
				fieldsHtml += field( 'Material', YP.escapeHtml( s.material_label || '—' ) );
				fieldsHtml += field( 'Quantity', String( s.quantity ) );
				fieldsHtml += field( 'Instructions', s.instructions ? YP.escapeHtml( s.instructions ).replace( /\n/g, '<br>' ) : '—' );
				fieldsHtml += field( 'Artwork Files', uploadListHtml( s.artwork_uploads ) );
			} else if ( 'template' === order.order_type ) {
				var t = order.template;
				fieldsHtml += field( 'Design', YP.escapeHtml( t.template_title || '—' ) );
				fieldsHtml += field( 'Size', YP.escapeHtml( t.size_label || '—' ) );
				fieldsHtml += field( 'Material', YP.escapeHtml( t.material_label || '—' ) );
				fieldsHtml += field( 'Instructions', t.instructions ? YP.escapeHtml( t.instructions ).replace( /\n/g, '<br>' ) : '—' );
			} else {
				var l = order.label;
				fieldsHtml += field( 'Brand Name', YP.escapeHtml( l.brand_name || '—' ) );
				fieldsHtml += field( 'Style / Colors', l.style_notes ? YP.escapeHtml( l.style_notes ).replace( /\n/g, '<br>' ) : '—' );
				fieldsHtml += field( 'Instructions', l.instructions ? YP.escapeHtml( l.instructions ).replace( /\n/g, '<br>' ) : '—' );
				fieldsHtml += field( l.uploads_label, uploadListHtml( l.uploads ) );
			}

			var batchHtml = '';
			if ( 'template' === order.order_type ) {
				batchHtml =
					'<table class="yp-record-table"><thead><tr><th>Qty</th><th>Customization</th></tr></thead><tbody>' +
						order.template.variants.map( function ( v ) {
							return '<tr><td>' + v.quantity + '</td><td>' + ( v.summary ? YP.escapeHtml( v.summary ) : '—' ) + '</td></tr>';
						} ).join( '' ) +
					'</tbody></table>';
			} else if ( 'label' === order.order_type ) {
				batchHtml =
					'<table class="yp-record-table"><thead><tr><th>Size</th><th>Material</th><th>Qty</th><th>Compound / Strength</th></tr></thead><tbody>' +
						order.label.batch.map( function ( b ) {
							return '<tr><td>' + YP.escapeHtml( b.size_label || '—' ) + '</td><td>' + YP.escapeHtml( b.material_label || '—' ) + '</td><td>' + b.quantity + '</td><td>' + YP.escapeHtml( b.compound_strength || '—' ) + '</td></tr>';
						} ).join( '' ) +
					'</tbody></table>';
			}

			var noticesHtml = '';
			if ( order.change_request_notes && 'design_in_progress' === order.status ) {
				noticesHtml += '<div class="yp-form__error" style="background: rgba(181, 121, 10, 0.1); color: #8a5c08;"><strong>Customer requested changes:</strong><br>' + YP.escapeHtml( order.change_request_notes ).replace( /\n/g, '<br>' ) + '</div>';
			}
			if ( order.customer_provided_design ) {
				noticesHtml += '<div class="yp-form__error" style="background: rgba(181, 121, 10, 0.1); color: #8a5c08;"><strong>Customer provided their own print-ready design</strong> — no design work needed.</div>';
			}

			detailEl.innerHTML =
				'<div class="yp-split__detail-scroll">' +
					'<button type="button" class="yp-split__back" data-yp-back>&larr; Back to list</button>' +
					'<div class="yp-split__head">' +
						'<span class="yp-split__row-avatar" style="background:' + avatarColor( order.id ) + ';width:40px;height:40px;font-size:1rem;">' + YP.escapeHtml( initial( order.title ) ) + '</span>' +
						'<div>' +
							'<h2>' + YP.escapeHtml( order.title ) + '</h2>' +
							'<p class="yp-field__hint" style="margin:0.15rem 0 0;">' +
								YP.escapeHtml( order.order_type_label ) + ' · ' + YP.escapeHtml( order.customer_name || '' ) +
								( order.customer_email ? ' — <a href="mailto:' + YP.escapeAttr( order.customer_email ) + '">' + YP.escapeHtml( order.customer_email ) + '</a>' : '' ) +
							'</p>' +
						'</div>' +
					'</div>' +
					noticesHtml +
					'<div class="yp-split__fields">' + fieldsHtml + '</div>' +
					( batchHtml ? '<h3 class="yp-split__subhead">Batch</h3>' + batchHtml : '' ) +
					'<div class="yp-split__fields" style="margin-top:0.75rem;">' +
						field(
							'label' === order.order_type ? 'Design Fee' : 'Amount Paid',
							order.fee_skipped
								? '$0.00 — fee skipped'
								: ( order.design_fee ? '$' + order.design_fee.toFixed( 2 ) + ' — paid' : 'Awaiting payment' )
						) +
						// Direct request: this used to link straight out to the
					// classic WooCommerce edit screen in a new tab — "I don't
					// want to use that screen at all... bring me to our own
					// YeffoDesign version." Opens the same order-detail drawer
					// the Dashboard's own Pending Orders/Shipped Packages rows
					// already use (YP.openWcOrderDrawer(), app.js), so the
					// order's items/customer/shipping/Shippo panel/status all
					// stay inside this app — bound just below, once this HTML
					// is actually in the document.
					( order.wc_order_id ? field( 'Order', '<button type="button" class="yp-row-action" style="padding:0;font-weight:600;" data-yp-open-wc-order="' + order.wc_order_id + '">#' + order.wc_order_id + '</button>' ) : '' ) +
					'</div>' +

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
						'<div class="yp-panel__head"><h2>Proofs</h2>' + ( order.paid ? '<button type="button" class="wp-block-button__link is-style-outline" data-yp-add-proof>+ Add Proof</button>' : '' ) + '</div>' +
						'<div data-yp-proofs-list>' + proofsListHtml( order.proofs ) + '</div>' +
						( order.approval_url
							? '<p class="yp-field__hint"><strong>Customer approval link:</strong> <input type="text" readonly onclick="this.select();" value="' + YP.escapeAttr( order.approval_url ) + '" style="width:100%;margin-top:0.35rem;padding:0.4rem 0.6rem;font-family:var(--wp--preset--font-family--mono);font-size:0.78rem;border:1.5px solid var(--wp--preset--color--light-gray);border-radius:var(--wp--custom--radius--control);" /></p>'
							: '' ) +
						( 'awaiting_approval' === order.status ? reminderStatusHtml( order ) : '' ) +
					'</div>' +
				'</div>';

			detailEl.querySelector( '[data-yp-back]' ).addEventListener( 'click', function () {
				splitEl.classList.remove( 'has-selection' );
			} );

			var openWcOrderButton = detailEl.querySelector( '[data-yp-open-wc-order]' );
			if ( openWcOrderButton ) {
				openWcOrderButton.addEventListener( 'click', function () {
					YP.openWcOrderDrawer( parseInt( openWcOrderButton.getAttribute( 'data-yp-open-wc-order' ), 10 ) );
				} );
			}

			if ( order.paid ) {
				detailEl.querySelector( '[data-yp-save-status]' ).addEventListener( 'click', function () { saveStatus( order ); } );
				detailEl.querySelector( '[data-yp-add-proof]' ).addEventListener( 'click', function () { addProof( order ); } );
			}
		}

		/** Staff-visible state for the automated 24h/48h proof reminder (class-proof-reminder-scheduler.php) — only shown while status is actually awaiting_approval, since the stage number is meaningless/stale once it's moved on. */
		function reminderStatusHtml( order ) {
			var label = 2 === order.proof_reminder_stage
				? 'Reminder sent (24h + 48h)'
				: 1 === order.proof_reminder_stage
					? 'Reminder sent (24h)'
					: 'No reminder sent yet';

			return '<p class="yp-field__hint">' + YP.escapeHtml( label ) +
				( order.awaiting_approval_since ? ' — waiting since ' + new Date( order.awaiting_approval_since ).toLocaleString() : '' ) +
				'</p>';
		}

		function proofsListHtml( proofs ) {
			if ( ! proofs.length ) {
				return '<p class="yp-field__hint">No proofs uploaded yet.</p>';
			}
			return '<ul>' + proofs.map( function ( p ) {
				return '<li>' + ( p.file_url ? '<a href="' + YP.escapeAttr( p.file_url ) + '" target="_blank" rel="noopener noreferrer">' + YP.escapeHtml( p.title ) + '</a>' : YP.escapeHtml( p.title ) ) + ' — ' + new Date( p.date ).toLocaleDateString() + '</li>';
			} ).join( '' ) + '</ul>';
		}

		function saveStatus( order ) {
			var select = detailEl.querySelector( '[data-yp-status]' );
			var button = detailEl.querySelector( '[data-yp-save-status]' );
			var errorEl = detailEl.querySelector( '[data-yp-status-error]' );

			button.disabled = true;
			button.textContent = 'Saving…';
			errorEl.innerHTML = '';

			YP.request( endpoint( 'custom-order/' + order.id ), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { status: select.value } ) } )
				.then( function ( updated ) {
					renderDetail( updated );
					load();
				} )
				.catch( function ( error ) {
					button.disabled = false;
					button.textContent = 'Save Status';
					errorEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		function addProof( order ) {
			if ( typeof wp === 'undefined' || ! wp.media ) {
				return;
			}

			var frame = wp.media( { title: 'Select proof file', multiple: false, button: { text: 'Use this file' } } );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var listEl = detailEl.querySelector( '[data-yp-proofs-list]' );
				listEl.innerHTML = '<p class="yp-field__hint">Uploading proof&hellip;</p>';

				YP.request( endpoint( 'proofs' ), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { custom_order_id: order.id, file_id: attachment.id } ) } )
					.then( function () { return YP.request( endpoint( 'custom-order/' + order.id ) ); } )
					.then( function ( updated ) {
						renderDetail( updated );
						load();
					} )
					.catch( function ( error ) {
						listEl.innerHTML = '<p class="yp-form__error">Couldn’t add proof: ' + YP.escapeHtml( error.message ) + '</p>';
					} );
			} );

			frame.open();
		}

		/**
		 * Keyboard: ↑/↓ move between rows, Esc back to list on mobile. The
		 * router tears a view down by just overwriting viewEl's innerHTML
		 * on the next hashchange — nothing ever calls back in here to
		 * unregister this listener, so it checks, on every fire, whether
		 * this view's own root node is still attached to the document and
		 * unregisters itself the first time it isn't (rather than a
		 * document-level keydown listener silently piling up one per past
		 * visit to this screen over a long admin session).
		 */
		function handleKeydown( event ) {
			if ( ! document.body.contains( viewEl ) ) {
				document.removeEventListener( 'keydown', handleKeydown );
				return;
			}

			if ( 'ArrowDown' !== event.key && 'ArrowUp' !== event.key && 'Escape' !== event.key ) {
				return;
			}
			var active = document.activeElement;
			if ( active && ( 'INPUT' === active.tagName || 'SELECT' === active.tagName || 'TEXTAREA' === active.tagName ) ) {
				return; // Never hijack typing in the search box or a form field.
			}

			if ( 'Escape' === event.key ) {
				splitEl.classList.remove( 'has-selection' );
				return;
			}

			var visibleRows = Array.prototype.slice.call( rowsEl.querySelectorAll( '[data-id]' ) );
			if ( ! visibleRows.length ) {
				return;
			}
			var currentIndex = visibleRows.findIndex( function ( row ) { return parseInt( row.getAttribute( 'data-id' ), 10 ) === selectedId; } );
			var nextIndex = 'ArrowDown' === event.key ? currentIndex + 1 : currentIndex - 1;
			nextIndex = Math.max( 0, Math.min( visibleRows.length - 1, nextIndex ) );

			if ( nextIndex === currentIndex && -1 !== currentIndex ) {
				return;
			}
			event.preventDefault();
			var nextRow = visibleRows[ nextIndex ];
			selectRow( parseInt( nextRow.getAttribute( 'data-id' ), 10 ) );
			nextRow.scrollIntoView( { block: 'nearest' } );
		}

		document.addEventListener( 'keydown', handleKeydown );

		searchEl.addEventListener( 'input', function () { renderRows( allOrders ); } );
		statusFilterEl.addEventListener( 'change', load );

		load();
	};
} )();
