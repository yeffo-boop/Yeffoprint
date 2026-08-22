/**
 * Create a Custom Label form (PROJECT_SPEC §13, extended by the batching +
 * fee-skip plan) — a separate flow from the Template configurator, not a
 * preview/live-pricing experience: loads Size/Material options and the
 * current design fee, handles multi-file upload (each file goes to the
 * server immediately on selection so the customer sees per-file success/
 * failure before submitting), then posts the full request and hands off to
 * WooCommerce checkout to actually pay whatever's due.
 *
 * Three submission modes (mirrors class-custom-order-controller.php's
 * parse_mode()): 'new_design' (default, $25 design fee), 'own_design' (the
 * customer already has a print-ready file — fee skipped, upload required),
 * and 'reorder' (a past, already-finished design of this customer's own —
 * fee skipped, picked from GET /custom-orders/eligible-reorders).
 *
 * The single Size/Material/Quantity/Compound fields became a batch: one or
 * more always-expanded rows (add/duplicate/remove), each its own size +
 * material + quantity + compound/strength, submitted as `batch[]`. Rows are
 * only ever rebuilt wholesale on structural changes (add/duplicate/remove/
 * prefill) — a field-level edit (a select's change, typing in an input)
 * mutates that row's state in place and re-requests the price preview,
 * without touching the DOM the customer is currently interacting with.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintCustomOrder === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'yp-custom-order' );
	if ( ! root ) {
		return;
	}

	var statusEl = root.querySelector( '.yp-configurator__status' );
	var form = document.getElementById( 'yp-custom-order-form' );
	var modeRadios = Array.prototype.slice.call( form.querySelectorAll( 'input[name="mode"]' ) );
	var reorderPickerEl = root.querySelector( '[data-yp-co-reorder-picker]' );
	var reorderSelectEl = document.getElementById( 'yp-co-reorder-select' );
	var reorderEmptyEl = root.querySelector( '[data-yp-co-reorder-empty]' );
	var batchContainerEl = root.querySelector( '[data-yp-co-batch]' );
	var addRowButton = root.querySelector( '[data-yp-co-add-row]' );
	var filesLabelTextEl = root.querySelector( '[data-yp-co-files-label-text]' );
	var filesHintEl = root.querySelector( '[data-yp-co-files-hint]' );
	var filesInput = document.getElementById( 'yp-co-files' );
	var fileListEl = root.querySelector( '[data-yp-co-file-list]' );
	var submitButton = root.querySelector( '[data-yp-co-submit]' );
	var feeEl = root.querySelector( '[data-yp-co-fee]' );
	var labelsTotalEl = root.querySelector( '[data-yp-co-labels-total]' );
	var totalEl = root.querySelector( '[data-yp-co-total]' );

	var state = { mode: 'new_design' };

	var sizesData = [];
	var materialsData = [];
	var quantityPresets = [];
	var batchRows = [];
	var nextRowId = 0;
	var uploadedFiles = []; // { name, id, error }
	var formErrorEl = null;
	var pricingRequestId = 0;
	var eligibleReorders = [];
	var eligibleReordersLoaded = false;

	function formatCurrency( amount ) {
		return '$' + Number( amount ).toFixed( 2 );
	}

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}

	function optionsHtml( records, selectedId ) {
		return records.map( function ( record ) {
			return '<option value="' + record.id + '"' + ( record.id === selectedId ? ' selected' : '' ) + '>' + escapeHtml( record.name ) + '</option>';
		} ).join( '' );
	}

	/* ---------- Batch rows ---------- */

	function createRow( overrides ) {
		return Object.assign( {
			id: nextRowId++,
			size_id: sizesData.length ? sizesData[ 0 ].id : 0,
			material_id: materialsData.length ? materialsData[ 0 ].id : 0,
			quantity: quantityPresets[ 0 ] || 10,
			compound_strength: ''
		}, overrides || {} );
	}

	function findRowIndex( rowId ) {
		for ( var i = 0; i < batchRows.length; i++ ) {
			if ( batchRows[ i ].id === rowId ) {
				return i;
			}
		}
		return -1;
	}

	function currentBatchPayload() {
		return batchRows.map( function ( row ) {
			return {
				size_id: row.size_id,
				material_id: row.material_id,
				quantity: row.quantity,
				compound_strength: row.compound_strength
			};
		} );
	}

	function rowMarkup( row, index ) {
		return (
			'<div class="yp-batch-row" data-row-id="' + row.id + '">' +
				'<div class="yp-batch-row__header">' +
					'<span class="yp-batch-row__title"><span class="yp-batch-row__index">' + ( index + 1 ) + '</span> Label ' + ( index + 1 ) + '</span>' +
					'<span class="yp-batch-row__actions">' +
						'<button type="button" class="button-link" data-duplicate-row="' + row.id + '">Duplicate</button>' +
						( batchRows.length > 1 ? '<button type="button" class="button-link" data-remove-row="' + row.id + '">Remove</button>' : '' ) +
					'</span>' +
				'</div>' +
				'<div class="yp-field">' +
					'<label for="yp-co-row-' + row.id + '-size">Size</label>' +
					'<select id="yp-co-row-' + row.id + '-size" data-row-field="size_id" required>' + optionsHtml( sizesData, row.size_id ) + '</select>' +
				'</div>' +
				'<div class="yp-field">' +
					'<label for="yp-co-row-' + row.id + '-material">Material</label>' +
					'<select id="yp-co-row-' + row.id + '-material" data-row-field="material_id" required>' + optionsHtml( materialsData, row.material_id ) + '</select>' +
				'</div>' +
				'<div class="yp-field">' +
					'<label for="yp-co-row-' + row.id + '-qty-input">Quantity</label>' +
					'<div class="yp-quantity-control" data-row-quantity></div>' +
				'</div>' +
				'<div class="yp-field">' +
					'<label for="yp-co-row-' + row.id + '-compound">Compound / strength <span class="description">(optional)</span></label>' +
					'<input type="text" id="yp-co-row-' + row.id + '-compound" data-row-field="compound_strength" maxlength="120" class="widefat" />' +
				'</div>' +
			'</div>'
		);
	}

	function renderRowQuantity( row, container ) {
		container.innerHTML = quantityPresets.map( function ( amount ) {
			return '<button type="button" class="yp-quantity-preset' + ( amount === row.quantity ? ' is-active' : '' ) + '" data-preset="' + amount + '">' + amount + '</button>';
		} ).join( '' ) +
			'<input type="number" min="1" id="yp-co-row-' + row.id + '-qty-input" class="yp-quantity-input" value="' + row.quantity + '" />';

		var input = container.querySelector( 'input' );

		function syncPresetHighlight() {
			container.querySelectorAll( '[data-preset]' ).forEach( function ( button ) {
				button.classList.toggle( 'is-active', parseInt( button.getAttribute( 'data-preset' ), 10 ) === row.quantity );
			} );
		}

		container.querySelectorAll( '[data-preset]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				row.quantity = parseInt( button.getAttribute( 'data-preset' ), 10 );
				input.value = row.quantity;
				syncPresetHighlight();
				updatePricePreview();
			} );
		} );

		input.addEventListener( 'input', function ( event ) {
			row.quantity = Math.max( 1, parseInt( event.target.value, 10 ) || 1 );
			syncPresetHighlight();
			updatePricePreview();
		} );
	}

	function renderBatch() {
		batchContainerEl.innerHTML = batchRows.map( rowMarkup ).join( '' );

		batchContainerEl.querySelectorAll( '.yp-batch-row' ).forEach( function ( rowEl ) {
			var rowId = parseInt( rowEl.getAttribute( 'data-row-id' ), 10 );
			var row = batchRows[ findRowIndex( rowId ) ];
			if ( ! row ) {
				return;
			}

			var sizeSelect = rowEl.querySelector( '[data-row-field="size_id"]' );
			sizeSelect.addEventListener( 'change', function () {
				row.size_id = parseInt( sizeSelect.value, 10 );
				updatePricePreview();
			} );

			var materialSelect = rowEl.querySelector( '[data-row-field="material_id"]' );
			materialSelect.addEventListener( 'change', function () {
				row.material_id = parseInt( materialSelect.value, 10 );
				updatePricePreview();
			} );

			// Property assignment, not an HTML `value="…"` attribute — this
			// is customer-authored free text (round-tripped from a reorder
			// prefill, in particular), and setting it as a DOM property is
			// what keeps a stray `"` in it from breaking out of the
			// attribute the way string-concatenating it into rowMarkup's
			// HTML would.
			var compoundInput = rowEl.querySelector( '[data-row-field="compound_strength"]' );
			compoundInput.value = row.compound_strength;
			compoundInput.addEventListener( 'input', function () {
				row.compound_strength = compoundInput.value;
			} );

			renderRowQuantity( row, rowEl.querySelector( '[data-row-quantity]' ) );
		} );

		batchContainerEl.querySelectorAll( '[data-duplicate-row]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				duplicateRow( parseInt( button.getAttribute( 'data-duplicate-row' ), 10 ) );
			} );
		} );

		batchContainerEl.querySelectorAll( '[data-remove-row]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				removeRow( parseInt( button.getAttribute( 'data-remove-row' ), 10 ) );
			} );
		} );
	}

	function duplicateRow( rowId ) {
		var index = findRowIndex( rowId );
		if ( index === -1 ) {
			return;
		}
		var source = batchRows[ index ];
		batchRows.splice( index + 1, 0, createRow( {
			size_id: source.size_id,
			material_id: source.material_id,
			quantity: source.quantity,
			compound_strength: source.compound_strength
		} ) );
		renderBatch();
		updatePricePreview();
	}

	function removeRow( rowId ) {
		if ( batchRows.length <= 1 ) {
			return;
		}
		var index = findRowIndex( rowId );
		if ( index === -1 ) {
			return;
		}
		batchRows.splice( index, 1 );
		renderBatch();
		updatePricePreview();
	}

	addRowButton.addEventListener( 'click', function () {
		batchRows.push( createRow() );
		renderBatch();
		updatePricePreview();
	} );

	/* ---------- Pricing preview ---------- */

	/**
	 * A provisional preview only — same as the Template configurator, the
	 * server recalculates the authoritative price at add-to-cart time
	 * (class-cart-pricing.php's apply_price()), so nothing here needs to be
	 * trusted, just kept close enough to avoid surprises before checkout.
	 * Uses the batch-aware /custom-orders/pricing-preview endpoint rather
	 * than /pricing/calculate, since a not-yet-submitted batch's rows need
	 * to share one bulk-discount tier with each other, not just with
	 * whatever's already in the cart.
	 */
	function updatePricePreview() {
		var batch = currentBatchPayload();
		if ( ! batch.length || batch.some( function ( row ) { return ! row.size_id || ! row.material_id || ! row.quantity; } ) ) {
			return;
		}

		var requestId = ++pricingRequestId;

		fetch( yeffoprintCustomOrder.restUrl + 'custom-orders/pricing-preview', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { mode: state.mode, batch: batch } )
		} )
			.then( function ( response ) {
				return response.ok ? response.json() : Promise.reject( new Error( 'pricing-failed' ) );
			} )
			.then( function ( data ) {
				if ( requestId !== pricingRequestId ) {
					return; // A newer request has since superseded this one.
				}

				if ( 'own_design' === state.mode ) {
					feeEl.textContent = '$0.00 — no design work needed';
				} else if ( 'reorder' === state.mode ) {
					feeEl.textContent = '$0.00 — fee skipped (reorder)';
				} else {
					feeEl.textContent = formatCurrency( data.design_fee );
				}

				labelsTotalEl.textContent = formatCurrency( data.labels_subtotal );
				totalEl.textContent = formatCurrency( data.total );
			} )
			.catch( function () {} );
	}

	/* ---------- Mode switching ---------- */

	function applyModeUi() {
		var mode = state.mode;

		reorderPickerEl.hidden = 'reorder' !== mode;
		if ( 'reorder' === mode && ! eligibleReordersLoaded ) {
			eligibleReordersLoaded = true;
			loadEligibleReorders();
		}

		if ( 'own_design' === mode ) {
			filesLabelTextEl.textContent = 'Your print-ready design file(s)';
			filesHintEl.textContent = '(required — PDF, SVG, PNG, or JPG, up to 5 files)';
		} else {
			filesLabelTextEl.textContent = 'Inspiration files';
			filesHintEl.textContent = '(optional — PDF, SVG, PNG, or JPG, up to 5 files)';
		}
		// Not the native `required` attribute: it validates the <input>
		// element's own value, but a customer can arrive at own_design
		// mode already holding uploads from a reorder prefill without the
		// <input> itself ever holding a file — the submit handler's own
		// uploadedFiles check below enforces this instead.
	}

	function setMode( mode ) {
		state.mode = mode;
		modeRadios.forEach( function ( radio ) {
			radio.checked = radio.value === mode;
		} );
		applyModeUi();
	}

	modeRadios.forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			if ( ! radio.checked ) {
				return;
			}
			state.mode = radio.value;
			applyModeUi();
			updatePricePreview();
		} );
	} );

	// Reorder mode has no guest path (class-custom-order-controller.php's
	// /custom-orders/eligible-reorders is logged-in only) — disabled up
	// front rather than letting a guest pick it and only then discovering
	// the picker has nothing to show. The reason is shown as an always-
	// visible note (data-yp-co-reorder-login-note), not a `title`
	// attribute — `title` only ever surfaces on hover, which doesn't
	// exist on a touch device, so a phone would show no explanation at
	// all for why the option won't select.
	var reorderModeRadio = form.querySelector( 'input[name="mode"][value="reorder"]' );
	if ( reorderModeRadio && ! yeffoprintCustomOrder.isLoggedIn ) {
		reorderModeRadio.disabled = true;
		var reorderOption = reorderModeRadio.closest( '.yp-radio-option' );
		if ( reorderOption ) {
			reorderOption.classList.add( 'is-disabled' );
			var loginNote = reorderOption.querySelector( '[data-yp-co-reorder-login-note]' );
			if ( loginNote ) {
				loginNote.hidden = false;
			}
		}
	}

	/* ---------- Reorder picker ---------- */

	function renderReorderOptions() {
		if ( ! eligibleReorders.length ) {
			reorderSelectEl.innerHTML = '';
			reorderSelectEl.hidden = true;
			reorderEmptyEl.hidden = false;
			return;
		}

		reorderSelectEl.hidden = false;
		reorderEmptyEl.hidden = true;
		reorderSelectEl.innerHTML = '<option value="">Choose a past design&hellip;</option>' +
			eligibleReorders.map( function ( item ) {
				var label = ( item.brand_name || ( 'Order #' + item.id ) ) + ' — ' + item.status_label + ' (' + item.date + ')';
				return '<option value="' + item.id + '">' + escapeHtml( label ) + '</option>';
			} ).join( '' );
	}

	function loadEligibleReorders( callback ) {
		fetch( yeffoprintCustomOrder.restUrl + 'custom-orders/eligible-reorders', {
			headers: { 'X-WP-Nonce': yeffoprintCustomOrder.nonce }
		} )
			.then( function ( response ) {
				return response.ok ? response.json() : [];
			} )
			.then( function ( list ) {
				eligibleReorders = list || [];
				renderReorderOptions();
				if ( callback ) {
					callback();
				}
			} )
			.catch( function () {
				eligibleReorders = [];
				renderReorderOptions();
				if ( callback ) {
					callback();
				}
			} );
	}

	reorderSelectEl.addEventListener( 'change', function () {
		if ( reorderSelectEl.value ) {
			prefillFromPastOrder( reorderSelectEl.value, false );
		}
	} );

	/* ---------- Prefill from a past Custom Order ---------- */

	function applyPastOrderFields( data ) {
		document.getElementById( 'yp-co-brand' ).value = data.brand_name || '';
		document.getElementById( 'yp-co-style' ).value = data.style_notes || '';
		document.getElementById( 'yp-co-instructions' ).value = data.instructions || '';

		uploadedFiles = ( data.uploads || [] ).map( function ( file ) {
			return { name: file.name, id: file.id, error: null };
		} );
		renderFileList();
	}

	/**
	 * Pre-fills a fresh request from a past Custom Order's own details
	 * (class-custom-order-controller.php, ownership-checked there) —
	 * "reorder" for a one-off custom design means resubmitting with the
	 * same brief, not restoring into a configurator that doesn't exist for
	 * this flow. Previously-uploaded reference files carry over as
	 * already-uploaded (same attachment, no re-upload needed) unless the
	 * customer removes them.
	 *
	 * `checkEligibility` is only true for the `?reorder=` URL entry point
	 * (the "Reorder this custom design" link on a past order) — that link
	 * doesn't know or care whether the fee is skippable, so this checks
	 * eligible-reorders itself and only switches to fee-free 'reorder' mode
	 * if the order is actually on that list; otherwise the fields still
	 * prefill, just under the normal fee-charging 'new_design' mode. A pick
	 * from the in-form reorder picker (`checkEligibility: false`) is
	 * already known-eligible — it only ever lists eligible orders.
	 */
	function prefillFromPastOrder( id, checkEligibility ) {
		fetch( yeffoprintCustomOrder.restUrl + 'custom-orders/' + encodeURIComponent( id ), {
			headers: { 'X-WP-Nonce': yeffoprintCustomOrder.nonce }
		} )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( data ) {
				if ( ! data ) {
					return;
				}

				applyPastOrderFields( data );

				var overrides = { compound_strength: data.compound_strength || '' };
				if ( data.size_id ) {
					overrides.size_id = data.size_id;
				}
				if ( data.material_id ) {
					overrides.material_id = data.material_id;
				}
				if ( data.quantity ) {
					overrides.quantity = data.quantity;
				}
				batchRows = [ createRow( overrides ) ];
				renderBatch();

				if ( checkEligibility ) {
					loadEligibleReorders( function () {
						var isEligible = eligibleReorders.some( function ( item ) {
							return item.id === parseInt( id, 10 );
						} );
						if ( isEligible ) {
							setMode( 'reorder' );
							reorderSelectEl.value = String( id );
						}
						updatePricePreview();
					} );
				} else {
					updatePricePreview();
				}
			} )
			.catch( function () {} );
	}

	/* ---------- Init ---------- */

	function init() {
		var reorderId = new URLSearchParams( window.location.search ).get( 'reorder' );

		fetch( yeffoprintCustomOrder.restUrl + 'custom-orders/options' )
			.then( function ( response ) {
				return response.ok ? response.json() : Promise.reject( new Error( 'options-failed' ) );
			} )
			.then( function ( data ) {
				sizesData = data.sizes || [];
				materialsData = data.materials || [];
				feeEl.textContent = data.design_fee || '$25.00';

				quantityPresets = data.quantity_presets && data.quantity_presets.length ? data.quantity_presets : [ 10 ];

				batchRows = [ createRow() ];
				renderBatch();

				statusEl.hidden = true;
				form.hidden = false;

				if ( reorderId ) {
					prefillFromPastOrder( reorderId, true );
				} else {
					updatePricePreview();
				}
			} )
			.catch( function () {
				statusEl.textContent = "This form couldn't be loaded. Please refresh, or contact us directly.";
				statusEl.setAttribute( 'data-state', 'error' );
			} );
	}

	/* ---------- File uploads ---------- */

	function renderFileList() {
		fileListEl.innerHTML = uploadedFiles.map( function ( file, index ) {
			var status = file.error
				? '<span class="is-error">' + escapeHtml( file.error ) + '</span>'
				: ( file.id ? '<span>Uploaded</span>' : '<span>Uploading&hellip;</span>' );

			return '<li>' + escapeHtml( file.name ) + ' — ' + status +
				' <button type="button" class="button-link" data-remove-file="' + index + '">Remove</button></li>';
		} ).join( '' );

		fileListEl.querySelectorAll( '[data-remove-file]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				uploadedFiles.splice( parseInt( button.getAttribute( 'data-remove-file' ), 10 ), 1 );
				renderFileList();
			} );
		} );
	}

	filesInput.addEventListener( 'change', function () {
		var selected = Array.prototype.slice.call( filesInput.files );
		filesInput.value = '';

		if ( ! selected.length ) {
			return;
		}

		var formData = new FormData();
		selected.forEach( function ( file ) {
			formData.append( 'files[]', file );
		} );

		var placeholders = selected.map( function ( file ) {
			return { name: file.name, id: null, error: null };
		} );
		uploadedFiles = uploadedFiles.concat( placeholders );
		renderFileList();

		fetch( yeffoprintCustomOrder.restUrl + 'custom-orders/uploads', {
			method: 'POST',
			headers: { 'X-WP-Nonce': yeffoprintCustomOrder.nonce },
			body: formData
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				( data.files || [] ).forEach( function ( result, i ) {
					var placeholder = placeholders[ i ];
					var entry = uploadedFiles.indexOf( placeholder );
					if ( entry === -1 ) {
						return;
					}
					if ( result.success ) {
						uploadedFiles[ entry ].id = result.id;
					} else {
						uploadedFiles[ entry ].error = result.message;
					}
				} );
				renderFileList();
			} )
			.catch( function () {
				placeholders.forEach( function ( placeholder ) {
					var entry = uploadedFiles.indexOf( placeholder );
					if ( entry !== -1 ) {
						uploadedFiles[ entry ].error = 'Upload failed.';
					}
				} );
				renderFileList();
			} );
	} );

	/* ---------- Submit ---------- */

	function showFormError( message ) {
		if ( ! formErrorEl ) {
			formErrorEl = document.createElement( 'p' );
			formErrorEl.className = 'yp-configurator__cart-status is-error';
			submitButton.insertAdjacentElement( 'afterend', formErrorEl );
		}
		formErrorEl.textContent = message;
	}

	function clearFormError() {
		if ( formErrorEl ) {
			formErrorEl.remove();
			formErrorEl = null;
		}
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		clearFormError();

		if ( uploadedFiles.some( function ( file ) { return ! file.id && ! file.error; } ) ) {
			showFormError( 'Please wait for your files to finish uploading.' );
			return;
		}

		if ( 'own_design' === state.mode && ! uploadedFiles.some( function ( file ) { return file.id; } ) ) {
			showFormError( 'Please attach your print-ready design file(s).' );
			return;
		}

		var sourceCustomOrderId = 0;
		if ( 'reorder' === state.mode ) {
			sourceCustomOrderId = parseInt( reorderSelectEl.value, 10 ) || 0;
			if ( ! sourceCustomOrderId ) {
				showFormError( 'Please choose which past design to reorder.' );
				return;
			}
		}

		submitButton.disabled = true;

		var payload = {
			mode: state.mode,
			batch: currentBatchPayload(),
			brand_name: document.getElementById( 'yp-co-brand' ).value,
			style_notes: document.getElementById( 'yp-co-style' ).value,
			instructions: document.getElementById( 'yp-co-instructions' ).value,
			uploads: uploadedFiles.filter( function ( file ) { return file.id; } ).map( function ( file ) { return file.id; } )
		};

		if ( sourceCustomOrderId ) {
			payload.source_custom_order_id = sourceCustomOrderId;
		}

		fetch( yeffoprintCustomOrder.restUrl + 'custom-orders', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': yeffoprintCustomOrder.nonce },
			body: JSON.stringify( payload )
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					submitButton.disabled = false;
					showFormError( ( result.data && result.data.message ) || "Couldn't submit your request. Please try again." );
					return;
				}

				window.location.href = result.data.checkout_url;
			} )
			.catch( function () {
				submitButton.disabled = false;
				showFormError( "Couldn't reach the server — please try again." );
			} );
	} );

	// Syncs state.mode (and the UI it drives) to whichever radio the
	// browser actually shows checked at load, rather than assuming
	// 'new_design' — a bfcache/back-navigation restore can leave a
	// different radio checked than a freshly-parsed document would.
	var checkedModeRadio = form.querySelector( 'input[name="mode"]:checked' );
	state.mode = checkedModeRadio ? checkedModeRadio.value : 'new_design';
	applyModeUi();

	init();
} )();
