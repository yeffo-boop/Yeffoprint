/**
 * Live Label Configurator (PROJECT_SPEC §10) — the signature feature.
 *
 * Vanilla JS, no framework/build step, per the spec's "richer JS is
 * justified [only in] the configurator" stance (this is that one
 * area). Single state object drives two preview renderers: Label View
 * is the live, per-keystroke WYSIWYG proof (field DOM built from
 * field_schema, updated on every input event); Vial View is a plain
 * reference photo of the vial with no field overlays at all — direct
 * request that live-as-you-type only ever show up on the label, not
 * the vial mockup. Both still read from the same state object and the
 * same controls pane (Architecture §4: "never a separate data
 * source") — Vial View just renders none of it as on-image text.
 *
 * Rendering strategy: structural panels (size/material options, field
 * inputs, variant cards) render once per data change that actually
 * changes their shape; per-keystroke updates only touch the specific
 * DOM nodes involved (preview text, counters, pricing) so focus is
 * never lost while typing — a full re-render on every keystroke would
 * reset cursor position in the field inputs.
 *
 * Pricing (Architecture §3): renderSummary() first shows an instant
 * client-side estimate (base + material/size adjustments already in
 * the REST payload, no discount tiers — the client doesn't know
 * those), then a debounced call to /pricing/calculate replaces it
 * with the authoritative, discount-aware breakdown. That server value
 * — never the client estimate — is what a future Add to Cart (Phase
 * 7) would submit. See docs/ARCHITECTURE.md §9.
 *
 * Bulk pricing table (direct request: "so customers can see the
 * savings if they order more... dynamic and show whatever tiered
 * pricing is assigned"): renderBulkPricingTable() reuses that same
 * "instant client estimate" precedent — it builds a per-tier price
 * preview straight from the schema's own base_unit_price/tiers plus
 * whichever size/material is currently selected, no extra request,
 * same as renderEstimatedSummary() above. It's purely informational;
 * the price a customer actually pays is still only ever the
 * authoritative /pricing/calculate result. See docs/ARCHITECTURE.md §9.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintConfigurator === 'undefined' || ! yeffoprintConfigurator.templateId ) {
		return;
	}

	var FIELD_BOX_WIDTH_RATIO = 0.86;
	var FIELD_BOX_HEIGHT_RATIO = 0.32;

	// Mirrors YeffoPrint_Field_Schema::CORNER_STYLE_OPTIONS (PHP) — a
	// fixed, non-admin-editable two-choice set, so no REST round trip is
	// needed to know these labels client-side (same reasoning ALIGNMENTS/
	// FORMATTING_RULES etc. never get sent to this page at all: they're
	// admin-editor-only concepts, this is the one closed-set choice a
	// customer actually picks from).
	var CORNER_STYLE_OPTIONS = { squared: 'Squared', rounded: 'Rounded' };

	var root = document.getElementById( 'yp-configurator' );
	if ( ! root ) {
		return;
	}

	var statusEl = root.querySelector( '.yp-configurator__status' );
	var layoutEl = root.querySelector( '.yp-configurator__layout' );
	var stageEl = root.querySelector( '[data-yp-stage]' );
	var overflowWarningEl = root.querySelector( '[data-yp-overflow-warning]' );
	var livePreviewNoteEl = root.querySelector( '[data-yp-live-preview-note]' );
	var descriptionEl = root.querySelector( '[data-yp-description]' );
	var titleEl = root.querySelector( '[data-yp-title]' );
	var sizeOptionsEl = root.querySelector( '[data-yp-size-options]' );
	var materialOptionsEl = root.querySelector( '[data-yp-material-options]' );
	var fieldInputsEl = root.querySelector( '[data-yp-field-inputs]' );
	var quantityEl = root.querySelector( '[data-yp-quantity]' );
	var variantCardsEl = root.querySelector( '[data-yp-variant-cards]' );
	var addVariantButton = root.querySelector( '[data-yp-add-variant]' );
	var summaryEl = root.querySelector( '[data-yp-summary]' );
	var stickyBar = document.querySelector( '[data-yp-sticky-bar]' );
	var stickyTotalEl = stickyBar ? stickyBar.querySelector( '[data-yp-sticky-total]' ) : null;
	var addToCartButtons = document.querySelectorAll( '[data-yp-add-to-cart]' );
	var saveDesignButton = root.querySelector( '[data-yp-save-design]' );

	var schema = null;
	var state = {
		view: 'label',
		sizeId: null,
		materialId: null,
		activeVariantIndex: 0,
		variants: [],
		editKey: null
	};
	var nextVariantId = 1;

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}

	function formatCurrency( amount ) {
		return '$' + amount.toFixed( 2 );
	}

	/* ---------- Load ---------- */

	function init() {
		var url = yeffoprintConfigurator.restUrl + 'templates/' + yeffoprintConfigurator.templateId + '/configurator';

		fetch( url )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'not-ok' );
				}
				return response.json();
			} )
			.then( onSchemaLoaded )
			.catch( onLoadError );
	}

	function onLoadError() {
		statusEl.textContent = "This design couldn't be loaded. Please refresh, or browse the full gallery instead.";
		statusEl.setAttribute( 'data-state', 'error' );
	}

	function onSchemaLoaded( data ) {
		schema = data;

		if ( ! schema.field_schema ) {
			schema.field_schema = [];
		}

		statusEl.hidden = true;
		layoutEl.hidden = false;

		titleEl.textContent = schema.title || '';
		document.title = schema.title ? schema.title + ' — YeffoPrint' : document.title;

		if ( descriptionEl ) {
			descriptionEl.textContent = schema.description || '';
			descriptionEl.hidden = ! schema.description;
		}

		var params = new URLSearchParams( window.location.search );
		var editKey = params.get( 'edit' );
		var reorderRef = params.get( 'reorder' ); // "<order_id>:<item_id>"
		var savedId = params.get( 'saved' );

		if ( editKey ) {
			// "Edit customization" (PROJECT_SPEC §14): rehydrates from a
			// live cart item; Add to Cart becomes an in-place update (see
			// submitAddToCart) rather than adding a second line item.
			loadExternalBatch(
				yeffoprintConfigurator.restUrl + 'cart/item/' + encodeURIComponent( editKey ),
				{},
				function ( item ) {
					state.editKey = editKey;
					hydrateFromBatch( item );
				}
			);
		} else if ( reorderRef && reorderRef.indexOf( ':' ) !== -1 ) {
			// Reorder (PROJECT_SPEC §16): "restore batch into configurator,
			// then edit before purchase" — rehydrates from a past order's
			// frozen line-item snapshot, but always as a fresh Add to Cart,
			// never a one-click re-cart. Needs a logged-in request (order
			// data isn't public the way a cart session is).
			var parts = reorderRef.split( ':' );
			loadExternalBatch(
				yeffoprintConfigurator.restUrl + 'orders/' + encodeURIComponent( parts[ 0 ] ) + '/items/' + encodeURIComponent( parts[ 1 ] ),
				{ headers: { 'X-WP-Nonce': yeffoprintConfigurator.nonce } },
				hydrateFromBatch
			);
		} else if ( savedId ) {
			// Saved Designs: same rehydration path as Edit/Reorder above,
			// just sourced from a design the customer previously saved to
			// their account instead of a cart item or order.
			loadExternalBatch(
				yeffoprintConfigurator.restUrl + 'saved-designs/' + encodeURIComponent( savedId ),
				{ headers: { 'X-WP-Nonce': yeffoprintConfigurator.nonce } },
				hydrateFromBatch
			);
		} else {
			applyDefaultState();
			finishInit();
		}
	}

	function applyDefaultState() {
		state.sizeId = schema.sizes && schema.sizes.length ? schema.sizes[ 0 ].id : null;
		state.materialId = firstAvailableMaterialId();
		state.variants = [ createVariant() ];
	}

	/**
	 * The first in-stock material, so a fresh page load never lands on
	 * an unselectable default (direct request: out-of-stock materials
	 * stay visible but can't be chosen). Falls back to the first
	 * material regardless of stock only if every single one is out —
	 * better than leaving nothing selected at all.
	 */
	function firstAvailableMaterialId() {
		if ( ! schema.materials || ! schema.materials.length ) {
			return null;
		}
		var available = schema.materials.filter( function ( m ) { return false !== m.in_stock; } );
		return ( available.length ? available[ 0 ] : schema.materials[ 0 ] ).id;
	}

	function hydrateFromBatch( item ) {
		state.sizeId = item.size_id ? parseInt( item.size_id, 10 ) : null;
		state.materialId = item.material_id ? parseInt( item.material_id, 10 ) : null;
		state.variants = item.variants.map( function ( variant ) {
			return {
				id: nextVariantId++,
				quantity: variant.quantity || 1,
				values: variant.values || {}
			};
		} );
	}

	function loadExternalBatch( url, fetchOptions, onSuccess ) {
		fetch( url, fetchOptions )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( item ) {
				if ( item && parseInt( item.template_id, 10 ) === schema.id && Array.isArray( item.variants ) && item.variants.length ) {
					onSuccess( item );
				} else {
					applyDefaultState();
				}
				finishInit();
			} )
			.catch( function () {
				applyDefaultState();
				finishInit();
			} );
	}

	function finishInit() {
		renderSizeOptions();
		renderMaterialOptions();
		renderFieldInputStructure();
		renderQuantityControl();
		renderVariantCards();
		renderStage();
		renderSummary();

		if ( stickyBar ) {
			stickyBar.hidden = false;
		}

		if ( state.editKey ) {
			addToCartButtons.forEach( function ( button ) {
				button.textContent = 'Update Cart';
			} );
		}
	}

	function createVariant() {
		var values = {};

		schema.field_schema.forEach( function ( field ) {
			values[ field.id ] = ( field.default || '' ).slice( 0, field.max_chars );
		} );

		return {
			id: nextVariantId++,
			quantity: schema.quantity_presets && schema.quantity_presets.length ? schema.quantity_presets[ 0 ] : 10,
			values: values
		};
	}

	function activeVariant() {
		return state.variants[ state.activeVariantIndex ];
	}

	/* ---------- Size / Material selectors ---------- */

	function renderSizeOptions() {
		sizeOptionsEl.innerHTML = schema.sizes.map( function ( size ) {
			var meta = size.price_adjustment ? ( size.price_adjustment > 0 ? '+' : '' ) + formatCurrency( size.price_adjustment ) : 'No adjustment';
			return optionPillHtml( 'size', size.id, size.name, meta, size.id === state.sizeId );
		} ).join( '' ) || '<p class="description">No compatible sizes configured yet.</p>';

		sizeOptionsEl.querySelectorAll( '[data-option-id]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				state.sizeId = parseInt( button.getAttribute( 'data-option-id' ), 10 );
				updateSelectedPill( sizeOptionsEl, state.sizeId );
				renderSummary();
			} );
		} );
	}

	function renderMaterialOptions() {
		materialOptionsEl.innerHTML = schema.materials.map( function ( material ) {
			var outOfStock = false === material.in_stock;
			var meta = outOfStock
				? 'Out of Stock'
				: ( material.price_adjustment ? ( material.price_adjustment > 0 ? '+' : '' ) + formatCurrency( material.price_adjustment ) : 'No adjustment' );
			var swatch = '<span class="yp-swatch-chip" style="' + ( material.swatch_url ? 'background-image:url(' + escapeHtml( material.swatch_url ) + ')' : '' ) + '"></span>';
			return optionPillHtml( 'material', material.id, material.name, meta, material.id === state.materialId, swatch, outOfStock );
		} ).join( '' ) || '<p class="description">No compatible materials configured yet.</p>';

		materialOptionsEl.querySelectorAll( '[data-option-id]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				state.materialId = parseInt( button.getAttribute( 'data-option-id' ), 10 );
				updateSelectedPill( materialOptionsEl, state.materialId );
				renderSummary();
			} );
		} );
	}

	/** `isDisabled` (out-of-stock materials only, so far) renders a real `disabled` button — nothing else needs to guard against it being clicked, since a disabled button never fires its own click event. */
	function optionPillHtml( group, id, name, meta, isSelected, leadingHtml, isDisabled ) {
		return (
			'<button type="button" role="radio" aria-checked="' + ( isSelected ? 'true' : 'false' ) + '" class="yp-option-pill' + ( isSelected ? ' is-selected' : '' ) + ( isDisabled ? ' yp-option-pill--out-of-stock' : '' ) + '" data-option-group="' + group + '" data-option-id="' + id + '"' + ( isDisabled ? ' disabled' : '' ) + '>' +
				( leadingHtml || '' ) +
				'<span class="yp-option-pill__name">' + escapeHtml( name ) + '</span>' +
				'<span class="yp-option-pill__meta">' + escapeHtml( meta ) + '</span>' +
			'</button>'
		);
	}

	function updateSelectedPill( container, selectedId ) {
		container.querySelectorAll( '[data-option-id]' ).forEach( function ( button ) {
			var isSelected = parseInt( button.getAttribute( 'data-option-id' ), 10 ) === selectedId;
			button.classList.toggle( 'is-selected', isSelected );
			button.setAttribute( 'aria-checked', isSelected ? 'true' : 'false' );
		} );
	}

	/* ---------- Customization fields ---------- */

	/**
	 * corner_style's own "?" — direct request: "have a modal pop up with
	 * a graphic example of what squared corners and rounded corners
	 * actually look like." Reuses the existing per-field tooltip
	 * mechanism (below) rather than the section-level drawer modal
	 * (site.js's data-yp-drawer-trigger/initDrawers()) — that scans the
	 * DOM once at page load, so a trigger created here, after the async
	 * schema fetch resolves, would never get wired up. This tooltip is
	 * bound at render time instead, same as every other field's, so it
	 * always works regardless of when the field itself was added. Two
	 * small SVG rectangles, corners drawn to the same size, one with a
	 * corner radius and one without — same "draw it to scale/shape
	 * rather than describe it in words" approach as size-info-modal.php.
	 */
	function cornerStyleDiagramHtml() {
		var examples = [ { label: 'Squared', rx: 0 }, { label: 'Rounded', rx: 10 } ];
		return (
			'<div class="yp-corner-style-diagram">' +
				examples.map( function ( example ) {
					return (
						'<span class="yp-corner-style-diagram__example">' +
							'<svg width="64" height="44" viewBox="0 0 64 44" aria-hidden="true" focusable="false">' +
								'<rect x="2" y="2" width="60" height="40" rx="' + example.rx + '" class="yp-corner-style-diagram__rect" />' +
							'</svg>' +
							'<span>' + example.label + '</span>' +
						'</span>'
					);
				} ).join( '' ) +
			'</div>'
		);
	}

	function cornerStyleOptionsHtml( field, value ) {
		return (
			'<div class="yp-option-group yp-corner-style-options" role="radiogroup" data-field-id="' + field.id + '">' +
				Object.keys( CORNER_STYLE_OPTIONS ).map( function ( key ) {
					var isSelected = key === value;
					return (
						'<button type="button" role="radio" aria-checked="' + ( isSelected ? 'true' : 'false' ) + '" class="yp-option-pill' + ( isSelected ? ' is-selected' : '' ) + '" data-corner-value="' + key + '">' +
							'<span class="yp-option-pill__name">' + CORNER_STYLE_OPTIONS[ key ] + '</span>' +
						'</button>'
					);
				} ).join( '' ) +
			'</div>'
		);
	}

	function renderFieldInputStructure() {
		fieldInputsEl.innerHTML = schema.field_schema.map( function ( field ) {
			var control;
			var value = ( activeVariant().values[ field.id ] ) || '';
			if ( 'color' === field.type ) {
				control = '<input type="color" data-field-id="' + field.id + '" class="yp-field__color-input" />';
			} else if ( 'qr_code' === field.type ) {
				control = '<input type="url" placeholder="https://" data-field-id="' + field.id + '" maxlength="' + field.max_chars + '" class="widefat" />';
			} else if ( 'textarea' === field.type ) {
				control = '<textarea data-field-id="' + field.id + '" maxlength="' + field.max_chars + '" rows="2" class="widefat"></textarea>';
			} else if ( 'corner_style' === field.type ) {
				control = cornerStyleOptionsHtml( field, value );
			} else {
				control = '<input type="text" data-field-id="' + field.id + '" maxlength="' + field.max_chars + '" class="widefat" />';
			}

			// corner_style's tooltip always exists (the diagram is
			// intrinsic to the type, not admin-authored content) even
			// when the admin never typed a tooltip/help text for this field.
			var hasTooltip = !! field.admin_description || 'corner_style' === field.type;
			var tooltip = hasTooltip
				? ' <button type="button" class="yp-field__tooltip-trigger" data-tooltip-trigger="' + field.id + '" aria-expanded="false" aria-controls="yp-field-tooltip-' + field.id + '" aria-label="More info about ' + escapeHtml( field.label ) + '">?</button>'
				: '';

			return (
				'<div class="yp-field">' +
					'<div class="yp-field__label-row">' +
						'<label for="yp-field-' + field.id + '">' + escapeHtml( field.label ) + ( field.required ? ' *' : '' ) + tooltip + '</label>' +
						( 'color' === field.type || 'corner_style' === field.type ? '' : '<span class="yp-field__counter" data-counter-for="' + field.id + '"></span>' ) +
					'</div>' +
					( hasTooltip
						? '<div class="yp-field__tooltip" id="yp-field-tooltip-' + field.id + '" hidden>' +
							( 'corner_style' === field.type ? cornerStyleDiagramHtml() : '' ) +
							( field.admin_description ? '<p>' + escapeHtml( field.admin_description ) + '</p>' : '' ) +
						'</div>'
						: '' ) +
					control.replace( '<textarea', '<textarea id="yp-field-' + field.id + '"' ).replace( '<input', '<input id="yp-field-' + field.id + '"' ) +
				'</div>'
			);
		} ).join( '' ) || '<p class="description">This design has no customization fields.</p>';

		fieldInputsEl.querySelectorAll( '[data-tooltip-trigger]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var tooltipEl = document.getElementById( 'yp-field-tooltip-' + button.getAttribute( 'data-tooltip-trigger' ) );
				if ( ! tooltipEl ) {
					return;
				}
				var isOpen = ! tooltipEl.hidden;
				tooltipEl.hidden = isOpen;
				button.setAttribute( 'aria-expanded', isOpen ? 'false' : 'true' );
			} );
		} );

		fieldInputsEl.querySelectorAll( '[data-field-id]' ).forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				var fieldId = input.getAttribute( 'data-field-id' );
				activeVariant().values[ fieldId ] = input.value;
				updateCounter( fieldId );
				updateStageField( fieldId );
				updateActiveVariantCardSummary();
			} );
		} );

		fieldInputsEl.querySelectorAll( '.yp-corner-style-options' ).forEach( function ( group ) {
			var fieldId = group.getAttribute( 'data-field-id' );
			group.querySelectorAll( '[data-corner-value]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					activeVariant().values[ fieldId ] = button.getAttribute( 'data-corner-value' );
					syncCornerStyleGroup( group, fieldId );
					updateActiveVariantCardSummary();
				} );
			} );
		} );

		syncFieldValuesToActiveVariant();
	}

	function syncCornerStyleGroup( group, fieldId ) {
		var value = activeVariant().values[ fieldId ] || '';
		group.querySelectorAll( '[data-corner-value]' ).forEach( function ( button ) {
			var isSelected = button.getAttribute( 'data-corner-value' ) === value;
			button.classList.toggle( 'is-selected', isSelected );
			button.setAttribute( 'aria-checked', isSelected ? 'true' : 'false' );
		} );
	}

	function updateCounter( fieldId ) {
		var field = schema.field_schema.filter( function ( f ) { return f.id === fieldId; } )[ 0 ];
		var counter = fieldInputsEl.querySelector( '[data-counter-for="' + fieldId + '"]' );
		if ( ! field || ! counter ) {
			return;
		}

		var length = ( activeVariant().values[ fieldId ] || '' ).length;
		counter.textContent = length + ' / ' + field.max_chars;
		counter.classList.toggle( 'is-over', length >= field.max_chars );
	}

	function syncFieldValuesToActiveVariant() {
		schema.field_schema.forEach( function ( field ) {
			if ( 'corner_style' === field.type ) {
				var group = fieldInputsEl.querySelector( '.yp-corner-style-options[data-field-id="' + field.id + '"]' );
				if ( group ) {
					syncCornerStyleGroup( group, field.id );
				}
				return;
			}

			var input = fieldInputsEl.querySelector( '[data-field-id="' + field.id + '"]' );
			if ( input ) {
				input.value = activeVariant().values[ field.id ] || '';
			}
			updateCounter( field.id );
		} );
	}

	/* ---------- Quantity ---------- */

	function renderQuantityControl() {
		var variant = activeVariant();
		var presets = schema.quantity_presets || [];

		quantityEl.innerHTML = presets.map( function ( amount ) {
			return '<button type="button" class="yp-quantity-preset' + ( amount === variant.quantity ? ' is-active' : '' ) + '" data-preset="' + amount + '">' + amount + '</button>';
		} ).join( '' ) +
			'<label class="screen-reader-text" for="yp-quantity-input">Custom quantity</label>' +
			'<input type="number" min="1" id="yp-quantity-input" class="yp-quantity-input" value="' + variant.quantity + '" />';

		quantityEl.querySelectorAll( '[data-preset]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				setActiveVariantQuantity( parseInt( button.getAttribute( 'data-preset' ), 10 ) );
			} );
		} );

		quantityEl.querySelector( '#yp-quantity-input' ).addEventListener( 'input', function ( event ) {
			var value = Math.max( 1, parseInt( event.target.value, 10 ) || 1 );
			setActiveVariantQuantity( value, /* skipInputRebuild */ true );
		} );
	}

	function setActiveVariantQuantity( quantity, skipInputRebuild ) {
		activeVariant().quantity = quantity;

		quantityEl.querySelectorAll( '[data-preset]' ).forEach( function ( button ) {
			button.classList.toggle( 'is-active', parseInt( button.getAttribute( 'data-preset' ), 10 ) === quantity );
		} );

		if ( ! skipInputRebuild ) {
			quantityEl.querySelector( '#yp-quantity-input' ).value = quantity;
		}

		renderVariantCards();
		renderSummary();
	}

	/* ---------- Variants (batch) ---------- */

	function variantCardSummaryHtml( variant, index ) {
		return '<span class="yp-variant-card__index">' + ( index + 1 ) + '</span>' +
			'<span class="yp-variant-card__text"><strong>Label ' + ( index + 1 ) + '</strong>' + escapeHtml( variantSummaryLabel( variant ) ) + ' &middot; ' + variant.quantity + ' units</span>';
	}

	// The full rebuild below only runs on switch/add/duplicate/remove/
	// quantity change — not on every keystroke in a field input, which
	// would tear down and re-attach every card's click listeners on
	// each character typed. That left a stale-looking bug: the active
	// batch's own card kept showing whatever text was on it when one of
	// those actions last ran, not what's actually been typed since —
	// invisible with a single batch (nothing to compare it to), obvious
	// with two or more. Called from the field-input handler alongside
	// updateStageField() so just this one card's label stays live
	// without rebuilding the whole list.
	function updateActiveVariantCardSummary() {
		var button = variantCardsEl.querySelector( '[data-switch-variant="' + state.activeVariantIndex + '"]' );
		if ( button ) {
			button.innerHTML = variantCardSummaryHtml( activeVariant(), state.activeVariantIndex );
		}
	}

	function renderVariantCards() {
		variantCardsEl.innerHTML = state.variants.map( function ( variant, index ) {
			return (
				'<div class="yp-variant-card' + ( index === state.activeVariantIndex ? ' is-active' : '' ) + '">' +
					'<button type="button" class="yp-variant-card__summary" data-switch-variant="' + index + '">' +
						variantCardSummaryHtml( variant, index ) +
					'</button>' +
					'<span class="yp-variant-card__actions">' +
						'<button type="button" class="button-link" data-duplicate-variant="' + index + '">Duplicate</button>' +
						( state.variants.length > 1 ? '<button type="button" class="button-link" data-remove-variant="' + index + '">Remove</button>' : '' ) +
					'</span>' +
				'</div>'
			);
		} ).join( '' );

		variantCardsEl.querySelectorAll( '[data-switch-variant]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				switchActiveVariant( parseInt( button.getAttribute( 'data-switch-variant' ), 10 ) );
			} );
		} );

		variantCardsEl.querySelectorAll( '[data-duplicate-variant]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				duplicateVariant( parseInt( button.getAttribute( 'data-duplicate-variant' ), 10 ) );
			} );
		} );

		variantCardsEl.querySelectorAll( '[data-remove-variant]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				removeVariant( parseInt( button.getAttribute( 'data-remove-variant' ), 10 ) );
			} );
		} );
	}

	function variantSummaryLabel( variant ) {
		var firstField = schema.field_schema[ 0 ];
		if ( ! firstField ) {
			return '';
		}
		var value = variant.values[ firstField.id ];
		if ( ! value ) {
			return '';
		}
		if ( 'corner_style' === firstField.type ) {
			value = CORNER_STYLE_OPTIONS[ value ] || value;
		}
		return ' — "' + value + '"';
	}

	function switchActiveVariant( index ) {
		state.activeVariantIndex = index;
		syncFieldValuesToActiveVariant();
		renderQuantityControl();
		renderVariantCards();
		renderStage();
	}

	function duplicateVariant( index ) {
		var source = state.variants[ index ];
		var copy = {
			id: nextVariantId++,
			quantity: source.quantity,
			values: Object.assign( {}, source.values )
		};
		state.variants.splice( index + 1, 0, copy );
		switchActiveVariant( index + 1 );
		renderSummary();
	}

	function removeVariant( index ) {
		if ( state.variants.length <= 1 ) {
			return;
		}
		state.variants.splice( index, 1 );
		state.activeVariantIndex = Math.min( state.activeVariantIndex, state.variants.length - 1 );
		switchActiveVariant( state.activeVariantIndex );
		renderSummary();
	}

	addVariantButton.addEventListener( 'click', function () {
		state.variants.push( createVariant() );
		switchActiveVariant( state.variants.length - 1 );
		renderSummary();
	} );

	/* ---------- Preview (Label View / Vial View) ---------- */

	// Keyed by field id (a schema can have more than one QR field, in
	// principle) so typing in one never cancels another's pending
	// refresh — same one-timer-per-concern shape as pricingDebounceTimer
	// below, just needing a map instead of a single variable.
	var qrPreviewDebounceTimers = {};

	/**
	 * Points a QR field's <img> at the plugin's own rendering endpoint
	 * (class-qr-controller.php) for the current URL, or clears it back
	 * to an empty placeholder state if the customer hasn't typed
	 * anything (or typed something too short/invalid to bother
	 * rendering yet — the server validates properly at cart/checkout;
	 * this is just "don't fire a request for an obviously-incomplete
	 * URL while someone is mid-keystroke").
	 */
	function setQrImageSrc( imgEl, url ) {
		var trimmed = ( url || '' ).trim();
		if ( trimmed.length < 4 ) {
			imgEl.removeAttribute( 'src' );
			imgEl.classList.add( 'is-empty' );
			return;
		}
		imgEl.classList.remove( 'is-empty' );
		imgEl.src = yeffoprintConfigurator.restUrl + 'qr?format=png&text=' + encodeURIComponent( trimmed );
	}

	// Rebuilds every field element — view toggle, variant switch, and
	// initial load, where every field's position/value can change at
	// once. Typing in one field only needs updateStageField() below.
	function renderStage() {
		var backgroundUrl = 'vial' === state.view ? schema.vial_mockup_url : schema.artwork_url;

		stageEl.innerHTML = backgroundUrl
			? '<img class="yp-stage__background" src="' + escapeHtml( backgroundUrl ) + '" alt="" />'
			: '';
		stageEl.setAttribute( 'data-view', state.view );

		// Admin kill switch (Dashboard → YeffoPrint → Settings → Label
		// Configurator) for whenever field alignment is still being
		// worked on — customers shouldn't see not-yet-correct live text
		// in the meantime. Only affects Label View; Vial View never had
		// live text to begin with, so the note below stays hidden there.
		var livePreviewSuppressed = false === schema.live_preview_enabled && 'label' === state.view;
		if ( livePreviewNoteEl ) {
			livePreviewNoteEl.hidden = ! livePreviewSuppressed;
		}

		// Vial View is a plain reference photo of the vial, not a live
		// proof — direct request: live-as-you-type editing should only
		// ever show up on Label View. No field elements get appended
		// here, which also means updateStageField() (the per-keystroke
		// path below) naturally no-ops while this view is active: it
		// looks a field up by data-field-id and bails out when nothing
		// matches. Live preview being switched off reuses this exact
		// same no-fields path.
		if ( 'vial' === state.view || livePreviewSuppressed ) {
			overflowWarningEl.hidden = true;
			return;
		}

		var variant = activeVariant();

		schema.field_schema.forEach( function ( field ) {
			if ( false === field.show_in_preview ) {
				// Direct request: some fields (e.g. a color picker
				// standing in for a cap color, not anything printed on
				// the label artwork) have nowhere sensible to render on
				// the visual stage — admin can opt a field out entirely
				// via the Template editor. The input control in the
				// controls pane below is unaffected; this only skips
				// creating a stage element, so the field stays fully
				// usable for input/pricing/order data.
				return;
			}

			if ( 'corner_style' === field.type ) {
				// A fulfillment choice about the label's physical die-cut,
				// not printed artwork — unlike every other type, there's no
				// show_in_preview toggle that would make sense to draw this
				// as text/swatch/QR on the stage, so it's always skipped
				// here regardless of that flag.
				return;
			}

			var el = document.createElement( 'qr_code' === field.type ? 'img' : 'div' );
			el.setAttribute( 'data-field-id', field.id );
			el.style.left = field.position.x + '%';
			el.style.top = field.position.y + '%';

			if ( 'color' === field.type ) {
				// A hex string as literal text would look wrong on the
				// label — this field's value is rendered as a small color
				// swatch instead, still positioned like any other field.
				el.className = 'yp-stage__field is-swatch';
				el.style.transform = 'translate(-50%, -50%)';
				el.style.background = variant.values[ field.id ] || '#cccccc';
			} else if ( 'qr_code' === field.type ) {
				// Renders an actual scannable code, not text — an <img>
				// pointed at the plugin's own QR-rendering endpoint
				// (class-qr-controller.php), sized as a square by qr_size
				// (% of stage width) and centered on position like the
				// color swatch above. Empty until the customer types a URL.
				el.className = 'yp-stage__field is-qr';
				el.alt = '';
				el.style.transform = 'translate(-50%, -50%)';
				el.style.width = ( field.qr_size || 20 ) + '%';
				setQrImageSrc( el, variant.values[ field.id ] || '' );
			} else {
				el.className = 'yp-stage__field' + ( 'textarea' === field.type ? ' is-multiline' : '' );
				el.style.textAlign = field.alignment;
				el.style.transform = anchorTransformFor( field.alignment );
				el.style.textTransform = textTransformFor( field.formatting_rule );
				el.style.color = field.text_color || '#000000';
				// Set per Template from the admin (class-template-editor.php),
				// loaded on this page via functions.php — direct request, so
				// the live preview reads as close to the actual printed
				// label as possible instead of always showing in the site's
				// own body font. Quoted, since a Google Fonts family name
				// can contain spaces; empty when unset, which leaves this
				// inheriting the theme's default rather than setting an
				// empty font-family.
				el.style.fontFamily = schema.preview_font ? '"' + schema.preview_font + '", sans-serif' : '';
				el.textContent = variant.values[ field.id ] || '';
			}

			stageEl.appendChild( el );
		} );

		refitStageFields();
	}

	function refitStageFields() {
		var stageRect = stageEl.getBoundingClientRect();
		var anyOverflow = false;

		schema.field_schema.forEach( function ( field ) {
			var el = stageEl.querySelector( '[data-field-id="' + field.id + '"]' );
			if ( ! el || 'color' === field.type || 'qr_code' === field.type ) {
				return; // A swatch/QR image has a fixed size — nothing to font-fit.
			}
			anyOverflow = fitText( el, field, stageRect.width, stageRect.height ) || anyOverflow;
		} );

		overflowWarningEl.hidden = ! anyOverflow;
	}

	// Typing on the hot path: updates and refits only the one field that
	// changed instead of tearing down and rebuilding every field's DOM
	// node on each keystroke (that was previously renderStage()'s job
	// here too, forcing a full layout reflow loop per field per
	// keystroke rather than one field's).
	function updateStageField( fieldId ) {
		var field = schema.field_schema.filter( function ( f ) { return f.id === fieldId; } )[ 0 ];
		var el = stageEl.querySelector( '[data-field-id="' + fieldId + '"]' );
		if ( ! field || ! el ) {
			return;
		}

		if ( 'color' === field.type ) {
			el.style.background = activeVariant().values[ fieldId ] || '#cccccc';
			return;
		}

		if ( 'qr_code' === field.type ) {
			window.clearTimeout( qrPreviewDebounceTimers[ fieldId ] );
			qrPreviewDebounceTimers[ fieldId ] = window.setTimeout( function () {
				setQrImageSrc( el, activeVariant().values[ fieldId ] || '' );
			}, 400 );
			return;
		}

		var stageRect = stageEl.getBoundingClientRect();
		el.textContent = activeVariant().values[ fieldId ] || '';
		var overflowing = fitText( el, field, stageRect.width, stageRect.height );

		var anyOverflow = overflowing || Array.prototype.some.call(
			stageEl.querySelectorAll( '.yp-stage__field' ),
			function ( otherEl ) {
				return otherEl !== el && otherEl.classList.contains( 'is-overflowing' );
			}
		);
		overflowWarningEl.hidden = ! anyOverflow;
	}

	function textTransformFor( rule ) {
		switch ( rule ) {
			case 'uppercase': return 'uppercase';
			case 'lowercase': return 'lowercase';
			case 'capitalize': return 'capitalize';
			default: return 'none';
		}
	}

	/**
	 * A field's `position.x/y` is the point in the admin's drag-to-
	 * position picker the field is anchored to — for "left justified"
	 * to actually mean "the text starts at that point" (rather than
	 * "that point is the text's centerpoint"), which edge of the box
	 * sits at x has to change with alignment, not just the text-align
	 * inside a box that's always centered on x regardless. Vertical
	 * stays centered on y either way — only PROJECT_SPEC's left/center/
	 * right alignments exist, no vertical equivalent.
	 *
	 * No Vial View scale-down anymore — this is only ever called from
	 * renderStage()'s field-building loop, which now never runs while
	 * Vial View is active.
	 */
	function anchorTransformFor( alignment ) {
		var anchorX = 'left' === alignment ? '0%' : 'right' === alignment ? '-100%' : '-50%';
		return 'translate(' + anchorX + ', -50%)';
	}

	function fitText( el, field, stageWidth, stageHeight ) {
		var maxWidth = stageWidth * FIELD_BOX_WIDTH_RATIO;
		var maxHeight = stageHeight * FIELD_BOX_HEIGHT_RATIO;
		var isMultiline = 'textarea' === field.type;

		el.style.maxWidth = maxWidth + 'px';
		if ( isMultiline ) {
			el.style.maxHeight = maxHeight + 'px';
		}

		// Each call sets fontSize and reads scrollWidth/Height, which
		// forces a synchronous layout — worth minimizing since this runs
		// on every keystroke. Shrinking a field's font never *increases*
		// its box (monotonic), so the largest non-overflowing integer
		// size can be binary-searched instead of walked down 1px at a
		// time, trading a handful of reflows for what could be dozens
		// on a wide font-size range.
		function overflowsAt( size ) {
			el.style.fontSize = size + 'px';
			var widthOverflow = el.scrollWidth > maxWidth + 1;
			var heightOverflow = isMultiline && el.scrollHeight > maxHeight + 1;
			return widthOverflow || heightOverflow;
		}

		var min = field.font_size_min;
		var max = field.font_size_max;
		var best;

		if ( ! overflowsAt( max ) ) {
			best = max;
		} else if ( overflowsAt( min ) ) {
			best = min;
		} else {
			var low = min;
			var high = max;
			while ( low < high ) {
				var mid = Math.ceil( ( low + high ) / 2 );
				if ( overflowsAt( mid ) ) {
					high = mid - 1;
				} else {
					low = mid;
				}
			}
			best = low;
		}

		var stillOverflowing = overflowsAt( best );
		el.classList.toggle( 'is-overflowing', stillOverflowing );
		return stillOverflowing;
	}

	var viewTabs = Array.prototype.slice.call( root.querySelectorAll( '[data-yp-view]' ) );

	function activateViewTab( tab, focusTab ) {
		state.view = tab.getAttribute( 'data-yp-view' );

		viewTabs.forEach( function ( t ) {
			var isActive = t === tab;
			t.classList.toggle( 'is-active', isActive );
			t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			t.setAttribute( 'tabindex', isActive ? '0' : '-1' );
		} );

		stageEl.setAttribute( 'aria-labelledby', tab.id );

		if ( focusTab ) {
			tab.focus();
		}

		renderStage();
	}

	viewTabs.forEach( function ( tab, index ) {
		tab.addEventListener( 'click', function () {
			activateViewTab( tab, false );
		} );

		// ARIA APG "tabs" pattern: arrow keys move focus between tabs
		// and activate the newly-focused one (roving tabindex above).
		tab.addEventListener( 'keydown', function ( event ) {
			var targetIndex = null;

			if ( 'ArrowRight' === event.key || 'ArrowDown' === event.key ) {
				targetIndex = ( index + 1 ) % viewTabs.length;
			} else if ( 'ArrowLeft' === event.key || 'ArrowUp' === event.key ) {
				targetIndex = ( index - 1 + viewTabs.length ) % viewTabs.length;
			} else if ( 'Home' === event.key ) {
				targetIndex = 0;
			} else if ( 'End' === event.key ) {
				targetIndex = viewTabs.length - 1;
			}

			if ( null !== targetIndex ) {
				event.preventDefault();
				activateViewTab( viewTabs[ targetIndex ], true );
			}
		} );
	} );

	/* ---------- Pricing ---------- */

	var pricingRequestId = 0;
	var pricingDebounceTimer = null;

	function unitAdjustments() {
		var material = ( schema.materials || [] ).filter( function ( m ) { return m.id === state.materialId; } )[ 0 ];
		var size = ( schema.sizes || [] ).filter( function ( s ) { return s.id === state.sizeId; } )[ 0 ];

		return {
			material: material ? material.price_adjustment : 0,
			size: size ? size.price_adjustment : 0
		};
	}

	function totalQuantity() {
		return state.variants.reduce( function ( sum, variant ) { return sum + variant.quantity; }, 0 );
	}

	function signedCurrency( amount ) {
		return ( amount > 0 ? '+' : '' ) + formatCurrency( amount );
	}

	function renderSummary() {
		renderEstimatedSummary();
		renderBulkPricingTable();
		window.clearTimeout( pricingDebounceTimer );
		pricingDebounceTimer = window.setTimeout( fetchAuthoritativePricing, 300 );
	}

	/**
	 * Direct request: "a bulk pricing table on the template order page
	 * so customers can see the savings if they order more... dynamic
	 * and show whatever tiered pricing is assigned." Same "instant
	 * client-side estimate" precedent renderEstimatedSummary() above
	 * already established — this table is purely informational (never
	 * what Add to Cart actually submits, that's still always the
	 * server-validated /pricing/calculate result via
	 * fetchAuthoritativePricing()), so computing it here from schema's
	 * own base_unit_price/tiers plus the currently selected size/
	 * material's adjustments — the exact same inputs
	 * YeffoPrint_Pricing_Rule::calculate() itself takes — needs no
	 * extra round trip and can update instantly as the customer changes
	 * size/material/quantity, same as the estimate above.
	 *
	 * V2 (direct follow-up report: "the ? doesn't work... I also always
	 * want it visible"): originally a small info-button next to Quantity
	 * that opened this table in a drawer modal. Dropped the modal
	 * entirely — this now renders straight into its own always-on
	 * section of the page (right below Quantity), so there's no click
	 * required and nothing that can silently fail to open. The section
	 * always shows once the page finishes loading; if the active Pricing
	 * Rule genuinely has no discount tiers configured yet, it shows a
	 * plain "not available yet" note instead of an empty table rather
	 * than disappearing.
	 */
	function renderBulkPricingTable() {
		var sectionEl = document.querySelector( '[data-yp-bulk-pricing-section]' );
		var el = document.querySelector( '[data-yp-bulk-pricing-table]' );
		var tiers = ( schema && schema.tiers ) || [];

		if ( sectionEl ) {
			sectionEl.hidden = false;
		}

		if ( ! el ) {
			return;
		}

		if ( ! tiers.length ) {
			el.innerHTML = '<p class="yp-bulk-pricing__empty">Bulk pricing isn\'t available for this design yet — check back soon.</p>';
			return;
		}

		var adjustments = unitAdjustments();
		var base = schema.base_unit_price;
		var fullPricePerUnit = base + adjustments.material + adjustments.size;
		var currentQty = totalQuantity();

		// One row for "no discount yet" (threshold 1) plus one per
		// configured tier — mirrors exactly how YeffoPrint_Pricing_Rule::calculate()
		// itself resolves a tier: highest threshold at or below the
		// quantity in question wins.
		var rows = [ { threshold: 1, discountPerUnit: 0 } ].concat( tiers.map( function ( tier ) {
			var discountPerUnit = 'percent' === tier.type
				? base * ( tier.value / 100 )
				: Math.max( 0, base - tier.value );
			return { threshold: tier.threshold, discountPerUnit: discountPerUnit };
		} ) );

		var activeIndex = 0;
		rows.forEach( function ( row, index ) {
			if ( currentQty >= row.threshold ) {
				activeIndex = index;
			}
		} );

		el.innerHTML =
			'<table class="yp-bulk-pricing-table">' +
				'<thead><tr><th>Quantity</th><th>Price per label</th><th>You save</th></tr></thead>' +
				'<tbody>' +
					rows.map( function ( row, index ) {
						var discountedBase = Math.max( 0, base - row.discountPerUnit );
						var perUnit = discountedBase + adjustments.material + adjustments.size;
						var savingsPct = fullPricePerUnit > 0 ? Math.round( ( row.discountPerUnit / fullPricePerUnit ) * 100 ) : 0;

						return (
							'<tr' + ( index === activeIndex ? ' class="is-active"' : '' ) + '>' +
								'<td>' + row.threshold + '+</td>' +
								'<td>' + formatCurrency( perUnit ) + '</td>' +
								'<td>' + ( savingsPct > 0 ? savingsPct + '%' : '—' ) + '</td>' +
							'</tr>'
						);
					} ).join( '' ) +
				'</tbody>' +
			'</table>';
	}

	function renderEstimatedSummary() {
		var adjustments = unitAdjustments();
		var perUnit = schema.base_unit_price + adjustments.material + adjustments.size;
		var qty = totalQuantity();

		renderBreakdown( {
			label: 'Estimated total',
			total: perUnit * qty,
			lines: [
				'Base: ' + formatCurrency( schema.base_unit_price ) + '/label',
				adjustments.material ? 'Material: ' + signedCurrency( adjustments.material ) + '/label' : null,
				adjustments.size ? 'Size: ' + signedCurrency( adjustments.size ) + '/label' : null,
				'Quantity: ' + qty
			],
			note: 'Confirming final price…'
		} );
	}

	function fetchAuthoritativePricing() {
		var qty = totalQuantity();
		var requestId = ++pricingRequestId;
		var url = yeffoprintConfigurator.restUrl + 'pricing/calculate?quantity=' + qty +
			( state.sizeId ? '&size_id=' + state.sizeId : '' ) +
			( state.materialId ? '&material_id=' + state.materialId : '' ) +
			// Editing a batch already in the cart: exclude its own (pre-edit)
			// quantity from the bulk-discount count, or it'd double-count
			// against the new quantity being previewed here.
			( state.editKey ? '&exclude_cart_item_key=' + encodeURIComponent( state.editKey ) : '' );

		fetch( url )
			.then( function ( response ) {
				return response.ok ? response.json() : Promise.reject( new Error( 'pricing-request-failed' ) );
			} )
			.then( function ( data ) {
				if ( requestId === pricingRequestId ) {
					renderAuthoritativeSummary( data );
				}
			} )
			.catch( function () {
				if ( requestId === pricingRequestId ) {
					var note = summaryEl.querySelector( '.yp-configurator__summary-note' );
					if ( note ) {
						note.textContent = "Couldn't confirm final pricing — showing an estimate.";
					}
				}
			} );
	}

	function renderAuthoritativeSummary( data ) {
		renderBreakdown( {
			label: 'Total',
			total: data.total,
			lines: [
				'Base: ' + formatCurrency( data.base_unit_price ) + '/label',
				data.material_adjustment ? 'Material: ' + signedCurrency( data.material_adjustment ) + '/label' : null,
				data.size_adjustment ? 'Size: ' + signedCurrency( data.size_adjustment ) + '/label' : null,
				'Quantity: ' + data.quantity,
				data.applied_tier ? 'Bulk discount: −' + formatCurrency( data.discount_per_unit ) + '/label' : null
			],
			note: 'Before shipping — confirmed at checkout.'
		} );
	}

	function renderBreakdown( summary ) {
		var lines = summary.lines.filter( function ( line ) { return line; } );

		summaryEl.innerHTML =
			'<div class="yp-configurator__summary-row">' +
				'<span>' + escapeHtml( summary.label ) + '</span>' +
				'<strong>' + formatCurrency( summary.total ) + '</strong>' +
			'</div>' +
			'<ul class="yp-configurator__summary-lines">' +
				lines.map( function ( line ) { return '<li>' + escapeHtml( line ) + '</li>'; } ).join( '' ) +
			'</ul>' +
			'<small class="yp-configurator__summary-note">' + escapeHtml( summary.note ) + '</small>';

		if ( stickyTotalEl ) {
			stickyTotalEl.textContent = formatCurrency( summary.total ) + ' (' + totalQuantity() + ' labels)';
		}
	}

	/* ---------- Add to Cart ---------- */

	var cartStatusEl = null;

	function showCartStatus( message, isError ) {
		if ( ! cartStatusEl ) {
			cartStatusEl = document.createElement( 'p' );
			cartStatusEl.className = 'yp-configurator__cart-status';
			summaryEl.insertAdjacentElement( 'afterend', cartStatusEl );
		}
		cartStatusEl.textContent = message;
		cartStatusEl.classList.toggle( 'is-error', !! isError );
	}

	function clearCartStatus() {
		if ( cartStatusEl ) {
			cartStatusEl.remove();
			cartStatusEl = null;
		}
	}

	// Both this endpoint's own explicit nonce check (class-rest-
	// security.php) and WordPress core's own cookie/nonce check (which
	// runs even earlier, before any endpoint code) reject the same
	// underlying problem — a stale nonce that no longer matches the
	// visitor's actual session — under two different error codes.
	var NONCE_ERROR_CODES = [ 'rest_cookie_invalid_nonce', 'yeffoprint_invalid_nonce' ];

	function submitAddToCart( isRetry ) {
		clearCartStatus();
		addToCartButtons.forEach( function ( button ) {
			button.disabled = true;
		} );

		var payload = {
			template_id: schema.id,
			size_id: state.sizeId,
			material_id: state.materialId,
			variants: state.variants.map( function ( variant ) {
				return { quantity: variant.quantity, values: variant.values };
			} )
		};

		if ( state.editKey ) {
			payload.edit_key = state.editKey;
		}

		fetch( yeffoprintConfigurator.restUrl + 'cart/add', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': yeffoprintConfigurator.nonce },
			body: JSON.stringify( payload )
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok && ! isRetry && result.data && NONCE_ERROR_CODES.indexOf( result.data.code ) !== -1 ) {
					// The nonce baked into this page at load time no
					// longer matches the visitor's session — most often
					// because the page itself was served from a cache
					// that predates it (functions.php has the full
					// reasoning). Fetching a fresh one only needs the
					// *current*, still-valid session cookie, so this
					// recovers silently instead of surfacing an error a
					// visitor would have no way to understand or act on.
					fetchFreshNonceAndRetry();
					return;
				}

				addToCartButtons.forEach( function ( button ) {
					button.disabled = false;
				} );

				if ( ! result.ok ) {
					showCartStatus( ( result.data && result.data.message ) || "Couldn't add this to your cart.", true );
					return;
				}

				document.dispatchEvent( new CustomEvent( 'yp:cart-updated', {
					detail: {
						count: result.data.cart_count,
						drawerHtml: result.data.drawer_html
					}
				} ) );

				if ( state.editKey ) {
					showCartStatus( 'Cart updated.', false );
				}
			} )
			.catch( function () {
				addToCartButtons.forEach( function ( button ) {
					button.disabled = false;
				} );
				showCartStatus( "Couldn't reach the server — please try again.", true );
			} );
	}

	function fetchFreshNonceAndRetry() {
		fetch( yeffoprintConfigurator.restUrl + 'session/nonce' )
			.then( function ( response ) {
				return response.ok ? response.json() : Promise.reject( new Error( 'nonce-refresh-failed' ) );
			} )
			.then( function ( data ) {
				yeffoprintConfigurator.nonce = data.nonce;
				submitAddToCart( /* isRetry */ true );
			} )
			.catch( function () {
				addToCartButtons.forEach( function ( button ) {
					button.disabled = false;
				} );
				showCartStatus( 'Your session has expired — please refresh the page and try again.', true );
			} );
	}

	addToCartButtons.forEach( function ( button ) {
		// Not `addEventListener( 'click', submitAddToCart )` directly —
		// that would pass the click Event itself as submitAddToCart's
		// first argument (isRetry), which is truthy, making every fresh
		// click look like a retry and skip the one-time nonce-refresh
		// path above entirely.
		button.addEventListener( 'click', function () {
			submitAddToCart( false );
		} );
	} );

	/* ---------- Save this design ---------- */
	// Saved Designs needs an account — there's nothing to attach an
	// anonymous save to. The button stays visible either way (rather
	// than being omitted server-side, which templates/*.html can't do)
	// and just relabels/redirects to login instead of no-op'ing.

	if ( saveDesignButton ) {
		if ( ! yeffoprintConfigurator.isLoggedIn ) {
			saveDesignButton.textContent = 'Log in to save this design';
		}

		saveDesignButton.addEventListener( 'click', function () {
			if ( ! yeffoprintConfigurator.isLoggedIn ) {
				window.location.href = yeffoprintConfigurator.accountUrl;
				return;
			}

			clearCartStatus();
			saveDesignButton.disabled = true;

			var payload = {
				template_id: schema.id,
				size_id: state.sizeId,
				material_id: state.materialId,
				variants: state.variants.map( function ( variant ) {
					return { quantity: variant.quantity, values: variant.values };
				} )
			};

			fetch( yeffoprintConfigurator.restUrl + 'saved-designs', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': yeffoprintConfigurator.nonce },
				body: JSON.stringify( payload )
			} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					saveDesignButton.disabled = false;

					if ( ! result.ok ) {
						showCartStatus( ( result.data && result.data.message ) || "Couldn't save this design.", true );
						return;
					}

					showCartStatus( 'Design saved — find it under Saved Designs in My Account.', false );
				} )
				.catch( function () {
					saveDesignButton.disabled = false;
					showCartStatus( "Couldn't reach the server — please try again.", true );
				} );
		} );
	}

	init();
} )();
