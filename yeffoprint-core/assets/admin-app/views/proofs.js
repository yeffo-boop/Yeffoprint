/**
 * Proofs — a flat, browsable list of every proof ever uploaded across
 * every Custom Order (docs/ARCHITECTURE.md, Phase 6), same scope as
 * the classic `edit.php?post_type=yp_proof` list table. Adding one
 * here is the same action as the "+ Add Proof" button inside a Custom
 * Order's own detail view (views/orders.js) — both call the same
 * `POST /admin/proof` endpoint — this screen just starts from "pick
 * which order" instead of already being inside one.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	function endpoint( path ) {
		return yeffoprintAdminApp.restUrl + 'admin/' + path;
	}

	YP.views.proofs = function ( viewEl ) {
		var allProofs = [];

		viewEl.innerHTML =
			'<p class="yp-app__intro">Every proof file staff have sent a customer for review, across every Custom Order.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search by order&hellip;" />' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add Proof</button>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Order</th><th>File</th><th>Date</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="4">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl  = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl = viewEl.querySelector( '[data-yp-search]' );

		function load() {
			rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="4">Loading&hellip;</td></tr>';
			YP.request( endpoint( 'proofs' ) )
				.then( function ( proofs ) {
					allProofs = proofs || [];
					renderRows( allProofs );
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="4">Couldn’t load proofs: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows( proofs ) {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? proofs.filter( function ( p ) { return p.custom_order_title.toLowerCase().indexOf( query ) !== -1; } )
				: proofs;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="4">' + ( proofs.length ? 'No proofs match your search.' : 'No proofs uploaded yet — add the first one above.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( proof ) {
				return (
					'<tr data-id="' + proof.id + '">' +
						'<td><div class="yp-record-name">' + YP.escapeHtml( proof.custom_order_title || '—' ) + '</div></td>' +
						'<td>' + ( proof.file_url ? '<a href="' + YP.escapeAttr( proof.file_url ) + '" target="_blank" rel="noopener noreferrer">' + YP.escapeHtml( proof.title ) + '</a>' : YP.escapeHtml( proof.title ) ) + '</td>' +
						'<td>' + new Date( proof.date ).toLocaleDateString() + '</td>' +
						'<td class="yp-row-actions"><button type="button" class="yp-row-action" data-yp-delete="' + proof.id + '">Delete</button></td>' +
					'</tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-delete]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { deleteProof( button.getAttribute( 'data-yp-delete' ) ); } );
			} );
		}

		function deleteProof( id ) {
			if ( ! window.confirm( 'Delete this proof? This moves it to Trash — it can be restored from Proofs → Trash in wp-admin if needed.' ) ) {
				return;
			}
			YP.request( endpoint( 'proof/' + id ), { method: 'DELETE' } )
				.then( load )
				.catch( function ( error ) {
					window.alert( 'Couldn’t delete: ' + error.message );
				} );
		}

		/* ---------- Add Proof drawer ---------- */

		function openForm() {
			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--center';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="Add Proof">' +
					'<div class="yp-drawer__header"><span>Add Proof</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<form class="yp-form" data-yp-form>' +
							'<div data-yp-form-error></div>' +
							'<div class="yp-field"><label for="yp-proof-order">Custom Order</label><select id="yp-proof-order" required><option value="">Loading&hellip;</option></select></div>' +
							'<div class="yp-field"><label>Proof file</label>' +
								'<div class="yp-media-field">' +
									'<div class="yp-media-field__preview" data-yp-file-preview></div>' +
									'<div class="yp-media-field__buttons">' +
										'<input type="hidden" data-yp-file-id />' +
										'<button type="button" class="wp-block-button__link is-style-outline" data-yp-file-select>Select file</button>' +
									'</div>' +
								'</div>' +
							'</div>' +
							'<div class="yp-form__actions">' +
								'<button type="submit" class="wp-block-button__link is-style-accent" data-yp-save>Add proof</button>' +
								'<button type="button" class="wp-block-button__link is-style-outline" data-yp-drawer-close>Cancel</button>' +
							'</div>' +
						'</form>' +
					'</div>' +
				'</div>';

			document.body.appendChild( drawer );
			YP.initDrawer( drawer );
			YP.openDrawer( drawer );

			var orderSelectEl = drawer.querySelector( '#yp-proof-order' );
			var fileIdEl       = drawer.querySelector( '[data-yp-file-id]' );
			var filePreviewEl  = drawer.querySelector( '[data-yp-file-preview]' );

			YP.request( endpoint( 'custom-orders' ) )
				.then( function ( orders ) {
					var paidOrders = ( orders || [] ).filter( function ( o ) { return o.paid; } );
					orderSelectEl.innerHTML = paidOrders.length
						? paidOrders.map( function ( o ) { return '<option value="' + o.id + '">' + YP.escapeHtml( o.title ) + ' — ' + YP.escapeHtml( o.customer_name || o.customer_email ) + '</option>'; } ).join( '' )
						: '<option value="">No paid orders yet</option>';
				} )
				.catch( function () {
					orderSelectEl.innerHTML = '<option value="">Couldn’t load orders</option>';
				} );

			if ( typeof wp !== 'undefined' && wp.media ) {
				drawer.querySelector( '[data-yp-file-select]' ).addEventListener( 'click', function ( event ) {
					event.preventDefault();
					var frame = wp.media( { title: 'Select proof file', multiple: false, button: { text: 'Use this file' } } );
					frame.on( 'select', function () {
						var attachment = frame.state().get( 'selection' ).first().toJSON();
						fileIdEl.value = attachment.id;
						filePreviewEl.innerHTML = YP.escapeHtml( attachment.filename || attachment.title || ( 'File #' + attachment.id ) );
					} );
					frame.open();
				} );
			}

			drawer.querySelector( '[data-yp-form]' ).addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				save( drawer, orderSelectEl, fileIdEl );
			} );
		}

		function save( drawer, orderSelectEl, fileIdEl ) {
			var errorEl = drawer.querySelector( '[data-yp-form-error]' );
			var saveButton = drawer.querySelector( '[data-yp-save]' );
			var customOrderId = parseInt( orderSelectEl.value, 10 ) || 0;
			var fileId = parseInt( fileIdEl.value, 10 ) || 0;

			if ( ! customOrderId ) {
				errorEl.innerHTML = '<p class="yp-form__error">Choose a Custom Order.</p>';
				return;
			}
			if ( ! fileId ) {
				errorEl.innerHTML = '<p class="yp-form__error">Choose a file.</p>';
				return;
			}

			errorEl.innerHTML = '';
			saveButton.disabled = true;
			saveButton.textContent = 'Adding…';

			YP.request( endpoint( 'proofs' ), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { custom_order_id: customOrderId, file_id: fileId } ) } )
				.then( function () {
					YP.closeDrawer( drawer );
					load();
				} )
				.catch( function ( error ) {
					saveButton.disabled = false;
					saveButton.textContent = 'Add proof';
					errorEl.innerHTML = '<p class="yp-form__error">Couldn’t add proof: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		viewEl.querySelector( '[data-yp-add]' ).addEventListener( 'click', openForm );
		searchEl.addEventListener( 'input', function () { renderRows( allProofs ); } );

		load();
	};
} )();
