/**
 * Manual order creation (docs/ARCHITECTURE.md) — staff key in a real
 * order for a customer directly from the admin app, direct request:
 * "am I able to make a custom order manually that also is with a proof
 * to be approved by the customer?", broadened to "manually create any
 * orders, not just custom orders."
 *
 * Phase A shipped Custom Design orders. Phase B added Custom Stickers —
 * no fee item, and its own artwork upload per sticker; "+ Add another
 * sticker" puts several on one order (direct report: "I can only add
 * one custom sticker to an order"). Phase C (this revision) adds Template Label orders —
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
 *
 * Web Design Package (this revision) — direct request: "Can we build
 * the add new order for web design customers to my yeffodesign
 * dashboard" — a fourth, much simpler item type: pick a package
 * (fetched from `/wp/v2/yp_web_design_pkg`, same REST route
 * views/web-design-packages.js itself edits), no proof approval, no
 * shipping address/method — see class-manual-order-creator.php's own
 * docblock for why a package purchase skips both. When it's the only
 * active type, the Shipping & billing panel is left out of render()
 * entirely rather than asking for a delivery address nothing will
 * ever ship to.
 *
 * Add to an existing order (#/manual-order/{id}) — direct request: "edit
 * an order I made from my dashboard before it's been paid? Like if a
 * customer wants to add something." Opened from the order window's Add
 * items button. Same item pickers and pricing, but the Customer and
 * Shipping panels are left out (the order already has both) and submit
 * posts to /admin/manual-orders/{id}/items instead of creating an order.
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

	// "Fill all" link under each field in the first label row — direct
	// request: "if I create 10 items for an order, and they're all
	// holographic, I should be able to put that in the top one then auto
	// fill the rest." records.css only shows it on the first row, and only
	// once there's more than one row; wireFillAll() does the copying.
	var FILL_ALL_HTML = '<button type="button" class="yp-fill-all" data-yp-fill-all>&darr; Fill all</button>';

	function batchRowHtml( row, options ) {
		row = row || { size_id: '', material_id: '', quantity: 100, compound_strength: '', brand_name: '' };
		return (
			'<tr>' +
				'<td data-label="Size"><select data-row-size aria-label="Size">' +
					'<option value="">Choose a size…</option>' +
					options.sizes.map( function ( size ) {
						return '<option value="' + size.id + '"' + ( String( row.size_id ) === String( size.id ) ? ' selected' : '' ) + '>' + YP.escapeHtml( size.name ) + '</option>';
					} ).join( '' ) +
				'</select>' + FILL_ALL_HTML + '</td>' +
				'<td data-label="Material"><select data-row-material aria-label="Material">' +
					'<option value="">Choose a material…</option>' +
					options.materials.map( function ( material ) {
						return '<option value="' + material.id + '"' + ( String( row.material_id ) === String( material.id ) ? ' selected' : '' ) + ( material.in_stock ? '' : ' disabled' ) + '>' + YP.escapeHtml( material.name ) + ( material.in_stock ? '' : ' (out of stock)' ) + '</option>';
					} ).join( '' ) +
				'</select>' + FILL_ALL_HTML + '</td>' +
				'<td data-label="Quantity" class="yp-tier-table__qty yp-stack-half"><input type="number" min="1" step="1" inputmode="numeric" data-row-quantity aria-label="Quantity" value="' + YP.escapeAttr( row.quantity ) + '" />' + FILL_ALL_HTML + '</td>' +
				'<td data-label="Compound/Strength" class="yp-stack-half"><input type="text" data-row-compound aria-label="Compound/Strength" placeholder="e.g. 10mg/mL" value="' + YP.escapeAttr( row.compound_strength ) + '" />' + FILL_ALL_HTML + '</td>' +
				// Blank uses the order's Brand name above; only a label for a different brand needs one typed.
				'<td data-label="Brand"><input type="text" data-row-brand aria-label="Brand" placeholder="Same as Brand name" value="' + YP.escapeAttr( row.brand_name || '' ) + '" />' + FILL_ALL_HTML + '</td>' +
				'<td class="yp-stack-remove"><button type="button" class="yp-row-action" data-yp-remove-row aria-label="Remove label">&times;</button></td>' +
			'</tr>'
		);
	}

	// Copies the first row's value in the clicked column into every other
	// row. One delegated listener per <tbody> (render() builds a fresh one
	// each time), so rows added later are covered too.
	function wireFillAll( tbody, onChange ) {
		if ( ! tbody || tbody._fillAllWired ) {
			return;
		}
		tbody._fillAllWired = true;
		tbody.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-yp-fill-all]' );
			if ( ! button || ! tbody.contains( button ) ) {
				return;
			}
			var cell   = button.closest( 'td' );
			var column = Array.prototype.indexOf.call( cell.parentNode.children, cell );
			var source = cell.querySelector( 'input, select, textarea' );
			if ( ! source ) {
				return;
			}
			Array.prototype.forEach.call( tbody.querySelectorAll( 'tr' ), function ( row ) {
				var target = row.children[ column ] && row.children[ column ].querySelector( 'input, select, textarea' );
				if ( target && target !== source ) {
					target.value = source.value;
				}
			} );
			var label = button.innerHTML;
			button.classList.add( 'is-done' );
			button.textContent = '\u2713 Filled';
			setTimeout( function () {
				button.classList.remove( 'is-done' );
				button.innerHTML = label;
			}, 1400 );
			onChange();
		} );
	}

	// A new row starts as a copy of the last one's picks (size, material,
	// quantity), so a run of identical labels only needs the text typed.
	function lastBatchRow( tbody ) {
		var rows = readBatchRows( tbody );
		var last = rows[ rows.length - 1 ];
		return last ? { size_id: last.size_id || '', material_id: last.material_id || '', quantity: last.quantity || 100, compound_strength: '', brand_name: last.brand_name || '' } : null;
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
				compound_strength: row.querySelector( '[data-row-compound]' ).value,
				brand_name: row.querySelector( '[data-row-brand]' ).value
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
		template: 'Template Label',
		web_design: 'Web Design Package'
	};

	YP.views[ 'manual-order' ] = function ( viewEl, subId ) {
		var addToOrderId = parseInt( subId, 10 ) || 0;

		var emptyAddress = function () {
			return { first_name: '', last_name: '', address_1: '', address_2: '', city: '', state: '', postcode: '', country: 'US', phone: '' };
		};

		var state = {
			activeTypes: { custom_design: true, sticker: false, template: false, web_design: false },
			options: null, // custom-orders/options — Custom Design's own sizes/materials.
			stickerOptions: null, // custom-stickers/options — Custom Stickers' own sizes/materials/types/shapes.
			webDesignPackages: null, // /wp/v2/yp_web_design_pkg — every published package, priced or not (see webDesignFieldsHtml()).
			addToOrder: null, // GET /admin/order/{id} — only in "add to an existing order" mode (addToOrderId).
			stickers: null, // Custom Sticker cards — see newSticker(); filled in once the options load.
			// Direct report: switching item types "makes me start all the way
			// over" — render() rebuilds every panel. saveDraft() copies what's
			// typed here first and render() puts it back, so turning a type
			// on/off (even off and on again) keeps every other panel as is.
			draft: { fields: {}, batch: null, templateVariants: null },
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
				customerProvidesAddress: false,
				billingDiffers: false,
				billingAddress: emptyAddress(),
				verifying: false,
				verifyResult: null, // { is_valid, messages } from the last /verify-address call.
				selectedOptionIndex: '' // Index into yeffoprintAdminApp.shippo.manualOrderShippingOptions, as a string (matches a <select>'s own value type) — '' means the customer picks on their payment page, 'none' means no shipping charge.
			}
		};

		viewEl.innerHTML = '<p class="yp-app__intro">Loading&hellip;</p>';

		Promise.all( [
			YP.request( coreEndpoint( 'custom-orders/options' ) ),
			YP.request( coreEndpoint( 'custom-stickers/options' ) ),
			YP.request( yeffoprintAdminApp.wpApiUrl + 'yp_web_design_pkg?context=edit&status=publish&per_page=100&orderby=menu_order&order=asc' ),
			addToOrderId ? YP.request( coreEndpoint( 'admin/order/' + addToOrderId ) ) : Promise.resolve( null )
		] )
			.then( function ( results ) {
				state.options = results[ 0 ];
				state.stickerOptions = results[ 1 ];
				state.stickers = [ newSticker() ];
				state.webDesignPackages = results[ 2 ];
				state.addToOrder = results[ 3 ];
				if ( state.addToOrder && ! state.addToOrder.editable && ! state.addToOrder.can_add_items ) {
					viewEl.innerHTML = '<p class="yp-form__error">Order #' + YP.escapeHtml( String( state.addToOrder.number ) ) + ' has already shipped or closed, so items can’t be added to it.</p>';
					return;
				}
				render();
			} )
			.catch( function ( error ) {
				viewEl.innerHTML = '<p class="yp-form__error">Couldn’t load this screen: ' + YP.escapeHtml( error.message ) + '</p>';
			} );

		function render() {
			var typeButtonsHtml = Object.keys( ORDER_TYPE_LABELS ).map( function ( type ) {
				return '<button type="button" class="wp-block-button__link ' + ( state.activeTypes[ type ] ? 'is-style-accent' : 'is-style-outline' ) + '" data-yp-order-type="' + type + '">' + ORDER_TYPE_LABELS[ type ] + '</button>';
			} ).join( '' );

			// A Web Design Package is a virtual, one-time service — nothing
			// ships. Only pull in the Shipping & billing panel when at least
			// one *other*, physical item type is active alongside it (or
			// instead of it).
			var hasPhysicalItem = state.activeTypes.custom_design || state.activeTypes.sticker || state.activeTypes.template;
			var adding          = state.addToOrder;

			viewEl.innerHTML =
				'<div class="yp-mo">' +
				( adding
					? '<p class="yp-app__intro">Adding items to <strong>Order #' + YP.escapeHtml( String( adding.number ) ) + '</strong>' + ( adding.customer_name ? ' for ' + YP.escapeHtml( adding.customer_name ) : '' ) +
						( adding.editable
							? ' (currently $' + adding.total.toFixed( 2 ) + '). Its payment link stays the same and charges the new total.'
							: '. It’s already paid, so these items go on a linked add-on order with its own payment link and no shipping charge. Both orders ship together in one box.' ) +
						' <a href="#/manual-order">Start a new order instead</a></p>'
					: '<p class="yp-app__intro">Key in an order for a customer over the phone or by email — same pricing and options as the storefront. Toggle on more than one item type below to combine them on the same order.</p>' ) +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Item types</h2></div>' +
					'<div class="yp-form__actions">' + typeButtonsHtml + '</div>' +
				'</div>' +

				( adding ? '' :
					'<div class="yp-panel">' +
						'<div class="yp-panel__head"><h2>Customer</h2></div>' +
						'<div data-yp-customer-picker></div>' +
					'</div>' ) +

				( hasPhysicalItem && ! adding ? shippingPanelHtml() : '' ) +

				( state.activeTypes.custom_design ? customDesignFieldsHtml() : '' ) +
				( state.activeTypes.sticker ? stickerFieldsHtml() : '' ) +
				( state.activeTypes.template ? templateFieldsHtml() : '' ) +
				( state.activeTypes.web_design ? webDesignFieldsHtml() : '' ) +

				'<div class="yp-panel">' +
					'<div class="yp-field yp-field--checkbox">' +
						'<input type="checkbox" id="yp-mo-requires-proof" checked />' +
						'<label for="yp-mo-requires-proof">Requires proof approval before printing</label>' +
					'</div>' +
					'<p class="yp-panel__hint">When checked, the customer gets a proof-approval link once staff upload a proof from the Custom Orders screen — same flow as an order placed on the storefront. Each item type above (and each sticker) gets its own proof to approve.</p>' +
					( state.activeTypes.custom_design ?
						'<div class="yp-field yp-field--checkbox">' +
							'<input type="checkbox" id="yp-mo-waive-fee" />' +
							'<label for="yp-mo-waive-fee">Waive the ' + ( state.options && state.options.design_fee ? state.options.design_fee : 'design' ) + ' fee</label>' +
						'</div>' +
						'<p class="yp-panel__hint">No design-fee line item gets added to the order — for a VIP customer or as goodwill. The customer still pays for the print run itself.</p>'
						: '' ) +
					'<div class="yp-field yp-field--checkbox">' +
						'<input type="checkbox" id="yp-mo-send-invoice"' + ( adding ? '' : ' checked' ) + ' />' +
						'<label for="yp-mo-send-invoice">' + ( adding ? ( adding.editable ? 'Email the customer the updated order and payment link' : 'Email the customer the add-on order and its payment link' ) : 'Email the customer their order details and a payment link' ) + '</label>' +
					'</div>' +
					'<p class="yp-panel__hint">Sent right away via WooCommerce’s own Order details email, with the order’s real payment link — skip this if you’re taking payment another way (over the phone, in person) instead.</p>' +
				'</div>' +

				'<div data-yp-submit-status></div>' +
				// Wrapped so phones can keep it pinned to the bottom of the screen (records.css .yp-mo__submit).
				'<div class="yp-mo__submit"><button type="button" class="wp-block-button__link is-style-accent" data-yp-submit>' + submitLabel() + '</button></div>' +
				'</div>';

			if ( ! adding ) {
				renderCustomerPicker();
			}
			// Both are no-ops (via their own null-guards) when
			// hasPhysicalItem left the shipping panel out of the markup
			// above. render() rebuilds the panel's HTML from scratch (e.g.
			// on every item-type toggle) with an empty verify-result
			// container — state.shipping itself survives that rebuild (see
			// its own docblock above; the shipping-method <select> reads its
			// own selected option straight from that state when the markup
			// is built, so only the verify result needs playing back here).
			bindShippingPanel();
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
					saveDraft();
					render();
				} );
			} );

			if ( state.activeTypes.custom_design ) {
				var batchBody = viewEl.querySelector( '[data-yp-batch]' );
				wireRemoveButtons( batchBody, refreshPricePreview );
				wireFillAll( batchBody, refreshPricePreview );

				viewEl.querySelector( '[data-yp-add-row]' ).addEventListener( 'click', function () {
					batchBody.insertAdjacentHTML( 'beforeend', batchRowHtml( lastBatchRow( batchBody ), state.options ) );
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
				var requiresProofToggle = viewEl.querySelector( '#yp-mo-requires-proof' );
				if ( requiresProofToggle && ! requiresProofToggle._wired ) {
					requiresProofToggle._wired = true;
					requiresProofToggle.addEventListener( 'change', refreshPricePreview );
				}
			}

			if ( state.activeTypes.sticker ) {
				bindStickerPanel();
			}

			if ( state.activeTypes.template ) {
				renderTemplatePanel();
				if ( state.templateData ) {
					bindTemplateVariantListeners();
					refreshTemplatePricePreview();
				}
			}

			restoreDraftFields();

			viewEl.querySelector( '[data-yp-submit]' ).addEventListener( 'click', submit );
		}

		// Every plain field with an id (brand name, notes, checkboxes, the
		// web design package, the template's size/material, new-customer
		// name/email...) plus the two row tables. Merged into the draft
		// rather than replacing it, so a panel that's switched off keeps
		// its values for when it's switched back on. Stickers and the
		// shipping panel already live in state; search boxes are skipped.
		function saveDraft() {
			viewEl.querySelectorAll( 'input[id], select[id], textarea[id]' ).forEach( function ( field ) {
				if ( 'file' === field.type || /^yp-mo-sticker-|-search$|^yp-mo-(billing-differs|customer-provides-address|shipping-method|payment-link)$/.test( field.id ) || field.hasAttribute( 'data-yp-address-field' ) ) {
					return;
				}
				state.draft.fields[ field.id ] = 'checkbox' === field.type ? { checked: field.checked } : { value: field.value };
			} );

			var batchBody = viewEl.querySelector( '[data-yp-batch]' );
			if ( batchBody ) {
				state.draft.batch = Array.prototype.map.call( batchBody.querySelectorAll( 'tr' ), function ( row ) {
					return {
						size_id: row.querySelector( '[data-row-size]' ).value,
						material_id: row.querySelector( '[data-row-material]' ).value,
						quantity: row.querySelector( '[data-row-quantity]' ).value,
						compound_strength: row.querySelector( '[data-row-compound]' ).value,
						brand_name: row.querySelector( '[data-row-brand]' ).value
					};
				} );
			}

			var variantsBody = viewEl.querySelector( '[data-yp-template-variants]' );
			if ( variantsBody && state.templateData ) {
				state.draft.templateVariants = readTemplateVariants( variantsBody, state.templateData.field_schema ).map( function ( variant ) {
					variant.quantity = variant.quantity || '';
					return variant;
				} );
			}
		}

		function restoreDraftFields() {
			Object.keys( state.draft.fields ).forEach( function ( id ) {
				var field = document.getElementById( id );
				if ( ! field || ! viewEl.contains( field ) ) {
					return;
				}
				var saved = state.draft.fields[ id ];
				if ( 'checked' in saved ) {
					field.checked = saved.checked;
				} else if ( 'SELECT' !== field.tagName || field.querySelector( 'option[value="' + CSS.escape( saved.value ) + '"]' ) ) {
					field.value = saved.value;
				}
			} );
		}

		/* ---------- Custom Design (Phase A) ---------- */

		function customDesignFieldsHtml() {
			return (
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Custom Design details</h2></div>' +
					'<div class="yp-field"><label for="yp-mo-brand">Brand name</label><input type="text" id="yp-mo-brand" /></div>' +
					'<table class="yp-tier-table yp-tier-table--items yp-stack-rows"><thead><tr><th>Size</th><th>Material</th><th>Quantity</th><th>Compound/Strength</th><th>Brand</th><th></th></tr></thead>' +
						'<tbody data-yp-batch>' + ( state.draft.batch || [ null ] ).map( function ( row ) { return batchRowHtml( row, state.options ); } ).join( '' ) + '</tbody>' +
					'</table>' +
					'<button type="button" class="wp-block-button__link is-style-outline yp-add-row-button" data-yp-add-row>+ Add another label</button>' +
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
			// The order only adds the fee alongside a proof (class-manual-order-creator.php
			// add_custom_design_rows), so no proof approval means no fee here either.
			var requiresProofEl = viewEl.querySelector( '#yp-mo-requires-proof' );
			var mode = ( waiveFeeEl && waiveFeeEl.checked ) || ( requiresProofEl && ! requiresProofEl.checked ) ? 'own_design' : 'new_design';

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

		// Direct report: "I can only add one custom sticker to an order."
		// Each sticker is its own card (own size/type/shape/quantity,
		// instructions and artwork) kept in state.stickers, so a re-render
		// (e.g. toggling another item type) keeps every card as typed.
		function newSticker( copyFrom ) {
			return {
				size_id: copyFrom ? copyFrom.size_id : '',
				material_id: copyFrom ? copyFrom.material_id : '',
				sticker_type: copyFrom ? copyFrom.sticker_type : '',
				shape: copyFrom ? copyFrom.shape : '',
				quantity: copyFrom ? copyFrom.quantity : 100,
				custom_width_in: copyFrom ? copyFrom.custom_width_in : '',
				custom_height_in: copyFrom ? copyFrom.custom_height_in : '',
				instructions: '',
				uploads: [] // [{ name, id, error }] — same shape as the customer-facing form's own uploadedFiles.
			};
		}

		function stickerSizeById( id ) {
			return state.stickerOptions.sizes.filter( function ( size ) {
				return String( size.id ) === String( id );
			} )[ 0 ] || null;
		}

		function stickerFieldsHtml() {
			return (
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Custom Sticker details</h2></div>' +
					'<div data-yp-sticker-list></div>' +
					'<button type="button" class="wp-block-button__link is-style-outline yp-add-row-button" data-yp-add-sticker>+ Add another sticker</button>' +
					'<div data-yp-price-preview="sticker"><p class="yp-field__hint">Choose a size, material, type, and shape to see pricing.</p></div>' +
				'</div>'
			);
		}

		function stickerOptionsHtml( placeholder, entries, selected ) {
			return '<option value="">' + placeholder + '</option>' + entries.map( function ( entry ) {
				return '<option value="' + YP.escapeAttr( entry.value ) + '"' + ( String( entry.value ) === String( selected ) ? ' selected' : '' ) + ( entry.disabled ? ' disabled' : '' ) + '>' + YP.escapeHtml( entry.label ) + '</option>';
			} ).join( '' );
		}

		function stickerCardHtml( sticker, index ) {
			var o    = state.stickerOptions;
			var id   = function ( name ) { return 'yp-mo-sticker-' + name + '-' + index; };
			var size = stickerSizeById( sticker.size_id );
			var many = state.stickers.length > 1;
			return (
				'<div class="yp-mo-sticker" data-yp-sticker="' + index + '">' +
					( many
						? '<div class="yp-mo-sticker__head"><h3>Sticker ' + ( index + 1 ) + '</h3>' +
							'<button type="button" class="yp-row-action" data-yp-remove-sticker aria-label="Remove sticker ' + ( index + 1 ) + '">Remove</button>' +
							'<span class="yp-mo-sticker__price" data-yp-sticker-price></span></div>'
						: '' ) +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="' + id( 'size' ) + '">Size</label><select id="' + id( 'size' ) + '" data-sk="size_id">' +
							stickerOptionsHtml( 'Choose a size…', o.sizes.map( function ( s ) { return { value: s.id, label: s.name }; } ), sticker.size_id ) +
						'</select></div>' +
						'<div class="yp-field"><label for="' + id( 'material' ) + '">Material</label><select id="' + id( 'material' ) + '" data-sk="material_id">' +
							stickerOptionsHtml( 'Choose a material…', o.materials.map( function ( m ) {
								return { value: m.id, label: m.name + ( m.in_stock ? '' : ' (out of stock)' ), disabled: ! m.in_stock };
							} ), sticker.material_id ) +
						'</select></div>' +
					'</div>' +
					'<div class="yp-form__row" data-yp-sticker-custom-dims' + ( size && size.is_custom ? '' : ' style="display:none;"' ) + '>' +
						'<div class="yp-field"><label for="' + id( 'width' ) + '">Width (in)</label><input type="number" min="0.1" step="0.01" id="' + id( 'width' ) + '" data-sk="custom_width_in" value="' + YP.escapeAttr( sticker.custom_width_in ) + '" /></div>' +
						'<div class="yp-field"><label for="' + id( 'height' ) + '">Height (in)</label><input type="number" min="0.1" step="0.01" id="' + id( 'height' ) + '" data-sk="custom_height_in" value="' + YP.escapeAttr( sticker.custom_height_in ) + '" /></div>' +
					'</div>' +
					'<div class="yp-form__row--three">' +
						'<div class="yp-field"><label for="' + id( 'type' ) + '">Type</label><select id="' + id( 'type' ) + '" data-sk="sticker_type">' +
							stickerOptionsHtml( 'Choose a type…', Object.keys( o.sticker_types ).map( function ( key ) { return { value: key, label: o.sticker_types[ key ] }; } ), sticker.sticker_type ) +
						'</select></div>' +
						'<div class="yp-field"><label for="' + id( 'shape' ) + '">Shape</label><select id="' + id( 'shape' ) + '" data-sk="shape">' +
							stickerOptionsHtml( 'Choose a shape…', Object.keys( o.shapes ).map( function ( key ) { return { value: key, label: o.shapes[ key ] }; } ), sticker.shape ) +
						'</select></div>' +
						'<div class="yp-field"><label for="' + id( 'quantity' ) + '">Quantity</label><input type="number" min="1" step="1" inputmode="numeric" id="' + id( 'quantity' ) + '" data-sk="quantity" value="' + YP.escapeAttr( sticker.quantity ) + '" /></div>' +
					'</div>' +
					'<div class="yp-field"><label for="' + id( 'instructions' ) + '">Instructions</label><textarea id="' + id( 'instructions' ) + '" data-sk="instructions" rows="2">' + YP.escapeHtml( sticker.instructions ) + '</textarea></div>' +
					'<div class="yp-field">' +
						'<label for="' + id( 'files' ) + '">Artwork (optional — can be sent separately and attached later)</label>' +
						'<input type="file" id="' + id( 'files' ) + '" multiple data-yp-sticker-files />' +
						'<ul data-yp-sticker-file-list></ul>' +
					'</div>' +
				'</div>'
			);
		}

		function renderStickerCards() {
			var listEl = viewEl.querySelector( '[data-yp-sticker-list]' );
			if ( ! listEl ) {
				return;
			}
			listEl.innerHTML = state.stickers.map( stickerCardHtml ).join( '' );

			Array.prototype.forEach.call( listEl.querySelectorAll( '[data-yp-sticker]' ), function ( card ) {
				var index   = parseInt( card.getAttribute( 'data-yp-sticker' ), 10 );
				var sticker = state.stickers[ index ];

				card.querySelectorAll( '[data-sk]' ).forEach( function ( field ) {
					var key = field.getAttribute( 'data-sk' );
					field.addEventListener( 'input', function () {
						sticker[ key ] = field.value;
					} );
					field.addEventListener( 'change', function () {
						sticker[ key ] = field.value;
						if ( 'size_id' === key ) {
							var size = stickerSizeById( field.value );
							card.querySelector( '[data-yp-sticker-custom-dims]' ).style.display = ( size && size.is_custom ) ? '' : 'none';
						}
						if ( 'instructions' !== key ) {
							refreshStickerPricePreview();
						}
					} );
				} );

				var removeButton = card.querySelector( '[data-yp-remove-sticker]' );
				if ( removeButton ) {
					removeButton.addEventListener( 'click', function () {
						state.stickers.splice( index, 1 );
						renderStickerCards();
						refreshStickerPricePreview();
					} );
				}

				wireStickerUploads( card, sticker );
				renderStickerFileList( card, sticker );
			} );
		}

		function bindStickerPanel() {
			var addButton = viewEl.querySelector( '[data-yp-add-sticker]' );
			addButton.addEventListener( 'click', function () {
				// Starts as a copy of the last sticker's picks, same as
				// "Add another label" — only the artwork usually differs.
				state.stickers.push( newSticker( state.stickers[ state.stickers.length - 1 ] ) );
				renderStickerCards();
				refreshStickerPricePreview();
				var cards = viewEl.querySelectorAll( '[data-yp-sticker]' );
				if ( cards.length && cards[ cards.length - 1 ].scrollIntoView ) {
					cards[ cards.length - 1 ].scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
			} );
			renderStickerCards();
			refreshStickerPricePreview();
		}

		var stickerPreviewTimer = null;
		function refreshStickerPricePreview() {
			clearTimeout( stickerPreviewTimer );
			stickerPreviewTimer = setTimeout( doRefreshStickerPricePreview, 300 );
		}

		function stickerPayload( sticker ) {
			return {
				size_id: parseInt( sticker.size_id, 10 ) || 0,
				material_id: parseInt( sticker.material_id, 10 ) || 0,
				sticker_type: sticker.sticker_type || '',
				shape: sticker.shape || '',
				quantity: parseInt( sticker.quantity, 10 ) || 0,
				custom_width_in: parseFloat( sticker.custom_width_in ) || 0,
				custom_height_in: parseFloat( sticker.custom_height_in ) || 0
			};
		}

		function stickerIsComplete( fields ) {
			var size   = stickerSizeById( fields.size_id );
			var dimsOk = ! size || ! size.is_custom || ( fields.custom_width_in > 0 && fields.custom_height_in > 0 );
			return fields.size_id && fields.material_id && fields.sticker_type && fields.shape && fields.quantity >= 1 && dimsOk;
		}

		function doRefreshStickerPricePreview() {
			var previewEl = viewEl.querySelector( '[data-yp-price-preview="sticker"]' );
			if ( ! previewEl ) {
				return; // Navigated away.
			}

			var priceEls = viewEl.querySelectorAll( '[data-yp-sticker-price]' );
			priceEls.forEach( function ( el ) { el.textContent = ''; } );

			var stickers = state.stickers.map( stickerPayload );
			var ready    = stickers.filter( stickerIsComplete );
			if ( ! ready.length ) {
				previewEl.innerHTML = '<p class="yp-field__hint">Choose a size, material, type, and shape to see pricing.</p>';
				return;
			}

			// Only finished stickers are priced (and count toward the
			// bulk tier), so a half-filled new card doesn't error out.
			YP.request( coreEndpoint( 'admin/manual-orders/sticker-pricing-preview' ), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { stickers: ready } )
			} )
				.then( function ( pricing ) {
					var errors = [];
					var next   = 0;
					stickers.forEach( function ( fields, index ) {
						if ( ! stickerIsComplete( fields ) ) {
							return;
						}
						var item = pricing.items[ next++ ] || {};
						if ( item.error ) {
							errors.push( ( stickers.length > 1 ? 'Sticker ' + ( index + 1 ) + ': ' : '' ) + item.error );
						} else if ( priceEls[ index ] ) {
							priceEls[ index ].textContent = '$' + item.total.toFixed( 2 );
						}
					} );
					var waiting = stickers.length - ready.length;
					previewEl.innerHTML =
						errors.map( function ( message ) { return '<p class="yp-form__error">' + YP.escapeHtml( message ) + '</p>'; } ).join( '' ) +
						'<p><strong>' + ( stickers.length > 1 ? 'Stickers total: $' : 'Total: $' ) + pricing.total.toFixed( 2 ) + '</strong></p>' +
						( waiting ? '<p class="yp-field__hint">' + waiting + ' sticker' + ( waiting > 1 ? 's' : '' ) + ' not priced yet. Choose a size, material, type, and shape.</p>' : '' );
				} )
				.catch( function ( error ) {
					previewEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		function renderStickerFileList( card, sticker ) {
			var listEl = card.querySelector( '[data-yp-sticker-file-list]' );
			if ( ! listEl ) {
				return;
			}
			listEl.innerHTML = sticker.uploads.map( function ( file, index ) {
				var status = file.error
					? '<span class="yp-form__error">' + YP.escapeHtml( file.error ) + '</span>'
					: ( file.id ? '<span>Uploaded</span>' : '<span>Uploading&hellip;</span>' );
				return '<li>' + YP.escapeHtml( file.name ) + ' — ' + status +
					' <button type="button" class="yp-row-action" data-yp-remove-sticker-file="' + index + '">Remove</button></li>';
			} ).join( '' );

			listEl.querySelectorAll( '[data-yp-remove-sticker-file]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					sticker.uploads.splice( parseInt( button.getAttribute( 'data-yp-remove-sticker-file' ), 10 ), 1 );
					renderStickerFileList( card, sticker );
				} );
			} );
		}

		// An upload finishing after its card was re-rendered (or moved by
		// removing an earlier sticker) still lands on the right sticker:
		// results go into the sticker object, then whichever card shows
		// that sticker now is redrawn.
		function redrawStickerFiles( sticker ) {
			var index = state.stickers.indexOf( sticker );
			var card  = index === -1 ? null : viewEl.querySelector( '[data-yp-sticker="' + index + '"]' );
			if ( card ) {
				renderStickerFileList( card, sticker );
			}
		}

		function wireStickerUploads( card, sticker ) {
			var filesInput = card.querySelector( '[data-yp-sticker-files]' );
			if ( ! filesInput ) {
				return;
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
				sticker.uploads = sticker.uploads.concat( placeholders );
				redrawStickerFiles( sticker );

				YP.request( coreEndpoint( 'custom-orders/uploads' ), {
					method: 'POST',
					body: formData
				} )
					.then( function ( data ) {
						( data.files || [] ).forEach( function ( result, i ) {
							var entry = sticker.uploads.indexOf( placeholders[ i ] );
							if ( entry === -1 ) {
								return;
							}
							if ( result.success ) {
								sticker.uploads[ entry ].id = result.id;
							} else {
								sticker.uploads[ entry ].error = result.message;
							}
						} );
						redrawStickerFiles( sticker );
					} )
					.catch( function () {
						placeholders.forEach( function ( placeholder ) {
							var entry = sticker.uploads.indexOf( placeholder );
							if ( entry !== -1 ) {
								sticker.uploads[ entry ].error = 'Upload failed.';
							}
						} );
						redrawStickerFiles( sticker );
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
					'<td data-label="Quantity" class="yp-tier-table__qty yp-stack-half"><input type="number" min="1" step="1" inputmode="numeric" data-row-quantity aria-label="Quantity" value="' + YP.escapeAttr( variant.quantity ) + '" />' + FILL_ALL_HTML + '</td>' +
					fieldSchema.map( function ( field ) {
						return '<td data-label="' + YP.escapeAttr( field.label ) + '"' + ( 'textarea' === field.type ? '' : ' class="yp-stack-half"' ) + '>' + templateFieldInputHtml( field, variant.values[ field.id ] ) + FILL_ALL_HTML + '</td>';
					} ).join( '' ) +
					'<td class="yp-stack-remove"><button type="button" class="yp-row-action" data-yp-remove-row aria-label="Remove label">&times;</button></td>' +
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
				tbody.querySelectorAll( 'input, select, textarea' ).forEach( function ( field ) {
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
					YP.request( yeffoprintAdminApp.wpApiUrl + 'yp_template?search=' + encodeURIComponent( term ) + '&status=publish&per_page=20&orderby=title&order=asc&context=edit' )
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
					return '<li><button type="button" class="yp-row-action" data-yp-pick-template="' + post.id + '">' + YP.escapeHtml( post.title.raw || post.title.rendered ) + '</button></li>';
				} ).join( '' );

				resultsEl.querySelectorAll( '[data-yp-pick-template]' ).forEach( function ( button, index ) {
					button.addEventListener( 'click', function () {
						pickTemplate( results[ index ].id, results[ index ].title.raw || results[ index ].title.rendered );
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
				'<table class="yp-tier-table yp-tier-table--items yp-stack-rows"><thead><tr><th>Quantity</th>' +
					data.field_schema.map( function ( field ) { return '<th>' + YP.escapeHtml( field.label ) + '</th>'; } ).join( '' ) +
					'<th></th></tr></thead>' +
					'<tbody data-yp-template-variants>' + ( state.draft.templateVariants || [ null ] ).map( function ( variant ) { return templateVariantRowHtml( variant, data.field_schema ); } ).join( '' ) + '</tbody>' +
				'</table>' +
				'<button type="button" class="wp-block-button__link is-style-outline yp-add-row-button" data-yp-add-template-row>+ Add another label</button>' +
				'<div class="yp-field"><label for="yp-mo-template-instructions">Instructions</label><textarea id="yp-mo-template-instructions" rows="2"></textarea></div>';

			el.querySelector( '[data-yp-change-template]' ).addEventListener( 'click', function () {
				state.selectedTemplate = null;
				state.templateData = null;
				state.draft.templateVariants = null;
				delete state.draft.fields[ 'yp-mo-template-size' ];
				delete state.draft.fields[ 'yp-mo-template-material' ];
				renderTemplatePanel();
				refreshTemplatePricePreview();
			} );

			var tbody = el.querySelector( '[data-yp-template-variants]' );
			wireRemoveButtons( tbody, refreshTemplatePricePreview );
			wireFillAll( tbody, refreshTemplatePricePreview );

			el.querySelector( '[data-yp-add-template-row]' ).addEventListener( 'click', function () {
				// Same as Custom Design's rows: carry the last row's quantity
				// over; the label text itself starts blank.
				var rows = readTemplateVariants( tbody, data.field_schema );
				var last = rows[ rows.length - 1 ];
				tbody.insertAdjacentHTML( 'beforeend', templateVariantRowHtml( { quantity: last && last.quantity ? last.quantity : 100, values: {} }, data.field_schema ) );
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

		/* ---------- Web Design Package (this revision) ---------- */

		function webDesignFieldsHtml() {
			// Same "priced or not" filter class-manual-order-creator.php's
			// own add_web_design_row() enforces server-side — only a
			// published package with a real Checkout Price is chargeable,
			// so an unpriced one is left out of the picker entirely rather
			// than offered and then rejected on submit.
			var pricedPackages = ( state.webDesignPackages || [] ).filter( function ( pkg ) {
				return pkg.meta && parseFloat( pkg.meta._yp_checkout_price ) > 0;
			} );

			if ( ! pricedPackages.length ) {
				return (
					'<div class="yp-panel">' +
						'<div class="yp-panel__head"><h2>Web Design Package details</h2></div>' +
						'<p class="yp-form__error">No packages have a Checkout Price set yet — set one on a package record (Web Design Packages) before charging for it.</p>' +
					'</div>'
				);
			}

			return (
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Web Design Package details</h2></div>' +
					'<div class="yp-field"><label for="yp-mo-web-design-package">Package</label><select id="yp-mo-web-design-package">' +
						'<option value="">Choose a package…</option>' +
						pricedPackages.map( function ( pkg ) {
							var price = parseFloat( pkg.meta._yp_checkout_price );
							return '<option value="' + pkg.id + '">' + YP.escapeHtml( pkg.title.raw || pkg.title.rendered ) + ' — $' + price.toFixed( 2 ) + '</option>';
						} ).join( '' ) +
					'</select></div>' +
					'<p class="yp-panel__hint">No proof approval and no shipping — a package purchase is a virtual service, not a physical print job.</p>' +
				'</div>'
			);
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
						'<div class="yp-field"><label for="yp-mo-' + prefix + '-first-name">First name</label><input type="text" id="yp-mo-' + prefix + '-first-name" data-yp-address-field="first_name" autocomplete="given-name" value="' + YP.escapeAttr( address.first_name ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-mo-' + prefix + '-last-name">Last name</label><input type="text" id="yp-mo-' + prefix + '-last-name" data-yp-address-field="last_name" autocomplete="family-name" value="' + YP.escapeAttr( address.last_name ) + '" /></div>' +
					'</div>' +
					'<div class="yp-field"><label for="yp-mo-' + prefix + '-address-1">Address line 1</label><input type="text" id="yp-mo-' + prefix + '-address-1" data-yp-address-field="address_1" autocomplete="address-line1" value="' + YP.escapeAttr( address.address_1 ) + '" /></div>' +
					'<div class="yp-field"><label for="yp-mo-' + prefix + '-address-2">Address line 2</label><input type="text" id="yp-mo-' + prefix + '-address-2" data-yp-address-field="address_2" autocomplete="address-line2" value="' + YP.escapeAttr( address.address_2 ) + '" /></div>' +
					'<div class="yp-form__row--three">' +
						'<div class="yp-field"><label for="yp-mo-' + prefix + '-city">City</label><input type="text" id="yp-mo-' + prefix + '-city" data-yp-address-field="city" autocomplete="address-level2" value="' + YP.escapeAttr( address.city ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-mo-' + prefix + '-state">State</label><input type="text" id="yp-mo-' + prefix + '-state" data-yp-address-field="state" autocomplete="address-level1" value="' + YP.escapeAttr( address.state ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-mo-' + prefix + '-postcode">ZIP / postal code</label><input type="text" id="yp-mo-' + prefix + '-postcode" data-yp-address-field="postcode" autocomplete="postal-code" value="' + YP.escapeAttr( address.postcode ) + '" /></div>' +
					'</div>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-mo-' + prefix + '-country">Country</label><input type="text" id="yp-mo-' + prefix + '-country" data-yp-address-field="country" autocomplete="country" autocapitalize="characters" maxlength="2" placeholder="US" value="' + YP.escapeAttr( address.country ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-mo-' + prefix + '-phone">Phone</label><input type="tel" id="yp-mo-' + prefix + '-phone" data-yp-address-field="phone" autocomplete="tel" value="' + YP.escapeAttr( address.phone ) + '" /></div>' +
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

					'<div class="yp-field yp-field--checkbox">' +
						'<input type="checkbox" id="yp-mo-customer-provides-address"' + ( s.customerProvidesAddress ? ' checked' : '' ) + ' />' +
						'<label for="yp-mo-customer-provides-address">Customer will provide their address when they pay</label>' +
					'</div>' +
					( s.customerProvidesAddress
						? '<p class="yp-panel__hint">They’ll be asked for it right on the payment page their emailed link takes them to — it saves straight onto this order once they submit it.</p>'
						: '' ) +

					'<div data-yp-ship-address-fields' + ( s.customerProvidesAddress ? ' style="display:none;"' : '' ) + '>' +
						addressFieldsHtml( 'ship', s.address ) +
						'<button type="button" class="wp-block-button__link is-style-outline yp-mo__verify" data-yp-verify-address>Verify address</button>' +
						'<div data-yp-verify-result></div>' +
					'</div>' +

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
							shippingMethodOptionsHtml() +
						'</select></div>' +
						'<p class="yp-panel__hint">“Customer picks” lets them choose on their payment link, from the options that ship to their address. Picking one here adds it to the invoice as a shipping line. Edit these options under Settings &rarr; Shipping.</p>'
						: '<p class="yp-panel__hint">No shipping options set up yet — add some under Settings &rarr; Shipping.</p>' ) +
				'</div>'
			);
		}

		/**
		 * Direct request: "restrict international shipping to just
		 * international customers and the other 2 to domestic customers."
		 * Only options whose "Ships to" (Settings → Shipping) fits the
		 * typed-in country are listed; all of them while the customer is
		 * providing the address themselves, since it isn't known yet (the
		 * payment page checks it then).
		 */
		function shippingOptionFits( option ) {
			var s       = state.shipping;
			var country = s.customerProvidesAddress ? '' : String( s.address.country || '' ).trim().toUpperCase();
			var home    = ( yeffoprintAdminApp.shippo && yeffoprintAdminApp.shippo.domesticCountry ) || 'US';

			if ( ! country || ! option.region || 'any' === option.region ) {
				return true;
			}
			return ( country === home ) === ( 'domestic' === option.region );
		}

		function shippingMethodOptionsHtml() {
			var s       = state.shipping;
			var options = ( yeffoprintAdminApp.shippo && yeffoprintAdminApp.shippo.manualOrderShippingOptions ) || [];

			var picked = options[ parseInt( s.selectedOptionIndex, 10 ) ];
			if ( picked && ! shippingOptionFits( picked ) ) {
				s.selectedOptionIndex = '';
			}

			return (
				'<option value=""' + ( '' === s.selectedOptionIndex ? ' selected' : '' ) + '>Customer picks when they pay</option>' +
				'<option value="none"' + ( 'none' === s.selectedOptionIndex ? ' selected' : '' ) + '>No shipping charge</option>' +
				options.map( function ( option, index ) {
					if ( ! shippingOptionFits( option ) ) {
						return '';
					}
					return '<option value="' + index + '"' + ( String( index ) === s.selectedOptionIndex ? ' selected' : '' ) + '>' + YP.escapeHtml( option.label ) + ' — $' + option.amount.toFixed( 2 ) + '</option>';
				} ).join( '' )
			);
		}

		function refreshShippingMethodSelect() {
			var methodSelect = viewEl.querySelector( '#yp-mo-shipping-method' );
			if ( methodSelect ) {
				methodSelect.innerHTML = shippingMethodOptionsHtml();
			}
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
					field.addEventListener( 'input', function () {
						readAddressState( prefix );
						if ( 'ship' === prefix && 'country' === field.getAttribute( 'data-yp-address-field' ) ) {
							refreshShippingMethodSelect();
						}
					} );
				} );
			} );

			var customerProvidesToggle = panel.querySelector( '#yp-mo-customer-provides-address' );
			customerProvidesToggle.addEventListener( 'change', function () {
				state.shipping.customerProvidesAddress = customerProvidesToggle.checked;
				panel.querySelector( '[data-yp-ship-address-fields]' ).style.display = customerProvidesToggle.checked ? 'none' : '';
				refreshShippingMethodSelect();
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
			var panel = viewEl.querySelector( '[data-yp-shipping-panel]' );
			if ( ! panel ) {
				return; // No physical item type active — see hasPhysicalItem in render().
			}
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

		function submitLabel() {
			return state.addToOrder ? 'Add to Order #' + YP.escapeHtml( String( state.addToOrder.number ) ) : 'Create Order';
		}

		function submit() {
			var statusEl     = viewEl.querySelector( '[data-yp-submit-status]' );
			var submitButton = viewEl.querySelector( '[data-yp-submit]' );

			// Direct request: "customers order custom design items mixed
			// with template items... order them at the same time." Every
			// active type below contributes its own nested key rather than
			// this body being shaped around exactly one order_type.
			var body = {
				customer: state.addToOrder ? null : customerPayload(),
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
				body.stickers = state.stickers.map( function ( sticker ) {
					var fields = stickerPayload( sticker );
					fields.instructions = sticker.instructions;
					fields.uploads = sticker.uploads.filter( function ( file ) { return file.id; } ).map( function ( file ) { return file.id; } );
					return fields;
				} );
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

			if ( state.activeTypes.web_design ) {
				body.web_design = {
					package_id: parseInt( ( viewEl.querySelector( '#yp-mo-web-design-package' ) || {} ).value, 10 ) || 0
				};
			}

			// Mirrors render()'s own hasPhysicalItem — the shipping panel
			// (and its fields) simply isn't in the DOM when nothing physical
			// is active, so there's nothing here to read.
			var hasPhysicalItem = state.activeTypes.custom_design || state.activeTypes.sticker || state.activeTypes.template;
			if ( hasPhysicalItem && ! state.addToOrder ) {
				body.customer_provides_address = state.shipping.customerProvidesAddress;
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
				body.customer_picks_shipping = '' === state.shipping.selectedOptionIndex;
			}

			submitButton.disabled = true;
			submitButton.textContent = state.addToOrder ? 'Adding…' : 'Creating…';
			statusEl.innerHTML = '';

			YP.request( coreEndpoint( state.addToOrder ? 'admin/manual-orders/' + state.addToOrder.id + '/items' : 'admin/manual-orders' ), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( body )
			} )
				.then( function ( result ) {
					// Add-to-order mode: straight back to the updated order.
					if ( state.addToOrder ) {
						submitButton.disabled = false;
						submitButton.innerHTML = submitLabel();
						window.location.hash = '#/order-history';
						if ( YP.openWcOrderDrawer ) {
							YP.openWcOrderDrawer( result.order_id );
						}
						return;
					}

					// Opens this same app's own order drawer, not the classic
					// WooCommerce edit screen (result.order_edit_url) — direct
					// report: after creating a Web Design order there was
					// "nowhere in the admin panel to add details," because
					// the Agreement/Staging Site/Go-Live panel only exists in
					// this drawer (app.js's renderWebDesignPanel()), and the
					// classic screen this used to link to has no idea it
					// exists. Same "never leave the app's own order view"
					// reasoning YP.openWcOrderDrawer's own docblock already
					// established elsewhere — this one spot just still linked
					// out. The drawer's own footer still links to the classic
					// screen for anyone who wants it.
					// The form stays filled in, so a second click would create a
					// duplicate order (and a second invoice email). Lock it and
					// offer a clean form instead.
					submitButton.disabled = true;
					submitButton.textContent = 'Order created';

					var links = '<a href="#" data-yp-view-order="' + result.order_id + '">View order</a>';
					// One "Add a proof" link per shell — an order can now
					// carry more than one (see the class docblock in
					// class-manual-order-creator.php).
					var typeCounts = {};
					( result.custom_orders || [] ).forEach( function ( customOrder ) {
						typeCounts[ customOrder.order_type ] = ( typeCounts[ customOrder.order_type ] || 0 ) + 1;
					} );
					var typeSeen = {};
					( result.custom_orders || [] ).forEach( function ( customOrder ) {
						var label = ORDER_TYPE_LABELS[ customOrder.order_type ] || customOrder.order_type;
						typeSeen[ customOrder.order_type ] = ( typeSeen[ customOrder.order_type ] || 0 ) + 1;
						if ( typeCounts[ customOrder.order_type ] > 1 ) {
							label += ' ' + typeSeen[ customOrder.order_type ];
						}
						links += ' &middot; <a href="#/orders/' + customOrder.id + '">Add a proof (' + YP.escapeHtml( label ) + ')</a>';
					} );

					// A Web Design Package order has no proof, no shipping —
					// the payment link is the one thing staff need to hand
					// the customer, same "readonly, click-to-select" pattern
					// as views/orders.js's own copy-link field.
					var paymentLinkHtml = result.payment_url
						? '<div class="yp-field"><label for="yp-mo-payment-link">Payment link</label>' +
							'<input type="text" id="yp-mo-payment-link" readonly onclick="this.select();" value="' + YP.escapeAttr( result.payment_url ) + '" style="width:100%;margin-top:0.35rem;padding:0.4rem 0.6rem;font-family:var(--wp--preset--font-family--mono);font-size:0.78rem;border:1.5px solid var(--wp--preset--color--light-gray);border-radius:var(--wp--custom--radius--control);" />' +
						'</div>'
						: '';

					links += ' &middot; <a href="#" data-yp-new-order>Start another order</a>';

					statusEl.innerHTML = '<p class="yp-panel__hint">Order created. ' + links + '</p>' + paymentLinkHtml;
					statusEl.querySelector( '[data-yp-new-order]' ).addEventListener( 'click', function ( event ) {
						event.preventDefault();
						// A different hash so the router builds a fresh, empty form.
						window.location.hash = '#/manual-order/new' === window.location.hash ? '#/manual-order' : '#/manual-order/new';
					} );

					var viewOrderLink = statusEl.querySelector( '[data-yp-view-order]' );
					if ( viewOrderLink && YP.openWcOrderDrawer ) {
						viewOrderLink.addEventListener( 'click', function ( event ) {
							event.preventDefault();
							YP.openWcOrderDrawer( result.order_id );
						} );
					}
				} )
				.catch( function ( error ) {
					submitButton.disabled = false;
					submitButton.innerHTML = submitLabel();
					statusEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}
	};
} )();
