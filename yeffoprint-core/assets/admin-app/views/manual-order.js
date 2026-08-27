/**
 * Manual order creation (docs/ARCHITECTURE.md) — staff key in a real
 * order for a customer directly from the admin app, direct request:
 * "am I able to make a custom order manually that also is with a proof
 * to be approved by the customer?", broadened to "manually create any
 * orders, not just custom orders."
 *
 * Phase A (this file, for now): Custom Design orders only. The other
 * two tabs are visible-but-disabled placeholders so the nav/IA is final
 * from day one, same idiom app.js's own SECTIONS placeholder view
 * already uses for a not-yet-shipped section.
 *
 * Reuses the exact same public endpoints the customer-facing Custom
 * Design form uses for its own picker options and live price preview
 * (`GET /custom-orders/options`, `POST /custom-orders/pricing-preview`,
 * class-custom-order-controller.php) — no new pricing/options endpoint
 * for this screen; only order creation itself
 * (`POST /admin/manual-orders`) is new.
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
			options: null,
			selectedCustomer: null, // { id, display_name, email }
			newCustomerMode: false
		};

		viewEl.innerHTML = '<p class="yp-app__intro">Loading&hellip;</p>';

		YP.request( coreEndpoint( 'custom-orders/options' ) )
			.then( function ( options ) {
				state.options = options;
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
					'<div class="yp-form__row">' +
						'<button type="button" class="wp-block-button__link is-style-accent" data-yp-order-type="custom_design">Custom Design</button>' +
						'<button type="button" class="wp-block-button__link is-style-outline" disabled title="Coming soon">Custom Sticker</button>' +
						'<button type="button" class="wp-block-button__link is-style-outline" disabled title="Coming soon">Template Label</button>' +
					'</div>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Customer</h2></div>' +
					'<div data-yp-customer-picker></div>' +
				'</div>' +

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
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Price</h2></div>' +
					'<div data-yp-price-preview><p class="yp-field__hint">Add a label to see pricing.</p></div>' +
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

			viewEl.querySelector( '[data-yp-submit]' ).addEventListener( 'click', submit );
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

		function submit() {
			var statusEl     = viewEl.querySelector( '[data-yp-submit-status]' );
			var submitButton = viewEl.querySelector( '[data-yp-submit]' );
			var batchBody    = viewEl.querySelector( '[data-yp-batch]' );

			var body = {
				order_type: 'custom_design',
				customer: customerPayload(),
				brand_name: viewEl.querySelector( '#yp-mo-brand' ).value,
				batch: readBatchRows( batchBody ),
				style_notes: viewEl.querySelector( '#yp-mo-style-notes' ).value,
				instructions: viewEl.querySelector( '#yp-mo-instructions' ).value,
				requires_proof: viewEl.querySelector( '#yp-mo-requires-proof' ).checked
			};

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
