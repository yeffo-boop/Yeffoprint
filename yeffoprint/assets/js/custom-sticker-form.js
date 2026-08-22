/**
 * Custom Stickers form (docs/ARCHITECTURE.md) — same overall shape as
 * custom-order-form.js (the Fully Custom Design flow): load options,
 * handle file upload (reusing that flow's own /custom-orders/uploads
 * endpoint — YeffoPrint_Secure_Upload doesn't care what a file is for),
 * live-preview the price, then post and hand off to checkout. Unlike
 * that flow there's no separate flat fee to show — Custom Stickers'
 * whole charge is the stickers themselves — and the Size field can
 * switch to a width/height pair when the customer picks the one tier
 * marked "custom size".
 */
( function () {
	'use strict';

	if ( typeof yeffoprintCustomSticker === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'yp-custom-sticker' );
	if ( ! root ) {
		return;
	}

	var statusEl = root.querySelector( '.yp-configurator__status' );
	var form = document.getElementById( 'yp-custom-sticker-form' );
	var typeSelect = document.getElementById( 'yp-cs-type' );
	var shapeSelect = document.getElementById( 'yp-cs-shape' );
	var sizeSelect = document.getElementById( 'yp-cs-size' );
	var customDimensionsField = root.querySelector( '[data-yp-cs-custom-dimensions]' );
	var widthInput = document.getElementById( 'yp-cs-width' );
	var heightInput = document.getElementById( 'yp-cs-height' );
	var materialSelect = document.getElementById( 'yp-cs-material' );
	var quantityContainer = root.querySelector( '[data-yp-cs-quantity]' );
	var filesInput = document.getElementById( 'yp-cs-files' );
	var fileListEl = root.querySelector( '[data-yp-cs-file-list]' );
	var submitButton = root.querySelector( '[data-yp-cs-submit]' );
	var qtyEl = root.querySelector( '[data-yp-cs-qty]' );
	var unitPriceEl = root.querySelector( '[data-yp-cs-unit-price]' );
	var subtotalEl = root.querySelector( '[data-yp-cs-subtotal]' );
	var totalEl = root.querySelector( '[data-yp-cs-total]' );

	var sizes = [];
	var quantityPresets = [];
	var quantity = 10;
	var uploadedFiles = []; // { name, id, error }
	var formErrorEl = null;
	var pricingRequestId = 0;

	function formatCurrency( amount ) {
		return '$' + amount.toFixed( 2 );
	}

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}

	function optionsHtml( entries ) {
		// entries: [{id, name}] for sizes/materials, or a plain {key: label} map for types/shapes.
		if ( Array.isArray( entries ) ) {
			return entries.map( function ( entry ) {
				return '<option value="' + entry.id + '">' + escapeHtml( entry.name ) + '</option>';
			} ).join( '' );
		}

		return Object.keys( entries ).map( function ( key ) {
			return '<option value="' + escapeHtml( key ) + '">' + escapeHtml( entries[ key ] ) + '</option>';
		} ).join( '' );
	}

	function selectedSize() {
		var id = parseInt( sizeSelect.value, 10 );
		return sizes.filter( function ( size ) { return size.id === id; } )[ 0 ] || null;
	}

	function onSizeChange() {
		var size = selectedSize();
		var isCustom = !! ( size && size.is_custom );
		customDimensionsField.hidden = ! isCustom;
		widthInput.required = isCustom;
		heightInput.required = isCustom;
		updatePricePreview();
	}

	/**
	 * A provisional preview only — same as every other price-preview in
	 * this project, the server recalculates the authoritative price at
	 * add-to-cart time (class-cart-pricing.php's apply_price()).
	 */
	function updatePricePreview() {
		var size = selectedSize();
		if ( ! size || ! typeSelect.value || ! shapeSelect.value || ! quantity ) {
			return;
		}
		if ( size.is_custom && ( ! widthInput.value || ! heightInput.value ) ) {
			return;
		}

		var requestId = ++pricingRequestId;
		var params = new URLSearchParams( {
			quantity: quantity,
			size_id: sizeSelect.value,
			material_id: materialSelect.value || '',
			sticker_type: typeSelect.value,
			shape: shapeSelect.value,
			custom_width_in: size.is_custom ? widthInput.value : '',
			custom_height_in: size.is_custom ? heightInput.value : ''
		} );

		fetch( yeffoprintCustomSticker.restUrl + 'pricing/calculate-sticker?' + params.toString() )
			.then( function ( response ) {
				return response.ok ? response.json() : Promise.reject( new Error( 'pricing-failed' ) );
			} )
			.then( function ( data ) {
				if ( requestId !== pricingRequestId ) {
					return; // A newer request has since superseded this one.
				}

				qtyEl.textContent = quantity;
				unitPriceEl.textContent = formatCurrency( data.unit_price_after_discount );
				subtotalEl.textContent = formatCurrency( data.total );
				totalEl.textContent = formatCurrency( data.total );
			} )
			.catch( function () {} );
	}

	typeSelect.addEventListener( 'change', updatePricePreview );
	shapeSelect.addEventListener( 'change', updatePricePreview );
	sizeSelect.addEventListener( 'change', onSizeChange );
	materialSelect.addEventListener( 'change', updatePricePreview );
	widthInput.addEventListener( 'input', updatePricePreview );
	heightInput.addEventListener( 'input', updatePricePreview );

	function renderQuantity() {
		quantityContainer.innerHTML = quantityPresets.map( function ( amount ) {
			return '<button type="button" class="yp-quantity-preset' + ( amount === quantity ? ' is-active' : '' ) + '" data-preset="' + amount + '">' + amount + '</button>';
		} ).join( '' ) +
			'<input type="number" min="1" id="yp-cs-quantity-input" class="yp-quantity-input" value="' + quantity + '" />';

		quantityContainer.querySelectorAll( '[data-preset]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				setQuantity( parseInt( button.getAttribute( 'data-preset' ), 10 ) );
			} );
		} );

		quantityContainer.querySelector( '#yp-cs-quantity-input' ).addEventListener( 'input', function ( event ) {
			quantity = Math.max( 1, parseInt( event.target.value, 10 ) || 1 );
			quantityContainer.querySelectorAll( '[data-preset]' ).forEach( function ( button ) {
				button.classList.toggle( 'is-active', parseInt( button.getAttribute( 'data-preset' ), 10 ) === quantity );
			} );
			updatePricePreview();
		} );
	}

	function setQuantity( amount ) {
		quantity = amount;
		quantityContainer.querySelector( '#yp-cs-quantity-input' ).value = amount;
		quantityContainer.querySelectorAll( '[data-preset]' ).forEach( function ( button ) {
			button.classList.toggle( 'is-active', parseInt( button.getAttribute( 'data-preset' ), 10 ) === amount );
		} );
		updatePricePreview();
	}

	function init() {
		fetch( yeffoprintCustomSticker.restUrl + 'custom-stickers/options' )
			.then( function ( response ) {
				return response.ok ? response.json() : Promise.reject( new Error( 'options-failed' ) );
			} )
			.then( function ( data ) {
				sizes = data.sizes || [];

				typeSelect.innerHTML = optionsHtml( data.sticker_types || {} );
				shapeSelect.innerHTML = optionsHtml( data.shapes || {} );
				sizeSelect.innerHTML = optionsHtml( sizes );
				materialSelect.innerHTML = optionsHtml( data.materials || [] );

				quantityPresets = data.quantity_presets && data.quantity_presets.length ? data.quantity_presets : [ 10 ];
				quantity = quantityPresets[ 0 ];
				renderQuantity();
				onSizeChange();

				statusEl.hidden = true;
				form.hidden = false;
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

		fetch( yeffoprintCustomSticker.restUrl + 'custom-orders/uploads', {
			method: 'POST',
			headers: { 'X-WP-Nonce': yeffoprintCustomSticker.nonce },
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

		var successfulUploads = uploadedFiles.filter( function ( file ) { return file.id; } );
		if ( ! successfulUploads.length ) {
			showFormError( 'Please upload your print-ready artwork.' );
			return;
		}

		var size = selectedSize();

		submitButton.disabled = true;

		var payload = {
			size_id: parseInt( sizeSelect.value, 10 ),
			material_id: parseInt( materialSelect.value, 10 ),
			sticker_type: typeSelect.value,
			shape: shapeSelect.value,
			custom_width_in: size && size.is_custom ? parseFloat( widthInput.value ) : 0,
			custom_height_in: size && size.is_custom ? parseFloat( heightInput.value ) : 0,
			quantity: quantity,
			instructions: document.getElementById( 'yp-cs-instructions' ).value,
			uploads: successfulUploads.map( function ( file ) { return file.id; } )
		};

		fetch( yeffoprintCustomSticker.restUrl + 'custom-stickers', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': yeffoprintCustomSticker.nonce },
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
