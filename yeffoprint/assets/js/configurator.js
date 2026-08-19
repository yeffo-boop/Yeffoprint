/**
 * Live Label Configurator (PROJECT_SPEC §10) — the signature feature.
 *
 * Vanilla JS, no framework/build step, per the spec's "richer JS is
 * justified [only in] the configurator" stance (this is that one
 * area). Single state object drives two synced preview renderers
 * (Label View / Vial View — same field DOM, just a different
 * background image and a CSS scale on Vial View) plus the controls
 * pane, matching Architecture §4: "never a separate data source."
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
 */
( function () {
	'use strict';

	if ( typeof yeffoprintConfigurator === 'undefined' || ! yeffoprintConfigurator.templateId ) {
		return;
	}

	var FIELD_BOX_WIDTH_RATIO = 0.86;
	var FIELD_BOX_HEIGHT_RATIO = 0.32;

	var root = document.getElementById( 'yp-configurator' );
	if ( ! root ) {
		return;
	}

	var statusEl = root.querySelector( '.yp-configurator__status' );
	var layoutEl = root.querySelector( '.yp-configurator__layout' );
	var stageEl = root.querySelector( '[data-yp-stage]' );
	var overflowWarningEl = root.querySelector( '[data-yp-overflow-warning]' );
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
		state.materialId = schema.materials && schema.materials.length ? schema.materials[ 0 ].id : null;
		state.variants = [ createVariant() ];
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
			quantity: schema.quantity_presets && schema.quantity_presets.length ? schema.quantity_presets[ 0 ] : 25,
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
			var meta = material.price_adjustment ? ( material.price_adjustment > 0 ? '+' : '' ) + formatCurrency( material.price_adjustment ) : 'No adjustment';
			var swatch = '<span class="yp-swatch-chip" style="' + ( material.swatch_url ? 'background-image:url(' + escapeHtml( material.swatch_url ) + ')' : '' ) + '"></span>';
			return optionPillHtml( 'material', material.id, material.name, meta, material.id === state.materialId, swatch );
		} ).join( '' ) || '<p class="description">No compatible materials configured yet.</p>';

		materialOptionsEl.querySelectorAll( '[data-option-id]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				state.materialId = parseInt( button.getAttribute( 'data-option-id' ), 10 );
				updateSelectedPill( materialOptionsEl, state.materialId );
				renderSummary();
			} );
		} );
	}

	function optionPillHtml( group, id, name, meta, isSelected, leadingHtml ) {
		return (
			'<button type="button" role="radio" aria-checked="' + ( isSelected ? 'true' : 'false' ) + '" class="yp-option-pill' + ( isSelected ? ' is-selected' : '' ) + '" data-option-group="' + group + '" data-option-id="' + id + '">' +
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

	function renderFieldInputStructure() {
		fieldInputsEl.innerHTML = schema.field_schema.map( function ( field ) {
			var control = 'textarea' === field.type
				? '<textarea data-field-id="' + field.id + '" maxlength="' + field.max_chars + '" rows="2" class="widefat"></textarea>'
				: '<input type="text" data-field-id="' + field.id + '" maxlength="' + field.max_chars + '" class="widefat" />';

			return (
				'<div class="yp-field">' +
					'<div class="yp-field__label-row">' +
						'<label for="yp-field-' + field.id + '">' + escapeHtml( field.label ) + ( field.required ? ' *' : '' ) + '</label>' +
						'<span class="yp-field__counter" data-counter-for="' + field.id + '"></span>' +
					'</div>' +
					control.replace( '<textarea', '<textarea id="yp-field-' + field.id + '"' ).replace( '<input', '<input id="yp-field-' + field.id + '"' ) +
					( field.admin_description ? '<p class="description">' + escapeHtml( field.admin_description ) + '</p>' : '' ) +
				'</div>'
			);
		} ).join( '' ) || '<p class="description">This design has no customization fields.</p>';

		fieldInputsEl.querySelectorAll( '[data-field-id]' ).forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				var fieldId = input.getAttribute( 'data-field-id' );
				activeVariant().values[ fieldId ] = input.value;
				updateCounter( fieldId );
				updateStageField( fieldId );
			} );
		} );

		syncFieldValuesToActiveVariant();
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
		var variant = activeVariant();

		schema.field_schema.forEach( function ( field ) {
			var input = fieldInputsEl.querySelector( '[data-field-id="' + field.id + '"]' );
			if ( input ) {
				input.value = variant.values[ field.id ] || '';
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

	function renderVariantCards() {
		variantCardsEl.innerHTML = state.variants.map( function ( variant, index ) {
			var label = variantSummaryLabel( variant );
			return (
				'<div class="yp-variant-card' + ( index === state.activeVariantIndex ? ' is-active' : '' ) + '">' +
					'<button type="button" class="yp-variant-card__summary" data-switch-variant="' + index + '">' +
						'<strong>Label ' + ( index + 1 ) + '</strong>' + escapeHtml( label ) + ' &middot; ' + variant.quantity + ' units' +
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
		return value ? ' — "' + value + '"' : '';
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

	// Rebuilds every field element — view toggle, variant switch, and
	// initial load, where every field's position/value can change at
	// once. Typing in one field only needs updateStageField() below.
	function renderStage() {
		var backgroundUrl = 'vial' === state.view ? schema.vial_mockup_url : schema.artwork_url;

		stageEl.innerHTML = backgroundUrl
			? '<img class="yp-stage__background" src="' + escapeHtml( backgroundUrl ) + '" alt="" />'
			: '';
		stageEl.setAttribute( 'data-view', state.view );

		var variant = activeVariant();

		schema.field_schema.forEach( function ( field ) {
			var el = document.createElement( 'div' );
			el.className = 'yp-stage__field' + ( 'textarea' === field.type ? ' is-multiline' : '' );
			el.setAttribute( 'data-field-id', field.id );
			el.style.left = field.position.x + '%';
			el.style.top = field.position.y + '%';
			el.style.textAlign = field.alignment;
			el.style.textTransform = textTransformFor( field.formatting_rule );
			el.style.color = field.text_color || '#000000';
			el.textContent = variant.values[ field.id ] || '';
			stageEl.appendChild( el );
		} );

		refitStageFields();
	}

	function refitStageFields() {
		var stageRect = stageEl.getBoundingClientRect();
		var anyOverflow = false;

		schema.field_schema.forEach( function ( field ) {
			var el = stageEl.querySelector( '[data-field-id="' + field.id + '"]' );
			if ( ! el ) {
				return;
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
		window.clearTimeout( pricingDebounceTimer );
		pricingDebounceTimer = window.setTimeout( fetchAuthoritativePricing, 300 );
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

	function submitAddToCart() {
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

	addToCartButtons.forEach( function ( button ) {
		button.addEventListener( 'click', submitAddToCart );
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
