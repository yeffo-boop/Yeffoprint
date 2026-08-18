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
 * Pricing here is provisional only (base + material/size adjustments
 * already in the REST payload) — no bulk-discount tiers yet, since
 * PricingRule / the authoritative server calc is Phase 6. See
 * docs/ARCHITECTURE.md §9.
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

	var schema = null;
	var state = {
		view: 'label',
		sizeId: null,
		materialId: null,
		activeVariantIndex: 0,
		variants: []
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

		state.sizeId = schema.sizes && schema.sizes.length ? schema.sizes[ 0 ].id : null;
		state.materialId = schema.materials && schema.materials.length ? schema.materials[ 0 ].id : null;
		state.variants = [ createVariant() ];

		statusEl.hidden = true;
		layoutEl.hidden = false;

		titleEl.textContent = schema.title || '';
		document.title = schema.title ? schema.title + ' — YeffoPrint' : document.title;

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
			'<button type="button" class="yp-option-pill' + ( isSelected ? ' is-selected' : '' ) + '" data-option-group="' + group + '" data-option-id="' + id + '">' +
				( leadingHtml || '' ) +
				'<span class="yp-option-pill__name">' + escapeHtml( name ) + '</span>' +
				'<span class="yp-option-pill__meta">' + escapeHtml( meta ) + '</span>' +
			'</button>'
		);
	}

	function updateSelectedPill( container, selectedId ) {
		container.querySelectorAll( '[data-option-id]' ).forEach( function ( button ) {
			button.classList.toggle( 'is-selected', parseInt( button.getAttribute( 'data-option-id' ), 10 ) === selectedId );
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
				renderStage();
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

	function renderStage() {
		var backgroundUrl = 'vial' === state.view ? schema.vial_mockup_url : schema.artwork_url;

		stageEl.innerHTML = backgroundUrl
			? '<img class="yp-stage__background" src="' + escapeHtml( backgroundUrl ) + '" alt="" />'
			: '';
		stageEl.setAttribute( 'data-view', state.view );

		var stageRect = stageEl.getBoundingClientRect();
		var variant = activeVariant();
		var anyOverflow = false;

		schema.field_schema.forEach( function ( field ) {
			var el = document.createElement( 'div' );
			el.className = 'yp-stage__field' + ( 'textarea' === field.type ? ' is-multiline' : '' );
			el.style.left = field.position.x + '%';
			el.style.top = field.position.y + '%';
			el.style.textAlign = field.alignment;
			el.style.textTransform = textTransformFor( field.formatting_rule );
			el.textContent = variant.values[ field.id ] || '';
			stageEl.appendChild( el );

			var overflowing = fitText( el, field, stageRect.width, stageRect.height );
			anyOverflow = anyOverflow || overflowing;
		} );

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
		var size = field.font_size_max;

		el.style.maxWidth = maxWidth + 'px';
		if ( isMultiline ) {
			el.style.maxHeight = maxHeight + 'px';
		}

		function overflowing() {
			var widthOverflow = el.scrollWidth > maxWidth + 1;
			var heightOverflow = isMultiline && el.scrollHeight > maxHeight + 1;
			return widthOverflow || heightOverflow;
		}

		el.style.fontSize = size + 'px';
		while ( size > field.font_size_min && overflowing() ) {
			size -= 1;
			el.style.fontSize = size + 'px';
		}

		var stillOverflowing = overflowing();
		el.classList.toggle( 'is-overflowing', stillOverflowing );
		return stillOverflowing;
	}

	root.querySelectorAll( '[data-yp-view]' ).forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			state.view = tab.getAttribute( 'data-yp-view' );

			root.querySelectorAll( '[data-yp-view]' ).forEach( function ( t ) {
				var isActive = t === tab;
				t.classList.toggle( 'is-active', isActive );
				t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			} );

			renderStage();
		} );
	} );

	/* ---------- Pricing (provisional — Phase 6 replaces this) ---------- */

	function unitPrice() {
		var material = ( schema.materials || [] ).filter( function ( m ) { return m.id === state.materialId; } )[ 0 ];
		var size = ( schema.sizes || [] ).filter( function ( s ) { return s.id === state.sizeId; } )[ 0 ];

		return schema.base_unit_price + ( material ? material.price_adjustment : 0 ) + ( size ? size.price_adjustment : 0 );
	}

	function totalQuantity() {
		return state.variants.reduce( function ( sum, variant ) { return sum + variant.quantity; }, 0 );
	}

	function renderSummary() {
		var perUnit = unitPrice();
		var qty = totalQuantity();
		var total = perUnit * qty;
		var text = formatCurrency( total );

		summaryEl.innerHTML = 'Estimated total <strong style="float:right;">' + text + '</strong>' +
			'<small>' + qty + ' labels &times; ' + formatCurrency( perUnit ) + ' — before shipping. Final price is confirmed at checkout.</small>';

		if ( stickyTotalEl ) {
			stickyTotalEl.textContent = text + ' (' + qty + ' labels)';
		}
	}

	addToCartButtons.forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var status = document.createElement( 'p' );
			status.className = 'yp-configurator__cart-status';
			status.textContent = "Cart isn't connected yet — check back soon.";
			button.insertAdjacentElement( 'afterend', status );
			window.setTimeout( function () {
				status.remove();
			}, 4000 );
		} );
	} );

	init();
} )();
