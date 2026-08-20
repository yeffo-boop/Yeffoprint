/**
 * Create a Custom Label form (PROJECT_SPEC §13) — a separate flow from
 * the Template configurator, not a preview/live-pricing experience:
 * loads Size/Material options and the current design fee, handles
 * multi-file upload (each file goes to the server immediately on
 * selection so the customer sees per-file success/failure before
 * submitting), then posts the full request and hands off to
 * WooCommerce checkout to actually pay the fee.
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
	var sizeSelect = document.getElementById( 'yp-co-size' );
	var materialSelect = document.getElementById( 'yp-co-material' );
	var quantityContainer = root.querySelector( '[data-yp-co-quantity]' );
	var feeEl = root.querySelector( '[data-yp-co-fee]' );
	var filesInput = document.getElementById( 'yp-co-files' );
	var fileListEl = root.querySelector( '[data-yp-co-file-list]' );
	var submitButton = root.querySelector( '[data-yp-co-submit]' );
	var labelQtyEl = root.querySelector( '[data-yp-co-label-qty]' );
	var unitPriceEl = root.querySelector( '[data-yp-co-unit-price]' );
	var labelsTotalEl = root.querySelector( '[data-yp-co-labels-total]' );
	var totalEl = root.querySelector( '[data-yp-co-total]' );

	var quantityPresets = [];
	var quantity = 25;
	var uploadedFiles = []; // { name, id, error }
	var formErrorEl = null;
	var designFee = 0;
	var pricingRequestId = 0;

	function formatCurrency( amount ) {
		return '$' + amount.toFixed( 2 );
	}

	/**
	 * A provisional preview only — same as the Template configurator,
	 * the server recalculates the authoritative price at add-to-cart
	 * time (class-cart-pricing.php's apply_price()), so nothing here
	 * needs to be trusted, just kept close enough to avoid surprises
	 * before checkout.
	 */
	function updatePricePreview() {
		if ( ! sizeSelect.value || ! materialSelect.value || ! quantity ) {
			return;
		}

		var requestId = ++pricingRequestId;
		var params = new URLSearchParams( {
			quantity: quantity,
			size_id: sizeSelect.value,
			material_id: materialSelect.value
		} );

		fetch( yeffoprintCustomOrder.restUrl + 'pricing/calculate?' + params.toString() )
			.then( function ( response ) {
				return response.ok ? response.json() : Promise.reject( new Error( 'pricing-failed' ) );
			} )
			.then( function ( data ) {
				if ( requestId !== pricingRequestId ) {
					return; // A newer request has since superseded this one.
				}

				labelQtyEl.textContent = quantity;
				unitPriceEl.textContent = formatCurrency( data.unit_price_after_discount );
				labelsTotalEl.textContent = formatCurrency( data.total );
				totalEl.textContent = formatCurrency( designFee + data.total );
			} )
			.catch( function () {} );
	}

	sizeSelect.addEventListener( 'change', updatePricePreview );
	materialSelect.addEventListener( 'change', updatePricePreview );

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}

	function optionsHtml( records ) {
		return records.map( function ( record ) {
			return '<option value="' + record.id + '">' + escapeHtml( record.name ) + '</option>';
		} ).join( '' );
	}

	function renderQuantity() {
		quantityContainer.innerHTML = quantityPresets.map( function ( amount ) {
			return '<button type="button" class="yp-quantity-preset' + ( amount === quantity ? ' is-active' : '' ) + '" data-preset="' + amount + '">' + amount + '</button>';
		} ).join( '' ) +
			'<input type="number" min="1" id="yp-co-quantity-input" class="yp-quantity-input" value="' + quantity + '" />';

		quantityContainer.querySelectorAll( '[data-preset]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				setQuantity( parseInt( button.getAttribute( 'data-preset' ), 10 ) );
			} );
		} );

		quantityContainer.querySelector( '#yp-co-quantity-input' ).addEventListener( 'input', function ( event ) {
			quantity = Math.max( 1, parseInt( event.target.value, 10 ) || 1 );
			quantityContainer.querySelectorAll( '[data-preset]' ).forEach( function ( button ) {
				button.classList.toggle( 'is-active', parseInt( button.getAttribute( 'data-preset' ), 10 ) === quantity );
			} );
			updatePricePreview();
		} );
	}

	function setQuantity( amount ) {
		quantity = amount;
		quantityContainer.querySelector( '#yp-co-quantity-input' ).value = amount;
		quantityContainer.querySelectorAll( '[data-preset]' ).forEach( function ( button ) {
			button.classList.toggle( 'is-active', parseInt( button.getAttribute( 'data-preset' ), 10 ) === amount );
		} );
		updatePricePreview();
	}

	function init() {
		var reorderId = new URLSearchParams( window.location.search ).get( 'reorder' );

		fetch( yeffoprintCustomOrder.restUrl + 'custom-orders/options' )
			.then( function ( response ) {
				return response.ok ? response.json() : Promise.reject( new Error( 'options-failed' ) );
			} )
			.then( function ( data ) {
				sizeSelect.innerHTML = optionsHtml( data.sizes || [] );
				materialSelect.innerHTML = optionsHtml( data.materials || [] );
				feeEl.textContent = data.design_fee || '$25.00';
				designFee = parseFloat( String( data.design_fee || '25' ).replace( /[^0-9.]/g, '' ) ) || 0;

				quantityPresets = data.quantity_presets && data.quantity_presets.length ? data.quantity_presets : [ 25 ];
				quantity = quantityPresets[ 0 ];
				renderQuantity();
				updatePricePreview();

				statusEl.hidden = true;
				form.hidden = false;

				if ( reorderId ) {
					loadReorderData( reorderId );
				}
			} )
			.catch( function () {
				statusEl.textContent = "This form couldn't be loaded. Please refresh, or contact us directly.";
				statusEl.setAttribute( 'data-state', 'error' );
			} );
	}

	/**
	 * Pre-fills a fresh request from a past Custom Order's own details
	 * (class-custom-order-controller.php, ownership-checked there) —
	 * "reorder" for a one-off custom design means resubmitting with the
	 * same brief, not restoring into a configurator that doesn't exist
	 * for this flow. Previously-uploaded reference files carry over as
	 * already-uploaded (same attachment, no re-upload needed) unless
	 * the customer removes them.
	 */
	function loadReorderData( reorderId ) {
		fetch( yeffoprintCustomOrder.restUrl + 'custom-orders/' + encodeURIComponent( reorderId ), {
			headers: { 'X-WP-Nonce': yeffoprintCustomOrder.nonce }
		} )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( data ) {
				if ( ! data ) {
					return;
				}

				if ( data.size_id ) {
					sizeSelect.value = data.size_id;
				}
				if ( data.material_id ) {
					materialSelect.value = data.material_id;
				}
				if ( data.quantity ) {
					setQuantity( data.quantity );
				}

				document.getElementById( 'yp-co-brand' ).value = data.brand_name || '';
				document.getElementById( 'yp-co-compound' ).value = data.compound_strength || '';
				document.getElementById( 'yp-co-style' ).value = data.style_notes || '';
				document.getElementById( 'yp-co-instructions' ).value = data.instructions || '';

				if ( data.uploads && data.uploads.length ) {
					uploadedFiles = data.uploads.map( function ( file ) {
						return { name: file.name, id: file.id, error: null };
					} );
					renderFileList();
				}

				updatePricePreview();
			} )
			.catch( function () {} );
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

		submitButton.disabled = true;

		var payload = {
			size_id: parseInt( sizeSelect.value, 10 ),
			material_id: parseInt( materialSelect.value, 10 ),
			quantity: quantity,
			brand_name: document.getElementById( 'yp-co-brand' ).value,
			compound_strength: document.getElementById( 'yp-co-compound' ).value,
			style_notes: document.getElementById( 'yp-co-style' ).value,
			instructions: document.getElementById( 'yp-co-instructions' ).value,
			uploads: uploadedFiles.filter( function ( file ) { return file.id; } ).map( function ( file ) { return file.id; } )
		};

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

	init();
} )();
