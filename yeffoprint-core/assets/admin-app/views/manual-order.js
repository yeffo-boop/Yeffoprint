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

	YP.views[ 'manual-order' ] = function ( viewEl ) {
		var state = {
			orderType: 'custom_design',
			options: null, // custom-orders/options — Custom Design's own sizes/materials.
			stickerOptions: null, // custom-stickers/options — Custom Stickers' own sizes/materials/types/shapes.
			stickerUploads: [], // [{ name, id, error }] — same shape as the customer-facing form's own uploadedFiles.
			selectedCustomer: null, // { id, display_name, email }
			newCustomerMode: false,
			selectedTemplate: null, // { id, title } — picked from search, before its configurator data has loaded.
			templateData: null // GET /templates/{id}/configurator response — { field_schema, sizes, materials } — null until selectedTemplate's data has loaded.
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
			viewEl.innerHTML =
				'<p class="yp-app__intro">Key in an order for a customer over the phone or by email — same pricing and options as the storefront.</p>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Order type</h2></div>' +
					'<div class="yp-form__actions">' +
						'<button type="button" class="wp-block-button__link ' + ( 'custom_design' === state.orderType ? 'is-style-accent' : 'is-style-outline' ) + '" data-yp-order-type="custom_design">Custom Design</button>' +
						'<button type="button" class="wp-block-button__link ' + ( 'sticker' === state.orderType ? 'is-style-accent' : 'is-style-outline' ) + '" data-yp-order-type="sticker">Custom Sticker</button>' +
						'<button type="button" class="wp-block-button__link ' + ( 'template' === state.orderType ? 'is-style-accent' : 'is-style-outline' ) + '" data-yp-order-type="template">Template Label</button>' +
					'</div>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Customer</h2></div>' +
					'<div data-yp-customer-picker></div>' +
				'</div>' +

				( 'custom_design' === state.orderType ? customDesignFieldsHtml() : ( 'sticker' === state.orderType ? stickerFieldsHtml() : templateFieldsHtml() ) ) +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Price</h2></div>' +
					'<div data-yp-price-preview><p class="yp-field__hint">' + priceHintText() + '</p></div>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-field yp-field--checkbox">' +
						'<input type="checkbox" id="yp-mo-requires-proof" checked />' +
						'<label for="yp-mo-requires-proof">Requires proof approval before printing</label>' +
					'</div>' +
					'<p class="yp-panel__hint">When checked, the customer gets a proof-approval link once staff upload a proof from the Custom Orders screen — same flow as an order placed on the storefront.</p>' +
				'</div>' +

				'<div data-yp-submit-status></div>' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-submit>Create Order</button>';

			renderCustomerPicker();

			viewEl.querySelectorAll( '[data-yp-order-type]' ).forEach( function ( button ) {
				if ( button.disabled ) {
					return;
				}
				button.addEventListener( 'click', function () {
					var type = button.getAttribute( 'data-yp-order-type' );
					if ( type === state.orderType ) {
						return;
					}
					state.orderType = type;
					render();
				} );
			} );

			if ( 'custom_design' === state.orderType ) {
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
			} else if ( 'sticker' === state.orderType ) {
				bindStickerChangeListeners();
				wireStickerUploads();
				renderStickerFileList();
				toggleStickerCustomDimensions();
				refreshStickerPricePreview();
			} else {
				renderTemplatePanel();
				if ( state.templateData ) {
					bindTemplateVariantListeners();
					refreshTemplatePricePreview();
				}
			}

			viewEl.querySelector( '[data-yp-submit]' ).addEventListener( 'click', submit );
		}

		function priceHintText() {
			if ( 'custom_design' === state.orderType ) {
				return 'Add a label to see pricing.';
			}
			if ( 'sticker' === state.orderType ) {
				return 'Choose a size, material, type, and shape to see pricing.';
			}
			return state.templateData ? 'Add a label to see pricing.' : 'Choose a design to see pricing.';
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
			var previewEl = viewEl.querySelector( '[data-yp-price-preview]' );
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

			YP.request( coreEndpoint( 'custom-orders/pricing-preview' ), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { mode: 'new_design', batch: batch } )
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
			var previewEl = viewEl.querySelector( '[data-yp-price-preview]' );
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
			var previewEl = viewEl.querySelector( '[data-yp-price-preview]' );
			if ( ! previewEl ) {
				return; // Navigated away.
			}

			if ( ! state.templateData ) {
				previewEl.innerHTML = '<p class="yp-field__hint">' + priceHintText() + '</p>';
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

		/* ---------- Submit ---------- */

		function submit() {
			var statusEl     = viewEl.querySelector( '[data-yp-submit-status]' );
			var submitButton = viewEl.querySelector( '[data-yp-submit]' );

			var body;
			if ( 'custom_design' === state.orderType ) {
				var batchBody = viewEl.querySelector( '[data-yp-batch]' );
				body = {
					order_type: 'custom_design',
					customer: customerPayload(),
					brand_name: viewEl.querySelector( '#yp-mo-brand' ).value,
					batch: readBatchRows( batchBody ),
					style_notes: viewEl.querySelector( '#yp-mo-style-notes' ).value,
					instructions: viewEl.querySelector( '#yp-mo-instructions' ).value,
					requires_proof: viewEl.querySelector( '#yp-mo-requires-proof' ).checked
				};
			} else if ( 'sticker' === state.orderType ) {
				body = readStickerFields();
				body.order_type = 'sticker';
				body.customer = customerPayload();
				body.instructions = viewEl.querySelector( '#yp-mo-sticker-instructions' ).value;
				body.uploads = state.stickerUploads.filter( function ( file ) { return file.id; } ).map( function ( file ) { return file.id; } );
				body.requires_proof = viewEl.querySelector( '#yp-mo-requires-proof' ).checked;
			} else {
				var templateVariantsBody = viewEl.querySelector( '[data-yp-template-variants]' );
				body = {
					order_type: 'template',
					customer: customerPayload(),
					template_id: state.selectedTemplate ? state.selectedTemplate.id : 0,
					size_id: parseInt( ( viewEl.querySelector( '#yp-mo-template-size' ) || {} ).value, 10 ) || 0,
					material_id: parseInt( ( viewEl.querySelector( '#yp-mo-template-material' ) || {} ).value, 10 ) || 0,
					variants: templateVariantsBody && state.templateData ? readTemplateVariants( templateVariantsBody, state.templateData.field_schema ) : [],
					instructions: ( viewEl.querySelector( '#yp-mo-template-instructions' ) || {} ).value || '',
					requires_proof: viewEl.querySelector( '#yp-mo-requires-proof' ).checked
				};
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
					if ( result.custom_order_id ) {
						links += ' &middot; <a href="#/orders/' + result.custom_order_id + '">Add a proof</a>';
					}
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
