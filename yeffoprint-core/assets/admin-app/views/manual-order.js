/**
 * Manual order creation (docs/ARCHITECTURE.md) — staff key in a real
 * order for a customer directly from the admin app, direct request:
 * "am I able to make a custom order manually that also is with a proof
 * to be approved by the customer?", broadened to "manually create any
 * orders, not just custom orders."
 *
 * Phase A shipped Custom Design orders. Phase B added Custom Stickers —
 * no fee item, no batching (one sticker configuration per order), and
 * its own artwork upload, matching the customer-facing Custom Stickers
 * form's own shape. Phase C (this revision) adds Template Label orders —
 * a real, existing yp_template, picked the same way the Customer field
 * picks an existing customer (search-as-you-type, WP core's own
 * `/wp/v2/yp_template?search=` route — same route views/templates.js
 * already uses), then its own Size/Material + a variant batch table with
 * one column per the Template's own field_schema (fetched from the
 * public `GET /templates/{id}/configurator` endpoint, the exact same
 * data the customer-facing configurator itself loads).
 *
 * Reuses the exact same public endpoints each customer-facing form uses
 * for its own picker options (`GET /custom-orders/options`,
 * `GET /custom-stickers/options`, `GET /templates/{id}/configurator`)
 * and file uploads (`POST /custom-orders/uploads`, shared by both
 * upload-taking flows — see class-custom-sticker-controller.php's own
 * docblock for why). Custom Design's live price preview reuses its
 * public `POST /custom-orders/pricing-preview` endpoint; Custom Stickers
 * and Template Label both have no such public endpoint (a real cart
 * prices them live, which this admin screen doesn't have), so each gets
 * its own small preview wrapper (`POST /admin/manual-orders/sticker-
 * pricing-preview`, `POST /admin/manual-orders/template-pricing-preview`).
 * Order creation itself (`POST /admin/manual-orders`) is new for all three.
 *
 * Mixed orders (this revision) — direct request: "customers order
 * custom design items mixed with template items... I need the
 * ability... to order them at the same time." The three "Item types"
 * buttons above toggle independently now instead of switching between
 * mutually-exclusive tabs, so any combination's own fields panel (and
 * own price preview) can be visible and filled in at once; submit()
 * sends whichever are active as their own nested key in the request
 * body instead of one order_type picking a single shape.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	function coreEndpoint( path ) {
		return yeffoprintAdminApp.restUrl + path;
	}

	function batchRowHtml( row, options ) {
		row = row || { size_id: '', material_id: '', quantity: 100, compound_strength: '' };
		return (
			'<tr>' +
				'<td><select data-row-size>' +
					'<option value="">Choose a size…</option>' +
					options.sizes.map( function ( size ) {
						return '<option value="' + size.id + '"' + ( String( row.size_id ) === String( size.id ) ? ' selected' : '' ) + '>' + YP.escapeHtml( size.name ) + '</option>';
					} ).join( '' ) +
				'</select></td>' +
				'<td><select data-row-material>' +
					'<option value="">Choose a material…</option>' +
					options.materials.map( function ( material ) {
						return '<option value="' + material.id + '"' + ( String( row.material_id ) === String( material.id ) ? ' selected' : '' ) + ( material.in_stock ? '' : ' disabled' ) + '>' + YP.escapeHtml( material.name ) + ( material.in_stock ? '' : ' (out of stock)' ) + '</option>';
					} ).join( '' ) +
				'</select></td>' +
				'<td><input type="number" min="1" step="1" data-row-quantity value="' + YP.escapeAttr( row.quantity ) + '" /></td>' +
				'<td><input type="text" data-row-compound placeholder="e.g. 10mg/mL" value="' + YP.escapeAttr( row.compound_strength ) + '" /></td>' +
				'<td><button type="button" class="yp-row-action" data-yp-remove-row aria-label="Remove label">&times;</button></td>' +
			'</tr>'
		);
	}

	function wireRemoveButtons( tbody, onChange ) {
		tbody.querySelectorAll( '[data-yp-remove-row]' ).forEach( function ( button ) {
			if ( button._wired ) {
				return;
			}
			button._wired = true;
			button.addEventListener( 'click', function () {
				// Always leave at least one row — an empty batch has
				// nothing for the price preview/submit to work with.
				if ( tbody.querySelectorAll( 'tr' ).length > 1 ) {
					button.closest( 'tr' ).remove();
					onChange();
				}
			} );
		} );
	}

	function readBatchRows( tbody ) {
		return Array.prototype.map.call( tbody.querySelectorAll( 'tr' ), function ( row ) {
			return {
				size_id: parseInt( row.querySelector( '[data-row-size]' ).value, 10 ) || 0,
				material_id: parseInt( row.querySelector( '[data-row-material]' ).value, 10 ) || 0,
				quantity: parseInt( row.querySelector( '[data-row-quantity]' ).value, 10 ) || 0,
				compound_strength: row.querySelector( '[data-row-compound]' ).value
			};
		} );
	}

	// Direct request: "customers order custom design items mixed with
	// template items... I need the ability... to order them at the same
	// time." Every button here used to be a radio — exactly one active,
	// switching state.orderType — now each toggles independently in
	// state.activeTypes, and render() stacks every active type's own
	// fields panel instead of picking just one.
	var ORDER_TYPE_LABELS = {
		custom_design: 'Custom Design',
		sticker: 'Custom Sticker',
		template: 'Template Label'
	};

	YP.views[ 'manual-order' ] = function ( viewEl ) {
		var emptyAddress = function () {
			return { first_name: '', last_name: '', address_1: '', address_2: '', city: '', state: '', postcode: '', country: 'US', phone: '' };
		};

		var state = {
			activeTypes: { custom_design: true, sticker: false, template: false },
			options: null, // custom-orders/options — Custom Design's own sizes/materials.
			stickerOptions: null, // custom-stickers/options — Custom Stickers' own sizes/materials/types/shapes.
			stickerUploads: [], // [{ name, id, error }] — same shape as the customer-facing form's own uploadedFiles.
			selectedCustomer: null, // { id, display_name, email }
			newCustomerMode: false,
			selectedTemplate: null, // { id, title } — picked from search, before its configurator data has loaded.
			templateData: null, // GET /templates/{id}/configurator response — { field_schema, sizes, materials } — null until selectedTemplate's data has loaded.
			// Shared by every order type (like the Customer picker above), so
			// this lives in its own top-level state slice rather than reset
			// by render()'s order-type switch the way batch/sticker/template
			// fields are — a customer's address doesn't change based on what
			// they're ordering. Every field write goes straight into this
			// object (see bindShippingPanel()) so it survives render()
			// rebuilding the panel's HTML from scratch on every order-type click.
			shipping: {
				address: emptyAddress(),
				billingDiffers: false,
				billingAddress: emptyAddress(),
				verifying: false,
				verifyResult: null, // { is_valid, messages } from the last /verify-address call.
				selectedOptionIndex: '' // Index into yeffoprintAdminApp.shippo.manualOrderShippingOptions, as a string (matches a <select>'s own value type) — '' means "no shipping charge."
			}
		};

		viewEl.innerHTML = '<p class="yp-app__intro">Loading&hellip;</p>';

		Promise.all( [
			YP.request( coreEndpoint( 'custom-orders/options' ) ),
			YP.request( coreEndpoint( 'custom-stickers/options' ) )
		] )
			.then( function ( results ) {
				state.options = results[ 0 ];
				state.stickerOptions = results[ 1 ];
				render();
			} )
			.catch( function ( error ) {
				viewEl.innerHTML = '<p class="yp-form__error">Couldn’t load this screen: ' + YP.escapeHtml( error.message ) + '</p>';
			} );

		function render() {
			var typeButtonsHtml = Object.keys( ORDER_TYPE_LABELS ).map( function ( type ) {
				return '<button type="button" class="wp-block-button__link ' + ( state.activeTypes[ type ] ? 'is-style-accent' : 'is-style-outline' ) + '" data-yp-order-type="' + type + '">' + ORDER_TYPE_LABELS[ type ] + '</button>';
			} ).join( '' );

			viewEl.innerHTML =
				'<p class="yp-app__intro">Key in an order for a customer over the phone or by email — same pricing and options as the storefront. Toggle on more than one item type below to combine them on the same order.</p>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Item types</h2></div>' +
					'<div class="yp-form__actions">' + typeButtonsHtml + '</div>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Customer</h2></div>' +
					'<div data-yp-customer-picker></div>' +
				'</div>' +

				shippingPanelHtml() +

				( state.activeTypes.custom_design ? customDesignFieldsHtml() : '' ) +
				( state.activeTypes.sticker ? stickerFieldsHtml() : '' ) +
				( state.activeTypes.template ? templateFieldsHtml() : '' ) +

				'<div class="yp-panel">' +
					'<div class="yp-field yp-field--checkbox">' +
						'<input type="checkbox" id="yp-mo-requires-proof" checked />' +
						'<label for="yp-mo-requires-proof">Requires proof approval before printing</label>' +
					'</div>' +
					'<p class="yp-panel__hint">When checked, the customer gets a proof-approval link once staff upload a proof from the Custom Orders screen — same flow as an order placed on the storefront. Each item type above gets its own proof to approve.</p>' +
					( state.activeTypes.custom_design ?
						'<div class="yp-field yp-field--checkbox">' +
							'<input type="checkbox" id="yp-mo-waive-fee" />' +
							'<label for="yp-mo-waive-fee">Waive the ' + ( state.options && state.options.design_fee ? state.options.design_fee : 'design' ) + ' fee</label>' +
						'</div>' +
						'<p class="yp-panel__hint">No design-fee line item gets added to the order — for a VIP customer or as goodwill. The customer still pays for the print run itself.</p>'
						: '' ) +
					'<div class="yp-field yp-field--checkbox">' +
						'<input type="checkbox" id="yp-mo-send-invoice" checked />' +
						'<label for="yp-mo-send-invoice">Email the customer their order details and a payment link</label>' +
					'</div>' +
					'<p class="yp-panel__hint">Sent right away via WooCommerce’s own Order details email, with the order’s real payment link — skip this if you’re taking payment another way (over the phone, in person) instead.</p>' +
				'</div>' +

				'<div data-yp-submit-status></div>' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-submit>Create Order</button>';

			renderCustomerPicker();
			bindShippingPanel();
			// render() rebuilds the shipping panel's HTML from scratch (e.g.
			// on every item-type toggle) with an empty verify-result
			// container — state.shipping itself survives that rebuild (see
			// its own docblock above; the shipping-method <select> reads its
			// own selected option straight from that state when the markup
			// is built, so only the verify result needs playing back here).
			renderVerifyResult();

			viewEl.querySelectorAll( '[data-yp-order-type]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var type = button.getAttribute( 'data-yp-order-type' );
					var activeCount = Object.keys( state.activeTypes ).filter( function ( t ) { return state.activeTypes[ t ]; } ).length;
					// Always leave at least one type active — an empty
					// order has nothing for pricing/submit to work with,
					// same "never remove the last one" rule batch/variant
					// rows already enforce (wireRemoveButtons() above).
					if ( state.activeTypes[ type ] && activeCount <= 1 ) {
						return;
					}
					state.activeTypes[ type ] = ! state.activeTypes[ type ];
					render();
				} );
			} );

			if ( state.activeTypes.custom_design ) {
				var batchBody = viewEl.querySelector( '[data-yp-batch]' );
				wireRemoveButtons( batchBody, refreshPricePreview );

				viewEl.querySelector( '[data-yp-add-row]' ).addEventListener( 'click', function () {
					batchBody.insertAdjacentHTML( 'beforeend', batchRowHtml( null, state.options ) );
					wireRemoveButtons( batchBody, refreshPricePreview );
					bindBatchChangeListeners();
					refreshPricePreview();
				} );

				bindBatchChangeListeners();
				refreshPricePreview();

				var waiveFeeToggle = viewEl.querySelector( '#yp-mo-waive-fee' );
				if ( waiveFeeToggle && ! waiveFeeToggle._wired ) {
					waiveFeeToggle._wired = true;
					waiveFeeToggle.addEventListener( 'change', refreshPricePreview );
				}
			}

			if ( state.activeTypes.sticker ) {
				bindStickerChangeListeners();
				wireStickerUploads();
				renderStickerFileList();
				toggleStickerCustomDimensions();
				refreshStickerPricePreview();
			}

			if ( state.activeTypes.template ) {
				renderTemplatePanel();
				if ( state.templateData ) {
					bindTemplateVariantListeners();
					refreshTemplatePricePreview();
				}
			}

			viewEl.querySelector( '[data-yp-submit]' ).addEventListener( 'click', submit );
		}

		/* ---------- Custom Design (Phase A) ---------- */

		function customDesignFieldsHtml() {
			return (
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Custom Design details</h2></div>' +
					'<div class="yp-field"><label for="yp-mo-brand">Brand name</label><input type="text" id="yp-mo-brand" /></div>' +
					'<table class="yp-tier-table"><thead><tr><th>Size</th><th>Material</th><th>Quantity</th><th>Compound/Strength</th><th></th></tr></thead>' +
						'<tbody data-yp-batch>' + batchRowHtml( null, state.options ) + '</tbody>' +
					'</table>' +
					'<button type="button" class="wp-block-button__link is-style-outline" data-yp-add-row>+ Add another label</button>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-mo-style-notes">Style notes</label><textarea id="yp-mo-style-notes" rows="2"></textarea></div>' +
						'<div class="yp-field"><label for="yp-mo-instructions">Instructions</label><textarea id="yp-mo-instructions" rows="2"></textarea></div>' +
					'</div>' +
					'<div data-yp-price-preview="custom_design"><p class="yp-field__hint">Add a label to see pricing.</p></div>' +
				'</div>'
			);
		}

		function bindBatchChangeListeners() {
			viewEl.querySelectorAll( '[data-yp-batch] input, [data-yp-batch] select' ).forEach( function ( field ) {
				if ( field._wired ) {
					return;
				}
				field._wired = true;
				field.addEventListener( 'change', refreshPricePreview );
			} );
		}

		var previewTimer = null;
		function refreshPricePreview() {
			clearTimeout( previewTimer );
			previewTimer = setTimeout( doRefreshPricePreview, 300 );
		}

		function doRefreshPricePreview() {
			var previewEl = viewEl.querySelector( '[data-yp-price-preview="custom_design"]' );
			if ( ! previewEl ) {
				return; // Navigated away.
			}

			var batchBody = viewEl.querySelector( '[data-yp-batch]' );
			var batch = readBatchRows( batchBody ).filter( function ( row ) {
				return row.size_id && row.material_id && row.quantity > 0;
			} );

			if ( ! batch.length ) {
				previewEl.innerHTML = '<p class="yp-field__hint">Add a label to see pricing.</p>';
				return;
			}

			// 'own_design' is the existing mode this same endpoint already
			// uses for a customer-provided design — no design-fee product,
			// so it's the exact shape needed for "staff waived it" too:
			// the preview shouldn't show a fee that's about to be left off
			// the actual order.
			var waiveFeeEl = viewEl.querySelector( '#yp-mo-waive-fee' );
			var mode = waiveFeeEl && waiveFeeEl.checked ? 'own_design' : 'new_design';

			YP.request( coreEndpoint( 'custom-orders/pricing-preview' ), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { mode: mode, batch: batch } )
			} )
				.then( function ( pricing ) {
					previewEl.innerHTML =
						'<p>Labels: $' + pricing.labels_subtotal.toFixed( 2 ) + ' + Design fee: $' + pricing.design_fee.toFixed( 2 ) + '</p>' +
						'<p><strong>Total: $' + pricing.total.toFixed( 2 ) + '</strong></p>';
				} )
				.catch( function ( error ) {
					previewEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		/* ---------- Custom Stickers (Phase B) ---------- */

		function stickerSizeById( id ) {
			return state.stickerOptions.sizes.filter( function ( size ) {
				return String( size.id ) === String( id );
			} )[ 0 ] || null;
		}

		function stickerFieldsHtml() {
			var o = state.stickerOptions;
			return (
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Custom Sticker details</h2></div>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-mo-sticker-size">Size</label><select id="yp-mo-sticker-size">' +
							'<option value="">Choose a size…</option>' +
							o.sizes.map( function ( size ) {
								return '<option value="' + size.id + '">' + YP.escapeHtml( size.name ) + '</option>';
							} ).join( '' ) +
						'</select></div>' +
						'<div class="yp-field"><label for="yp-mo-sticker-material">Material</label><select id="yp-mo-sticker-material">' +
							'<option value="">Choose a material…</option>' +
							o.materials.map( function ( material ) {
								return '<option value="' + material.id + '"' + ( material.in_stock ? '' : ' disabled' ) + '>' + YP.escapeHtml( material.name ) + ( material.in_stock ? '' : ' (out of stock)' ) + '</option>';
							} ).join( '' ) +
						'</select></div>' +
					'</div>' +
					'<div class="yp-form__row" data-yp-sticker-custom-dims style="display:none;">' +
						'<div class="yp-field"><label for="yp-mo-sticker-width">Width (in)</label><input type="number" min="0.1" step="0.01" id="yp-mo-sticker-width" /></div>' +
						'<div class="yp-field"><label for="yp-mo-sticker-height">Height (in)</label><input type="number" min="0.1" step="0.01" id="yp-mo-sticker-height" /></div>' +
					'</div>' +
					'<div class="yp-form__row--three">' +
						'<div class="yp-field"><label for="yp-mo-sticker-type">Type</label><select id="yp-mo-sticker-type">' +
							'<option value="">Choose a type…</option>' +
							Object.keys( o.sticker_types ).map( function ( key ) {
								return '<option value="' + key + '">' + YP.escapeHtml( o.sticker_types[ key ] ) + '</option>';
							} ).join( '' ) +
						'</select></div>' +
						'<div class="yp-field"><label for="yp-mo-sticker-shape">Shape</label><select id="yp-mo-sticker-shape">' +
							'<option value="">Choose a shape…</option>' +
							Object.keys( o.shapes ).map( function ( key ) {
								return '<option value="' + key + '">' + YP.escapeHtml( o.shapes[ key ] ) + '</option>';
							} ).join( '' ) +
						'</select></div>' +
						'<div class="yp-field"><label for="yp-mo-sticker-quantity">Quantity</label><input type="number" min="1" step="1" id="yp-mo-sticker-quantity" value="100" /></div>' +
					'</div>' +
					'<div class="yp-field"><label for="yp-mo-sticker-instructions">Instructions</label><textarea id="yp-mo-sticker-instructions" rows="2"></textarea></div>' +
					'<div class="yp-field">' +
						'<label for="yp-mo-sticker-files">Artwork (optional — can be sent separately and attached later)</label>' +
						'<input type="file" id="yp-mo-sticker-files" multiple />' +
						'<ul data-yp-sticker-file-list></ul>' +
					'</div>' +
					'<div data-yp-price-preview="sticker"><p class="yp-field__hint">Choose a size, material, type, and shape to see pricing.</p></div>' +
				'</div>'
			);
		}

		function toggleStickerCustomDimensions() {
			var sizeSelect = viewEl.querySelector( '#yp-mo-sticker-size' );
			var dimsRow    = viewEl.querySelector( '[data-yp-sticker-custom-dims]' );
			if ( ! sizeSelect || ! dimsRow ) {
				return;
			}
			var size = stickerSizeById( sizeSelect.value );
			dimsRow.style.display = ( size && size.is_custom ) ? '' : 'none';
		}

		function bindStickerChangeListeners() {
			var ids = [ 'yp-mo-sticker-size', 'yp-mo-sticker-material', 'yp-mo-sticker-type', 'yp-mo-sticker-shape', 'yp-mo-sticker-quantity', 'yp-mo-sticker-width', 'yp-mo-sticker-height' ];
			ids.forEach( function ( id ) {
				var field = viewEl.querySelector( '#' + id );
				if ( ! field || field._wired ) {
					return;
				}
				field._wired = true;
				field.addEventListener( 'change', function () {
					if ( 'yp-mo-sticker-size' === id ) {
						toggleStickerCustomDimensions();
					}
					refreshStickerPricePreview();
				} );
			} );
		}

		var stickerPreviewTimer = null;
		function refreshStickerPricePreview() {
			clearTimeout( stickerPreviewTimer );
			stickerPreviewTimer = setTimeout( doRefreshStickerPricePreview, 300 );
		}

		function readStickerFields() {
			var size = viewEl.querySelector( '#yp-mo-sticker-size' );
			return {
				size_id: size ? parseInt( size.value, 10 ) || 0 : 0,
				material_id: parseInt( ( viewEl.querySelector( '#yp-mo-sticker-material' ) || {} ).value, 10 ) || 0,
				sticker_type: ( viewEl.querySelector( '#yp-mo-sticker-type' ) || {} ).value || '',
				shape: ( viewEl.querySelector( '#yp-mo-sticker-shape' ) || {} ).value || '',
				quantity: parseInt( ( viewEl.querySelector( '#yp-mo-sticker-quantity' ) || {} ).value, 10 ) || 0,
				custom_width_in: parseFloat( ( viewEl.querySelector( '#yp-mo-sticker-width' ) || {} ).value ) || 0,
				custom_height_in: parseFloat( ( viewEl.querySelector( '#yp-mo-sticker-height' ) || {} ).value ) || 0
			};
		}

		function doRefreshStickerPricePreview() {
			var previewEl = viewEl.querySelector( '[data-yp-price-preview="sticker"]' );
			if ( ! previewEl ) {
				return; // Navigated away.
			}

			var fields = readStickerFields();
			var size   = stickerSizeById( fields.size_id );
			var dimsOk = ! size || ! size.is_custom || ( fields.custom_width_in > 0 && fields.custom_height_in > 0 );

			if ( ! fields.size_id || ! fields.material_id || ! fields.sticker_type || ! fields.shape || fields.quantity < 1 || ! dimsOk ) {
				previewEl.innerHTML = '<p class="yp-field__hint">Choose a size, material, type, and shape to see pricing.</p>';
				return;
			}

			YP.request( coreEndpoint( 'admin/manual-orders/sticker-pricing-preview' ), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( fields )
			} )
				.then( function ( pricing ) {
					previewEl.innerHTML = '<p><strong>Total: $' + pricing.total.toFixed( 2 ) + '</strong></p>';
				} )
				.catch( function ( error ) {
					previewEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		function renderStickerFileList() {
			var listEl = viewEl.querySelector( '[data-yp-sticker-file-list]' );
			if ( ! listEl ) {
				return;
			}
			listEl.innerHTML = state.stickerUploads.map( function ( file, index ) {
				var status = file.error
					? '<span class="yp-form__error">' + YP.escapeHtml( file.error ) + '</span>'
					: ( file.id ? '<span>Uploaded</span>' : '<span>Uploading&hellip;</span>' );
				return '<li>' + YP.escapeHtml( file.name ) + ' — ' + status +
					' <button type="button" class="yp-row-action" data-yp-remove-sticker-file="' + index + '">Remove</button></li>';
			} ).join( '' );

			listEl.querySelectorAll( '[data-yp-remove-sticker-file]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					state.stickerUploads.splice( parseInt( button.getAttribute( 'data-yp-remove-sticker-file' ), 10 ), 1 );
					renderStickerFileList();
				} );
			} );
		}

		function wireStickerUploads() {
			var filesInput = viewEl.querySelector( '#yp-mo-sticker-files' );
			if ( ! filesInput || filesInput._wired ) {
				return;
			}
			filesInput._wired = true;

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
				state.stickerUploads = state.stickerUploads.concat( placeholders );
				renderStickerFileList();

				YP.request( coreEndpoint( 'custom-orders/uploads' ), {
					method: 'POST',
					body: formData
				} )
					.then( function ( data ) {
						( data.files || [] ).forEach( function ( result, i ) {
							var entry = state.stickerUploads.indexOf( placeholders[ i ] );
							if ( entry === -1 ) {
								return;
							}
							if ( result.success ) {
								state.stickerUploads[ entry ].id = result.id;
							} else {
								state.stickerUploads[ entry ].error = result.message;
							}
						} );
						renderStickerFileList();
					} )
					.catch( function () {
						placeholders.forEach( function ( placeholder ) {
							var entry = state.stickerUploads.indexOf( placeholder );
							if ( entry !== -1 ) {
								state.stickerUploads[ entry ].error = 'Upload failed.';
							}
						} );
						renderStickerFileList();
					} );
			} );
		}

		/* ---------- Template Label (Phase C) ---------- */

		function templateFieldsHtml() {
			return (
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Template Label details</h2></div>' +
					'<div data-yp-template-panel></div>' +
					'<div data-yp-price-preview="template"><p class="yp-field__hint">Choose a design to see pricing.</p></div>' +
				'</div>'
			);
		}

		function templateFieldInputHtml( field, value ) {
			value = value || '';
			var attr = ' data-field-id="' + YP.escapeAttr( field.id ) + '"';

			if ( 'color' === field.type ) {
				return '<input type="color"' + attr + ' value="' + YP.escapeAttr( value || '#000000' ) + '" />';
			}
			if ( 'qr_code' === field.type ) {
				return '<input type="url" placeholder="https://"' + attr + ' maxlength="' + field.max_chars + '" value="' + YP.escapeAttr( value ) + '" />';
			}
			if ( 'textarea' === field.type ) {
				return '<textarea' + attr + ' maxlength="' + field.max_chars + '">' + YP.escapeHtml( value ) + '</textarea>';
			}
			if ( 'corner_style' === field.type ) {
				var options = yeffoprintAdminApp.fieldSchema.cornerStyleOptions || {};
				return '<select' + attr + '>' + Object.keys( options ).map( function ( key ) {
					return '<option value="' + YP.escapeAttr( key ) + '"' + ( key === value ? ' selected' : '' ) + '>' + YP.escapeHtml( options[ key ] ) + '</option>';
				} ).join( '' ) + '</select>';
			}
			return '<input type="text"' + attr + ' maxlength="' + field.max_chars + '" value="' + YP.escapeAttr( value ) + '" />';
		}

		function templateVariantRowHtml( variant, fieldSchema ) {
			variant = variant || { quantity: 100, values: {} };
			return (
				'<tr>' +
					'<td><input type="number" min="1" step="1" data-row-quantity value="' + YP.escapeAttr( variant.quantity ) + '" /></td>' +
					fieldSchema.map( function ( field ) {
						return '<td>' + templateFieldInputHtml( field, variant.values[ field.id ] ) + '</td>';
					} ).join( '' ) +
					'<td><button type="button" class="yp-row-action" data-yp-remove-row aria-label="Remove label">&times;</button></td>' +
				'</tr>'
			);
		}

		function readTemplateVariants( tbody, fieldSchema ) {
			return Array.prototype.map.call( tbody.querySelectorAll( 'tr' ), function ( row ) {
				var values = {};
				fieldSchema.forEach( function ( field ) {
					var input = row.querySelector( '[data-field-id="' + field.id + '"]' );
					values[ field.id ] = input ? input.value : '';
				} );
				return {
					quantity: parseInt( row.querySelector( '[data-row-quantity]' ).value, 10 ) || 0,
					values: values
				};
			} );
		}

		function bindTemplateVariantListeners() {
			var tbody = viewEl.querySelector( '[data-yp-template-variants]' );
			if ( tbody ) {
				tbody.querySelectorAll( 'input, textarea' ).forEach( function ( field ) {
					if ( field._wired ) {
						return;
					}
					field._wired = true;
					field.addEventListener( 'change', refreshTemplatePricePreview );
				} );
			}

			[ viewEl.querySelector( '#yp-mo-template-size' ), viewEl.querySelector( '#yp-mo-template-material' ) ].forEach( function ( field ) {
				if ( ! field || field._wired ) {
					return;
				}
				field._wired = true;
				field.addEventListener( 'change', refreshTemplatePricePreview );
			} );
		}

		function pickTemplate( id, title ) {
			state.selectedTemplate = { id: id, title: title };
			state.templateData = null;
			renderTemplatePanel();
			refreshTemplatePricePreview();

			YP.request( coreEndpoint( 'templates/' + id + '/configurator' ) )
				.then( function ( data ) {
					state.templateData = data;
					renderTemplatePanel();
					bindTemplateVariantListeners();
					refreshTemplatePricePreview();
				} )
				.catch( function ( error ) {
					var el = viewEl.querySelector( '[data-yp-template-panel]' );
					if ( el ) {
						el.innerHTML = '<p class="yp-form__error">Couldn’t load this design: ' + YP.escapeHtml( error.message ) + '</p>';
					}
				} );
		}

		function renderTemplatePanel() {
			var el = viewEl.querySelector( '[data-yp-template-panel]' );
			if ( ! el ) {
				return; // Navigated away.
			}

			if ( state.templateData ) {
				renderTemplateDetails( el );
				return;
			}

			if ( state.selectedTemplate ) {
				el.innerHTML = '<p class="yp-field__hint">Loading design details&hellip;</p>';
				return;
			}

			el.innerHTML =
				'<div class="yp-field"><label for="yp-mo-template-search">Search by design name</label><input type="text" id="yp-mo-template-search" autocomplete="off" /></div>' +
				'<ul data-yp-template-results></ul>';

			var searchInput = el.querySelector( '#yp-mo-template-search' );
			var resultsEl   = el.querySelector( '[data-yp-template-results]' );
			var searchTimer = null;

			searchInput.addEventListener( 'input', function () {
				clearTimeout( searchTimer );
				var term = searchInput.value.trim();
				if ( ! term ) {
					resultsEl.innerHTML = '';
					return;
				}
				searchTimer = setTimeout( function () {
					YP.request( yeffoprintAdminApp.wpApiUrl + 'yp_template?search=' + encodeURIComponent( term ) + '&status=publish&per_page=20&orderby=title&order=asc' )
						.then( function ( results ) { renderTemplateResults( results, resultsEl ); } )
						.catch( function () { resultsEl.innerHTML = ''; } );
				}, 300 );
			} );

			function renderTemplateResults( results, resultsEl ) {
				if ( ! results.length ) {
					resultsEl.innerHTML = '<li class="yp-field__hint">No matches.</li>';
					return;
				}
				resultsEl.innerHTML = results.map( function ( post ) {
					return '<li><button type="button" class="yp-row-action" data-yp-pick-template="' + post.id + '">' + YP.escapeHtml( post.title.rendered ) + '</button></li>';
				} ).join( '' );

				resultsEl.querySelectorAll( '[data-yp-pick-template]' ).forEach( function ( button, index ) {
					button.addEventListener( 'click', function () {
						pickTemplate( results[ index ].id, results[ index ].title.rendered );
					} );
				} );
			}
		}

		function renderTemplateDetails( el ) {
			var data = state.templateData;

			el.innerHTML =
				'<p><span class="yp-chip">' + YP.escapeHtml( data.title ) + '</span> ' +
				'<button type="button" class="yp-row-action" data-yp-change-template>Change</button></p>' +
				'<div class="yp-form__row">' +
					'<div class="yp-field"><label for="yp-mo-template-size">Size</label><select id="yp-mo-template-size">' +
						'<option value="">Choose a size…</option>' +
						data.sizes.map( function ( size ) {
							return '<option value="' + size.id + '">' + YP.escapeHtml( size.name ) + '</option>';
						} ).join( '' ) +
					'</select></div>' +
					'<div class="yp-field"><label for="yp-mo-template-material">Material</label><select id="yp-mo-template-material">' +
						'<option value="">Choose a material…</option>' +
						data.materials.map( function ( material ) {
							return '<option value="' + material.id + '"' + ( material.in_stock ? '' : ' disabled' ) + '>' + YP.escapeHtml( material.name ) + ( material.in_stock ? '' : ' (out of stock)' ) + '</option>';
						} ).join( '' ) +
					'</select></div>' +
				'</div>' +
				'<table class="yp-tier-table"><thead><tr><th>Quantity</th>' +
					data.field_schema.map( function ( field ) { return '<th>' + YP.escapeHtml( field.label ) + '</th>'; } ).join( '' ) +
					'<th></th></tr></thead>' +
					'<tbody data-yp-template-variants>' + templateVariantRowHtml( null, data.field_schema ) + '</tbody>' +
				'</table>' +
				'<button type="button" class="wp-block-button__link is-style-outline" data-yp-add-template-row>+ Add another label</button>' +
				'<div class="yp-field"><label for="yp-mo-template-instructions">Instructions</label><textarea id="yp-mo-template-instructions" rows="2"></textarea></div>';

			el.querySelector( '[data-yp-change-template]' ).addEventListener( 'click', function () {
				state.selectedTemplate = null;
				state.templateData = null;
				renderTemplatePanel();
				refreshTemplatePricePreview();
			} );

			var tbody = el.querySelector( '[data-yp-template-variants]' );
			wireRemoveButtons( tbody, refreshTemplatePricePreview );

			el.querySelector( '[data-yp-add-template-row]' ).addEventListener( 'click', function () {
				tbody.insertAdjacentHTML( 'beforeend', templateVariantRowHtml( null, data.field_schema ) );
				wireRemoveButtons( tbody, refreshTemplatePricePreview );
				bindTemplateVariantListeners();
				refreshTemplatePricePreview();
			} );

			bindTemplateVariantListeners();
		}

		var templatePreviewTimer = null;
		function refreshTemplatePricePreview() {
			clearTimeout( templatePreviewTimer );
			templatePreviewTimer = setTimeout( doRefreshTemplatePricePreview, 300 );
		}

		function doRefreshTemplatePricePreview() {
			var previewEl = viewEl.querySelector( '[data-yp-price-preview="template"]' );
			if ( ! previewEl ) {
				return; // Navigated away.
			}

			if ( ! state.templateData ) {
				previewEl.innerHTML = '<p class="yp-field__hint">Choose a design to see pricing.</p>';
				return;
			}

			var sizeSelect     = viewEl.querySelector( '#yp-mo-template-size' );
			var materialSelect = viewEl.querySelector( '#yp-mo-template-material' );
			var tbody          = viewEl.querySelector( '[data-yp-template-variants]' );
			var variants       = tbody ? readTemplateVariants( tbody, state.templateData.field_schema ) : [];
			var quantity       = variants.reduce( function ( sum, v ) { return sum + v.quantity; }, 0 );
			var sizeId         = sizeSelect ? parseInt( sizeSelect.value, 10 ) || 0 : 0;
			var materialId     = materialSelect ? parseInt( materialSelect.value, 10 ) || 0 : 0;

			if ( ! sizeId || ! materialId || quantity < 1 ) {
				previewEl.innerHTML = '<p class="yp-field__hint">Choose a size, material, and quantity to see pricing.</p>';
				return;
			}

			YP.request( coreEndpoint( 'admin/manual-orders/template-pricing-preview' ), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { size_id: sizeId, material_id: materialId, quantity: quantity } )
			} )
				.then( function ( pricing ) {
					previewEl.innerHTML = '<p><strong>Total: $' + pricing.total.toFixed( 2 ) + '</strong></p>';
				} )
				.catch( function ( error ) {
					previewEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		/* ---------- Customer picker (shared by every order type) ---------- */

		function renderCustomerPicker() {
			var el = viewEl.querySelector( '[data-yp-customer-picker]' );

			if ( state.selectedCustomer ) {
				el.innerHTML =
					'<p><span class="yp-chip">' + YP.escapeHtml( state.selectedCustomer.display_name || state.selectedCustomer.email ) + ' (' + YP.escapeHtml( state.selectedCustomer.email ) + ')</span> ' +
					'<button type="button" class="yp-row-action" data-yp-change-customer>Change</button></p>';
				el.querySelector( '[data-yp-change-customer]' ).addEventListener( 'click', function () {
					state.selectedCustomer = null;
					renderCustomerPicker();
				} );
				return;
			}

			if ( state.newCustomerMode ) {
				el.innerHTML =
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-mo-new-name">Name</label><input type="text" id="yp-mo-new-name" /></div>' +
						'<div class="yp-field"><label for="yp-mo-new-email">Email</label><input type="email" id="yp-mo-new-email" /></div>' +
					'</div>' +
					'<button type="button" class="yp-row-action" data-yp-search-instead>Search for an existing customer instead</button>';
				el.querySelector( '[data-yp-search-instead]' ).addEventListener( 'click', function () {
					state.newCustomerMode = false;
					renderCustomerPicker();
				} );
				return;
			}

			el.innerHTML =
				'<div class="yp-field"><label for="yp-mo-customer-search">Search by name or email</label><input type="text" id="yp-mo-customer-search" autocomplete="off" /></div>' +
				'<ul data-yp-customer-results></ul>' +
				'<button type="button" class="yp-row-action" data-yp-new-customer>+ New customer instead</button>';

			el.querySelector( '[data-yp-new-customer]' ).addEventListener( 'click', function () {
				state.newCustomerMode = true;
				renderCustomerPicker();
			} );

			var searchInput = el.querySelector( '#yp-mo-customer-search' );
			var resultsEl   = el.querySelector( '[data-yp-customer-results]' );
			var searchTimer = null;

			searchInput.addEventListener( 'input', function () {
				clearTimeout( searchTimer );
				var term = searchInput.value.trim();
				if ( ! term ) {
					resultsEl.innerHTML = '';
					return;
				}
				searchTimer = setTimeout( function () {
					YP.request( coreEndpoint( 'admin/manual-orders/customer-search' ) + '?q=' + encodeURIComponent( term ) )
						.then( function ( results ) { renderCustomerResults( results, resultsEl ); } )
						.catch( function () { resultsEl.innerHTML = ''; } );
				}, 300 );
			} );

			function renderCustomerResults( results, resultsEl ) {
				if ( ! results.length ) {
					resultsEl.innerHTML = '<li class="yp-field__hint">No matches.</li>';
					return;
				}
				resultsEl.innerHTML = results.map( function ( user ) {
					return '<li><button type="button" class="yp-row-action" data-yp-pick-customer="' + user.id + '">' + YP.escapeHtml( user.display_name ) + ' (' + YP.escapeHtml( user.email ) + ')</button></li>';
				} ).join( '' );

				resultsEl.querySelectorAll( '[data-yp-pick-customer]' ).forEach( function ( button, index ) {
					button.addEventListener( 'click', function () {
						state.selectedCustomer = results[ index ];
						renderCustomerPicker();
						prefillAddressFromCustomer( results[ index ].id );
					} );
				} );
			}
		}

		function customerPayload() {
			if ( state.selectedCustomer ) {
				return { id: state.selectedCustomer.id };
			}
			return {
				name: viewEl.querySelector( '#yp-mo-new-name' ) ? viewEl.querySelector( '#yp-mo-new-name' ).value : '',
				email: viewEl.querySelector( '#yp-mo-new-email' ) ? viewEl.querySelector( '#yp-mo-new-email' ).value : ''
			};
		}

		/* ---------- Shipping & Billing address, shipping method ----------
		 * Direct request: "I need the ability to verify the shipping/billing
		 * address for the customer before finalizing, also need to be able
		 * to select a shipping method so shipping can be added to the
		 * invoice." Address verification goes through class-admin-manual-
		 * order-controller.php's own /verify-address route — no order
		 * exists yet at this point, unlike the order-detail screen's own
		 * Shippo panel (app.js's shippoPanelHtml()), which reads an
		 * already-saved order address.
		 *
		 * The shipping-method half originally rate-shopped live through
		 * Shippo here too, mirroring that same order-detail panel — direct
		 * follow-up request to simplify it: "I don't need to rate shop to
		 * add shipping, just use my default shipping options." It's now a
		 * plain <select> built from yeffoprintAdminApp.shippo.
		 * manualOrderShippingOptions (Settings → Shipping → "Manual order
		 * shipping options" — a flat {label, amount} list, edited there,
		 * not a live API call), so it works whether or not Shippo itself is
		 * configured. Selecting one still only adds its cost to the invoice
		 * as a real shipping line item — purchasing an actual label still
		 * happens from the order-detail Shippo panel once the order exists,
		 * same as any other order.
		 */

		function addressFieldsHtml( prefix, address ) {
			// Direct report: these field labels "look like they belong to
			// the field above" — wrapped in .yp-address-fields (records.css)
			// so consecutive rows/fields get real spacing between them; see
			// that rule's own comment for why the gap wasn't there before.
			return (
				'<div class="yp-address-fields">' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-mo-' + prefix + '-first-name">First name</label><input type="text" id="yp-mo-' + prefix + '-first-name" data-yp-address-field="first_name" value="' + YP.escapeAttr( address.first_name ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-mo-' + prefix + '-last-name">Last name</label><input type="text" id="yp-mo-' + prefix + '-last-name" data-yp-address-field="last_name" value="' + YP.escapeAttr( address.last_name ) + '" /></div>' +
					'</div>' +
					'<div class="yp-field"><label for="yp-mo-' + prefix + '-address-1">Address line 1</label><input type="text" id="yp-mo-' + prefix + '-address-1" data-yp-address-field="address_1" value="' + YP.escapeAttr( address.address_1 ) + '" /></div>' +
					'<div class="yp-field"><label for="yp-mo-' + prefix + '-address-2">Address line 2</label><input type="text" id="yp-mo-' + prefix + '-address-2" data-yp-address-field="address_2" value="' + YP.escapeAttr( address.address_2 ) + '" /></div>' +
					'<div class="yp-form__row--three">' +
						'<div class="yp-field"><label for="yp-mo-' + prefix + '-city">City</label><input type="text" id="yp-mo-' + prefix + '-city" data-yp-address-field="city" value="' + YP.escapeAttr( address.city ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-mo-' + prefix + '-state">State</label><input type="text" id="yp-mo-' + prefix + '-state" data-yp-address-field="state" value="' + YP.escapeAttr( address.state ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-mo-' + prefix + '-postcode">ZIP / postal code</label><input type="text" id="yp-mo-' + prefix + '-postcode" data-yp-address-field="postcode" value="' + YP.escapeAttr( address.postcode ) + '" /></div>' +
					'</div>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-mo-' + prefix + '-country">Country</label><input type="text" id="yp-mo-' + prefix + '-country" data-yp-address-field="country" maxlength="2" placeholder="US" value="' + YP.escapeAttr( address.country ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-mo-' + prefix + '-phone">Phone</label><input type="text" id="yp-mo-' + prefix + '-phone" data-yp-address-field="phone" value="' + YP.escapeAttr( address.phone ) + '" /></div>' +
					'</div>' +
				'</div>'
			);
		}

		function shippingPanelHtml() {
			var s       = state.shipping;
			var options = ( yeffoprintAdminApp.shippo && yeffoprintAdminApp.shippo.manualOrderShippingOptions ) || [];

			return (
				'<div class="yp-panel" data-yp-shipping-panel>' +
					'<div class="yp-panel__head"><h2>Shipping &amp; billing address</h2></div>' +
					'<p class="yp-panel__hint">Optional at this step — leave blank to add an address later from the order screen instead.</p>' +
					addressFieldsHtml( 'ship', s.address ) +
					'<button type="button" class="yp-row-action" data-yp-verify-address>Verify address</button>' +
					'<div data-yp-verify-result></div>' +

					'<div class="yp-field yp-field--checkbox">' +
						'<input type="checkbox" id="yp-mo-billing-differs"' + ( s.billingDiffers ? ' checked' : '' ) + ' />' +
						'<label for="yp-mo-billing-differs">Billing address is different</label>' +
					'</div>' +
					'<div data-yp-billing-fields' + ( s.billingDiffers ? '' : ' style="display:none;"' ) + '>' +
						addressFieldsHtml( 'bill', s.billingAddress ) +
					'</div>' +

					'<div class="yp-panel__head"><h2>Shipping method</h2></div>' +
					( options.length ?
						'<div class="yp-field"><label for="yp-mo-shipping-method">Method</label><select id="yp-mo-shipping-method">' +
							'<option value="">No shipping charge</option>' +
							options.map( function ( option, index ) {
								return '<option value="' + index + '"' + ( String( index ) === s.selectedOptionIndex ? ' selected' : '' ) + '>' + YP.escapeHtml( option.label ) + ' — $' + option.amount.toFixed( 2 ) + '</option>';
							} ).join( '' ) +
						'</select></div>' +
						'<p class="yp-panel__hint">Adds the chosen amount to the invoice as a shipping line — edit these options under Settings &rarr; Shipping.</p>'
						: '<p class="yp-panel__hint">No shipping options set up yet — add some under Settings &rarr; Shipping.</p>' ) +
				'</div>'
			);
		}

		function readAddressState( prefix ) {
			var target = 'ship' === prefix ? state.shipping.address : state.shipping.billingAddress;
			viewEl.querySelectorAll( '#yp-mo-' + prefix + '-first-name, #yp-mo-' + prefix + '-last-name, #yp-mo-' + prefix + '-address-1, #yp-mo-' + prefix + '-address-2, #yp-mo-' + prefix + '-city, #yp-mo-' + prefix + '-state, #yp-mo-' + prefix + '-postcode, #yp-mo-' + prefix + '-country, #yp-mo-' + prefix + '-phone' )
				.forEach( function ( field ) {
					target[ field.getAttribute( 'data-yp-address-field' ) ] = field.value;
				} );
		}

		/** Rebuilds just the Shipping & billing panel's own markup in place — used after prefillAddressFromCustomer() below updates state.shipping, without touching (or losing typed-in progress in) the order-type-specific panel above the fold. */
		function refreshShippingPanel() {
			var panel = viewEl.querySelector( '[data-yp-shipping-panel]' );
			if ( ! panel ) {
				return;
			}
			panel.outerHTML = shippingPanelHtml();
			bindShippingPanel();
			renderVerifyResult();
		}

		/**
		 * Direct request: "can it pull their existing address from their
		 * profile if it has it filled out? I'd still like the opportunity
		 * to edit it if necessary, but if it's there it should pull it."
		 * Fired the moment staff pick an existing customer (not folded into
		 * the search-as-you-type results themselves, which would mean
		 * fetching an address for every result on every keystroke instead
		 * of the one customer actually chosen).
		 *
		 * The Shipping field prefers the account's saved shipping address,
		 * falling back to its billing address when only that's on file —
		 * the common case for a customer who's only ever entered one
		 * address at checkout, and this business cares about where the
		 * label/sticker order actually ships. The separate Billing section
		 * only switches on when the account has *both* halves saved *and*
		 * they're actually different; a customer with just one address (or
		 * two identical ones) stays on the simpler single-address view,
		 * same as this screen's own default for an address typed fresh.
		 */
		function prefillAddressFromCustomer( customerId ) {
			YP.request( coreEndpoint( 'admin/manual-orders/customer/' + customerId + '/address' ) )
				.then( function ( result ) {
					var shipping = result.shipping || result.billing;

					if ( shipping ) {
						state.shipping.address = shipping;
					}

					if ( result.shipping && result.billing && addressesDiffer( result.shipping, result.billing ) ) {
						state.shipping.billingAddress = result.billing;
						state.shipping.billingDiffers = true;
					}

					refreshShippingPanel();
				} )
				.catch( function () {
					// No saved address, or the lookup failed — leave the
					// fields exactly as they were (blank, ready to type
					// into), same as picking a customer with none on file.
				} );
		}

		function addressesDiffer( a, b ) {
			return Object.keys( a ).some( function ( key ) { return ( a[ key ] || '' ) !== ( b[ key ] || '' ); } );
		}

		function bindShippingPanel() {
			var panel = viewEl.querySelector( '[data-yp-shipping-panel]' );
			if ( ! panel ) {
				return; // Shippo not configured and no fields rendered — shouldn't happen, defensive only.
			}

			[ 'ship', 'bill' ].forEach( function ( prefix ) {
				panel.querySelectorAll( '[id^="yp-mo-' + prefix + '-"]' ).forEach( function ( field ) {
					field.addEventListener( 'input', function () { readAddressState( prefix ); } );
				} );
			} );

			var billingDiffersToggle = panel.querySelector( '#yp-mo-billing-differs' );
			billingDiffersToggle.addEventListener( 'change', function () {
				state.shipping.billingDiffers = billingDiffersToggle.checked;
				panel.querySelector( '[data-yp-billing-fields]' ).style.display = billingDiffersToggle.checked ? '' : 'none';
			} );

			panel.querySelector( '[data-yp-verify-address]' ).addEventListener( 'click', verifyShippingAddress );

			var methodSelect = panel.querySelector( '#yp-mo-shipping-method' );
			if ( methodSelect ) {
				methodSelect.addEventListener( 'change', function () {
					state.shipping.selectedOptionIndex = methodSelect.value;
				} );
			}
		}

		function verifyShippingAddress() {
			var panel     = viewEl.querySelector( '[data-yp-shipping-panel]' );
			var resultEl  = panel.querySelector( '[data-yp-verify-result]' );
			var button    = panel.querySelector( '[data-yp-verify-address]' );

			readAddressState( 'ship' );

			button.disabled = true;
			button.textContent = 'Verifying…';
			resultEl.innerHTML = '';

			YP.request( coreEndpoint( 'admin/manual-orders/verify-address' ), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { address: state.shipping.address } )
			} )
				.then( function ( result ) {
					button.disabled = false;
					button.textContent = 'Verify address';
					state.shipping.verifyResult = result;
					renderVerifyResult();
				} )
				.catch( function ( error ) {
					button.disabled = false;
					button.textContent = 'Verify address';
					state.shipping.verifyResult = { error: error.message };
					renderVerifyResult();
				} );
		}

		function renderVerifyResult() {
			var panel    = viewEl.querySelector( '[data-yp-shipping-panel]' );
			var resultEl = panel.querySelector( '[data-yp-verify-result]' );
			var result   = state.shipping.verifyResult;

			if ( ! result ) {
				resultEl.innerHTML = '';
				return;
			}

			if ( result.error ) {
				resultEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( result.error ) + '</p>';
				return;
			}

			var messages = ( result.messages || [] ).map( function ( message ) {
				return '<li>' + YP.escapeHtml( message ) + '</li>';
			} ).join( '' );

			resultEl.innerHTML = result.is_valid
				? '<p class="yp-panel__hint">Address verified.</p>' + ( messages ? '<ul>' + messages + '</ul>' : '' )
				: '<p class="yp-form__error">This address didn’t verify — double-check it before finalizing.</p>' + ( messages ? '<ul>' + messages + '</ul>' : '' );
		}

		function selectedShippingPayload() {
			var options = ( yeffoprintAdminApp.shippo && yeffoprintAdminApp.shippo.manualOrderShippingOptions ) || [];
			var index   = parseInt( state.shipping.selectedOptionIndex, 10 );
			var option  = isNaN( index ) ? null : options[ index ];

			return option ? { carrier_label: '', service: option.label, amount: option.amount } : null;
		}

		function shippingAddressPayload( address ) {
			// Every field blank is a valid "no address yet" — the backend's
			// own sanitize_address() treats that as null rather than an
			// incomplete-address error, same reasoning as leaving it blank
			// in the form in the first place.
			var hasAny = Object.keys( address ).some( function ( key ) { return '' !== address[ key ] && 'country' !== key; } );
			return hasAny ? address : null;
		}

		/* ---------- Submit ---------- */

		function submit() {
			var statusEl     = viewEl.querySelector( '[data-yp-submit-status]' );
			var submitButton = viewEl.querySelector( '[data-yp-submit]' );

			// Direct request: "customers order custom design items mixed
			// with template items... order them at the same time." Every
			// active type below contributes its own nested key rather than
			// this body being shaped around exactly one order_type.
			var body = {
				customer: customerPayload(),
				requires_proof: viewEl.querySelector( '#yp-mo-requires-proof' ).checked,
				send_invoice_email: viewEl.querySelector( '#yp-mo-send-invoice' ).checked
			};

			if ( state.activeTypes.custom_design ) {
				var batchBody = viewEl.querySelector( '[data-yp-batch]' );
				body.custom_design = {
					brand_name: viewEl.querySelector( '#yp-mo-brand' ).value,
					batch: readBatchRows( batchBody ),
					style_notes: viewEl.querySelector( '#yp-mo-style-notes' ).value,
					instructions: viewEl.querySelector( '#yp-mo-instructions' ).value,
					waive_design_fee: viewEl.querySelector( '#yp-mo-waive-fee' ).checked
				};
			}

			if ( state.activeTypes.sticker ) {
				var stickerFields = readStickerFields();
				stickerFields.instructions = viewEl.querySelector( '#yp-mo-sticker-instructions' ).value;
				stickerFields.uploads = state.stickerUploads.filter( function ( file ) { return file.id; } ).map( function ( file ) { return file.id; } );
				body.sticker = stickerFields;
			}

			if ( state.activeTypes.template ) {
				var templateVariantsBody = viewEl.querySelector( '[data-yp-template-variants]' );
				body.template = {
					template_id: state.selectedTemplate ? state.selectedTemplate.id : 0,
					size_id: parseInt( ( viewEl.querySelector( '#yp-mo-template-size' ) || {} ).value, 10 ) || 0,
					material_id: parseInt( ( viewEl.querySelector( '#yp-mo-template-material' ) || {} ).value, 10 ) || 0,
					variants: templateVariantsBody && state.templateData ? readTemplateVariants( templateVariantsBody, state.templateData.field_schema ) : [],
					instructions: ( viewEl.querySelector( '#yp-mo-template-instructions' ) || {} ).value || ''
				};
			}

			readAddressState( 'ship' );
			body.shipping_address = shippingAddressPayload( state.shipping.address );
			if ( state.shipping.billingDiffers ) {
				readAddressState( 'bill' );
				body.billing_address = shippingAddressPayload( state.shipping.billingAddress );
			}
			var selectedShipping = selectedShippingPayload();
			if ( selectedShipping ) {
				body.shipping = selectedShipping;
			}

			submitButton.disabled = true;
			submitButton.textContent = 'Creating…';
			statusEl.innerHTML = '';

			YP.request( coreEndpoint( 'admin/manual-orders' ), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( body )
			} )
				.then( function ( result ) {
					submitButton.disabled = false;
					submitButton.textContent = 'Create Order';

					var links = '<a href="' + YP.escapeAttr( result.order_edit_url ) + '">View order</a>';
					// One "Add a proof" link per shell — an order can now
					// carry more than one (see the class docblock in
					// class-manual-order-creator.php).
					( result.custom_orders || [] ).forEach( function ( customOrder ) {
						var label = ORDER_TYPE_LABELS[ customOrder.order_type ] || customOrder.order_type;
						links += ' &middot; <a href="#/orders/' + customOrder.id + '">Add a proof (' + YP.escapeHtml( label ) + ')</a>';
					} );
					statusEl.innerHTML = '<p class="yp-panel__hint">Order created. ' + links + '</p>';
				} )
				.catch( function ( error ) {
					submitButton.disabled = false;
					submitButton.textContent = 'Create Order';
					statusEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}
	};
} )();
