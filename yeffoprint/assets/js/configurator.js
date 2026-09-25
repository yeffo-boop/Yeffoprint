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
	var skeletonEl = root.querySelector( '[data-yp-skeleton]' );
	var layoutEl = root.querySelector( '.yp-configurator__layout' );
	var stageEl = root.querySelector( '[data-yp-stage]' );
	var overflowWarningEl = root.querySelector( '[data-yp-overflow-warning]' );
	var livePreviewNoteEl = root.querySelector( '[data-yp-live-preview-note]' );
	var descriptionEl = root.querySelector( '[data-yp-description]' );
	var titleEl = root.querySelector( '[data-yp-title]' );
	var sizeOptionsEl = root.querySelector( '[data-yp-size-options]' );
	var materialOptionsEl = root.querySelector( '[data-yp-material-options]' );
	var fieldInputsEl = root.querySelector( '[data-yp-field-inputs]' );
	var colorSectionEl = root.querySelector( '[data-yp-section="colors"]' );
	var colorChoicesEl = root.querySelector( '[data-yp-color-choices]' );
	var doseTipEl = root.querySelector( '[data-yp-dose-tip]' );
	var quantityEl = root.querySelector( '[data-yp-quantity]' );
	var variantCardsEl = root.querySelector( '[data-yp-variant-cards]' );
	var addVariantButton = root.querySelector( '[data-yp-add-variant]' );
	var summaryEl = root.querySelector( '[data-yp-summary]' );
	var stickyBar = document.querySelector( '[data-yp-sticky-bar]' );
	var stickyTotalEl = stickyBar ? stickyBar.querySelector( '[data-yp-sticky-total]' ) : null;
	var addToCartButtons = document.querySelectorAll( '[data-yp-add-to-cart]' );
	// Includes the sticky bar's Save button (outside root) as well as the
	// desktop CTA pair inside the configurator.
	var saveDesignButtons = document.querySelectorAll( '[data-yp-save-design]' );

	var schema = null;
	var state = {
		view: 'label',
		sizeId: null,
		materialId: null,
		activeVariantIndex: 0,
		variants: [],
		editKey: null,
		// Only used on a Size with no print dimensions ("Custom"): the
		// label size the customer types in, in inches.
		customWidthIn: '',
		customHeightIn: ''
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
		if ( skeletonEl ) {
			skeletonEl.hidden = true;
		}
		root.removeAttribute( 'data-loading' );
	}

	function onSchemaLoaded( data ) {
		schema = data;

		if ( ! schema.field_schema ) {
			schema.field_schema = [];
		}

		statusEl.hidden = true;
		if ( skeletonEl ) {
			skeletonEl.hidden = true;
		}
		layoutEl.hidden = false;
		root.removeAttribute( 'data-loading' );

		titleEl.textContent = schema.title || '';
		document.title = schema.title ? schema.title + ' — YeffoDesign' : document.title;

		if ( descriptionEl ) {
			descriptionEl.textContent = schema.description || '';
			descriptionEl.hidden = ! schema.description;
		}

		var params = new URLSearchParams( window.location.search );
		var editKey = params.get( 'edit' );
		var reorderRef = params.get( 'reorder' ); // "<order_id>:<item_id>"
		var savedId = params.get( 'saved' );
		var pendingToken = params.get( 'pending' );

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
		} else if ( pendingToken ) {
			// Guest email-resume link (?pending={token}): durable transient
			// from class-guest-saved-design.php — works after the session
			// cookie expires.
			loadExternalBatch(
				yeffoprintConfigurator.restUrl + 'saved-designs/pending/' + encodeURIComponent( pendingToken ),
				{},
				function ( item ) {
					hydrateFromBatch( item );
					showCartStatus( 'Draft restored — keep editing, then add to cart or log in to save it under Saved Designs.', false );
				}
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
		state.customWidthIn = item.custom_width_in ? String( item.custom_width_in ) : '';
		state.customHeightIn = item.custom_height_in ? String( item.custom_height_in ) : '';
		state.materialId = item.material_id ? parseInt( item.material_id, 10 ) : null;
		state.variants = item.variants.map( function ( variant ) {
			var values = variant.values || {};
			// A design saved before this template had color choices (or
			// before one was added) starts on each choice's own color.
			colorChoiceFields().forEach( function ( field ) {
				if ( ! values[ field.id ] ) {
					values[ field.id ] = field.default;
				}
			} );
			return {
				id: nextVariantId++,
				quantity: variant.quantity || 1,
				values: values
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
		renderDoseTip();
		renderFieldInputStructure();
		renderColorChoices();
		renderQuantityControl();
		renderVariantCards();
		renderStage();
		renderSummary();

		setupStickyBar();

		if ( state.editKey ) {
			addToCartButtons.forEach( function ( button ) {
				button.textContent = 'Update Cart';
			} );
		}
	}

	/**
	 * Mobile only: pin the sticky Save/Add to Cart bar once the
	 * configurator is ready, since the in-panel buttons are hidden there
	 * (configurator.css). Desktop never shows it — direct report: it
	 * showed up as a second Save/Add to Cart row at the bottom of the
	 * page, duplicating the buttons already in the form.
	 */
	function setupStickyBar() {
		if ( ! stickyBar ) {
			return;
		}

		var desktopMq = window.matchMedia( '(min-width: 961px)' );

		function sync() {
			stickyBar.hidden = desktopMq.matches;
			document.body.classList.toggle( 'yp-has-sticky-bar', ! stickyBar.hidden );
		}

		sync();

		if ( typeof desktopMq.addEventListener === 'function' ) {
			desktopMq.addEventListener( 'change', sync );
		} else if ( typeof desktopMq.addListener === 'function' ) {
			desktopMq.addListener( sync );
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

	/* ---------- Reconstitution / dose tip ---------- */

	/*
	 * Direct request: recommend customers print their reconstitution
	 * volume and dose on the label, "especially on the pen labels". Shown
	 * on peptide templates (Product Type "Peptide & Vial Labels" or "Pen
	 * Labels", or any template offering the Pen size); the Pen size itself gets a louder
	 * version, since a pen is dialed by units every time it's used.
	 * Points at the template's own notes-style field when it has one —
	 * that's the free-text field that prints on the label.
	 */
	var DOSE_TIP_EXAMPLE = '2 mL BAC \u00b7 250 mcg = 10 units';

	function isPenSize( size ) {
		return !! size && 'pen' === size.slug;
	}

	function showsDoseTip() {
		var types = schema.product_types || [];
		return types.indexOf( 'peptide-vial-labels' ) !== -1
			|| types.indexOf( 'pen-labels' ) !== -1
			|| ( schema.sizes || [] ).some( isPenSize );
	}

	function doseNotesField() {
		return schema.field_schema.filter( function ( field ) {
			return ( 'text' === field.type || 'textarea' === field.type ) && /note/i.test( field.label );
		} )[ 0 ] || null;
	}

	function renderDoseTip() {
		if ( ! doseTipEl ) {
			return;
		}
		if ( ! showsDoseTip() ) {
			doseTipEl.hidden = true;
			return;
		}

		var size = ( schema.sizes || [] ).filter( function ( s ) { return s.id === state.sizeId; } )[ 0 ];
		var isPen = isPenSize( size );
		var notesField = doseNotesField();

		var heading = isPen
			? 'Pen labels: print your mixing and dose info.'
			: 'Tip: add your reconstitution volume and dose.';
		var body = isPen
			? ' A pen gets dialed by units every time, so put how much BAC water went in and what a dose measures right on the label.'
			: ' Printing how the vial was mixed and what a dose measures saves guesswork later.';
		var where = notesField
			? ' Add it in the <strong>' + escapeHtml( notesField.label ) + '</strong> field, like <em>' + escapeHtml( DOSE_TIP_EXAMPLE ) + '</em>.'
			: ' For example: <em>' + escapeHtml( DOSE_TIP_EXAMPLE ) + '</em>.';
		var calculatorLink = yeffoprintConfigurator.calculatorUrl
			? '<a class="yp-form-redirect-notice__cta" href="' + escapeHtml( yeffoprintConfigurator.calculatorUrl ) + '" target="_blank" rel="noopener">Work it out with our Reconstitution Calculator &rarr;</a>'
			: '';

		doseTipEl.className = 'yp-form-redirect-notice yp-dose-tip' + ( isPen ? ' is-pen' : '' );
		doseTipEl.setAttribute( 'role', 'note' );
		doseTipEl.innerHTML =
			'<span class="yp-form-redirect-notice__icon" aria-hidden="true">' + ( isPen ? '!' : 'i' ) + '</span>' +
			'<div class="yp-form-redirect-notice__body">' +
				'<p><strong>' + heading + '</strong>' + body + where + '</p>' +
				calculatorLink +
			'</div>';
		doseTipEl.hidden = false;
	}

	/* ---------- Size / Material selectors ---------- */

	/**
	 * Direct request: size cards "in an image" — each label drawn to
	 * scale with its dimensions, all on one shared scale so the sizes
	 * compare honestly — and materials shown as the material itself
	 * with a shimmer on the foil finishes. Markup lives in
	 * label-pickers.js, shared with the custom label form.
	 */
	var SIZE_BOX_W = 96;
	var SIZE_BOX_H = 54;
	var sizeCaptionEl = null;

	function cornerIsRounded() {
		var cornerField = schema.field_schema.filter( function ( field ) { return 'corner_style' === field.type; } )[ 0 ];
		return !! cornerField && state.variants.length > 0 && 'rounded' === activeVariant().values[ cornerField.id ];
	}

	function renderSizeOptions() {
		var pickers = window.YPLabelPickers;
		var scale = pickers.groupScale( schema.sizes, SIZE_BOX_W, SIZE_BOX_H );
		var rounded = cornerIsRounded();

		sizeOptionsEl.classList.remove( 'yp-option-group' );
		sizeOptionsEl.classList.add( 'yp-size-cards' );
		sizeOptionsEl.innerHTML = schema.sizes.map( function ( size ) {
			return pickers.sizeCardHtml( size, { scale: scale, boxW: SIZE_BOX_W, boxH: SIZE_BOX_H, selected: size.id === state.sizeId, rounded: rounded } );
		} ).join( '' ) || '<p class="description">No compatible sizes configured yet.</p>';

		sizeOptionsEl.querySelectorAll( '[data-option-id]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				state.sizeId = parseInt( button.getAttribute( 'data-option-id' ), 10 );
				updateSelectedPill( sizeOptionsEl, state.sizeId );
				renderSizeCaption();
				renderDoseTip();
				renderSummary();
			} );
		} );

		renderSizeCaption();
	}

	function selectedSize() {
		return ( schema.sizes || [] ).filter( function ( s ) { return s.id === state.sizeId; } )[ 0 ];
	}

	/** True when the picked Size has no print dimensions ("Custom"), so the customer types their own. */
	function needsCustomSize() {
		var size = selectedSize();
		return !! size && ! window.YPLabelPickers.hasDimensions( size );
	}

	function customSizeValid() {
		var w = parseFloat( state.customWidthIn );
		var h = parseFloat( state.customHeightIn );
		return w >= 0.25 && w <= 24 && h >= 0.25 && h <= 24;
	}

	var customSizeEl = null;

	/**
	 * Width/height boxes under the size cards when a no-dimensions Size
	 * ("Custom") is picked. Direct report: "there's a custom size option
	 * for the templates, but nowhere for them to enter the size they
	 * need." Sent with Add to Cart (cart/add validates and stores it).
	 */
	function renderCustomSize() {
		if ( ! customSizeEl ) {
			customSizeEl = document.createElement( 'div' );
			customSizeEl.className = 'yp-custom-size';
			customSizeEl.innerHTML =
				'<p class="yp-custom-size__title">Enter your label size</p>' +
				'<div class="yp-custom-size__row">' +
					'<label class="yp-custom-size__field"><span>Width</span><span class="yp-custom-size__input"><input type="number" inputmode="decimal" min="0.25" max="24" step="0.05" placeholder="2.00" data-yp-custom-width /><em>in</em></span></label>' +
					'<span class="yp-custom-size__x" aria-hidden="true">×</span>' +
					'<label class="yp-custom-size__field"><span>Height</span><span class="yp-custom-size__input"><input type="number" inputmode="decimal" min="0.25" max="24" step="0.05" placeholder="1.00" data-yp-custom-height /><em>in</em></span></label>' +
				'</div>' +
				'<p class="yp-custom-size__hint">Measure the area you want the label to cover. We’ll confirm the fit before printing.</p>';
			( sizeCaptionEl || sizeOptionsEl ).insertAdjacentElement( 'afterend', customSizeEl );

			var widthInput = customSizeEl.querySelector( '[data-yp-custom-width]' );
			var heightInput = customSizeEl.querySelector( '[data-yp-custom-height]' );
			widthInput.addEventListener( 'input', function () {
				state.customWidthIn = widthInput.value;
				customSizeEl.classList.remove( 'is-invalid' );
			} );
			heightInput.addEventListener( 'input', function () {
				state.customHeightIn = heightInput.value;
				customSizeEl.classList.remove( 'is-invalid' );
			} );
		}

		customSizeEl.hidden = ! needsCustomSize();
		customSizeEl.querySelector( '[data-yp-custom-width]' ).value = state.customWidthIn;
		customSizeEl.querySelector( '[data-yp-custom-height]' ).value = state.customHeightIn;
	}

	/** "Label size: 1.77″ × 0.83″ · 45 × 21 mm · rounded corners" under the cards. */
	function renderSizeCaption() {
		if ( ! sizeCaptionEl ) {
			sizeCaptionEl = document.createElement( 'p' );
			sizeCaptionEl.className = 'yp-size-caption';
			sizeOptionsEl.parentNode.insertBefore( sizeCaptionEl, sizeOptionsEl.nextSibling );
		}

		renderCustomSize();

		var pickers = window.YPLabelPickers;
		var size = selectedSize();
		if ( ! size || ! pickers.hasDimensions( size ) ) {
			sizeCaptionEl.hidden = true;
			return;
		}

		var hasCornerField = schema.field_schema.some( function ( field ) { return 'corner_style' === field.type; } );
		sizeCaptionEl.hidden = false;
		sizeCaptionEl.innerHTML =
			'Label size: <strong>' + pickers.inches( size.print_width_mm ) + ' × ' + pickers.inches( size.print_height_mm ) + '</strong>' +
			' · ' + Math.round( size.print_width_mm * 10 ) / 10 + ' × ' + Math.round( size.print_height_mm * 10 ) / 10 + ' mm' +
			( hasCornerField ? ' · ' + ( cornerIsRounded() ? 'rounded' : 'squared' ) + ' corners' : '' ) +
			' · shown to scale';
	}

	function renderMaterialOptions() {
		var pickers = window.YPLabelPickers;

		materialOptionsEl.classList.remove( 'yp-option-group' );
		materialOptionsEl.classList.add( 'yp-material-cards' );
		materialOptionsEl.innerHTML = schema.materials.map( function ( material ) {
			return pickers.materialCardHtml( material, { selected: material.id === state.materialId } );
		} ).join( '' ) || '<p class="description">No compatible materials configured yet.</p>';

		materialOptionsEl.querySelectorAll( '[data-option-id]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				state.materialId = parseInt( button.getAttribute( 'data-option-id' ), 10 );
				updateSelectedPill( materialOptionsEl, state.materialId );
				renderSummary();
			} );
		} );
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
	 * Corner Finish as two picture cards (squared / rounded outline)
	 * instead of text pills plus a "?" diagram — the drawing *is* the
	 * explanation, so no tooltip is needed for this type any more.
	 */
	var CORNER_STYLE_HINTS = { squared: 'Sharp 90° corners', rounded: 'Soft, peel-friendly' };

	function cornerStyleOptionsHtml( field, value ) {
		return (
			'<div class="yp-corner-cards yp-corner-style-options" role="radiogroup" aria-labelledby="yp-field-label-' + field.id + '" data-field-id="' + field.id + '">' +
				Object.keys( CORNER_STYLE_OPTIONS ).map( function ( key ) {
					var isSelected = key === value;
					return (
						'<button type="button" role="radio" aria-checked="' + ( isSelected ? 'true' : 'false' ) + '" class="yp-corner-card' + ( isSelected ? ' is-selected' : '' ) + '" data-corner-value="' + key + '">' +
							'<svg width="40" height="28" viewBox="0 0 40 28" aria-hidden="true" focusable="false"><rect x="1" y="1" width="38" height="26" rx="' + ( 'rounded' === key ? 6 : 0 ) + '"/></svg>' +
							'<span><strong>' + CORNER_STYLE_OPTIONS[ key ] + '</strong><small>' + ( CORNER_STYLE_HINTS[ key ] || '' ) + '</small></span>' +
						'</button>'
					);
				} ).join( '' ) +
			'</div>'
		);
	}

	/**
	 * Color as a row of preset dots plus a "pick any color" wheel. The
	 * real value still lives on the native color input (inside the
	 * wheel), so every existing read/sync path is unchanged — a dot just
	 * sets that input and fires its `input` event.
	 */
	var COLOR_PRESETS = [ '#141414', '#FFFFFF', '#0D1B4C', '#00AEEF', '#EC008C', '#1F7A4D', '#B8862B', '#7A1F2B' ];

	function colorOptionsHtml( field ) {
		return (
			'<div class="yp-color-options" data-color-group="' + field.id + '">' +
				COLOR_PRESETS.map( function ( hex ) {
					return '<button type="button" class="yp-color-dot" style="background:' + hex + '" data-color-value="' + hex + '" aria-label="' + hex + '" aria-pressed="false"></button>';
				} ).join( '' ) +
				'<label class="yp-color-any" title="Pick any color"><span class="screen-reader-text">Pick any color</span>' +
					'<input type="color" data-field-id="' + field.id + '" class="yp-field__color-input" />' +
				'</label>' +
				'<span class="yp-color-hex" data-color-hex="' + field.id + '"></span>' +
			'</div>'
		);
	}

	function syncColorGroup( fieldId ) {
		var group = fieldInputsEl.querySelector( '[data-color-group="' + fieldId + '"]' );
		if ( ! group ) {
			return;
		}
		var value = ( activeVariant().values[ fieldId ] || '' ).toUpperCase();
		var matchedPreset = false;
		group.querySelectorAll( '[data-color-value]' ).forEach( function ( dot ) {
			var on = dot.getAttribute( 'data-color-value' ) === value;
			matchedPreset = matchedPreset || on;
			dot.classList.toggle( 'is-selected', on );
			dot.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
		group.querySelector( '.yp-color-any' ).classList.toggle( 'is-selected', !! value && ! matchedPreset );
		group.querySelector( '[data-color-hex]' ).textContent = value;
	}

	/** One field's markup (label row, optional tooltip, control). */
	function fieldHtml( field ) {
		var control;
		var value = ( activeVariant().values[ field.id ] ) || '';
		var isWide = true;
		if ( 'color' === field.type ) {
			control = colorOptionsHtml( field );
		} else if ( 'qr_code' === field.type ) {
			control = '<input type="url" placeholder="https://" data-field-id="' + field.id + '" maxlength="' + field.max_chars + '" class="widefat" />';
		} else if ( 'textarea' === field.type ) {
			control = '<textarea data-field-id="' + field.id + '" maxlength="' + field.max_chars + '" rows="2" class="widefat"></textarea>';
		} else if ( 'corner_style' === field.type ) {
			control = cornerStyleOptionsHtml( field, value );
		} else {
			var placeholder = showsDoseTip() && field === doseNotesField()
				? ' placeholder="e.g. ' + escapeHtml( DOSE_TIP_EXAMPLE ) + '"'
				: '';
			control = '<input type="text" data-field-id="' + field.id + '" maxlength="' + field.max_chars + '" class="widefat"' + placeholder + ' />';
			// Short single-line text sits two to a row; a long one (a
			// notes-style field) keeps the full width.
			isWide = field.max_chars > 60;
		}

		var hasTooltip = !! field.admin_description;
		var tooltip = hasTooltip
			? ' <button type="button" class="yp-field__tooltip-trigger" data-tooltip-trigger="' + field.id + '" aria-expanded="false" aria-controls="yp-field-tooltip-' + field.id + '" aria-label="More info about ' + escapeHtml( field.label ) + '">?</button>'
			: '';
		// Groups of buttons aren't labelable by <label for>, so they get a
		// plain labelled heading (radiogroup aria-labelledby) instead.
		var isGroup = 'color' === field.type || 'corner_style' === field.type;
		var labelText = escapeHtml( field.label ) + ( field.required ? ' *' : '' );

		return (
			'<div class="yp-field' + ( isWide ? ' yp-field--wide' : '' ) + '">' +
				'<div class="yp-field__label-row">' +
					( isGroup
						? '<span class="yp-field__label" id="yp-field-label-' + field.id + '">' + labelText + tooltip + '</span>'
						: '<label for="yp-field-' + field.id + '">' + labelText + tooltip + '</label>' ) +
					( isGroup ? '' : '<span class="yp-field__counter" data-counter-for="' + field.id + '"></span>' ) +
				'</div>' +
				( hasTooltip
					? '<div class="yp-field__tooltip" id="yp-field-tooltip-' + field.id + '" hidden><p>' + escapeHtml( field.admin_description ) + '</p></div>'
					: '' ) +
				control.replace( '<textarea', '<textarea id="yp-field-' + field.id + '"' ).replace( '<input', '<input id="yp-field-' + field.id + '"' ) +
			'</div>'
		);
	}

	/**
	 * Every Template shares one global field set now (admin → Label
	 * Fields), so the section is laid out once for all of them:
	 * required fields first under "On the label", the rest under
	 * "Optional details", each keeping the admin's own order.
	 */
	function renderFieldInputStructure() {
		var labelFields = schema.field_schema.filter( function ( field ) { return 'color_choice' !== field.type; } );
		var required = labelFields.filter( function ( field ) { return field.required; } );
		var optional = labelFields.filter( function ( field ) { return ! field.required; } );
		var showTitles = required.length > 0 && optional.length > 0;

		function groupHtml( title, fields ) {
			if ( ! fields.length ) {
				return '';
			}
			return ( showTitles ? '<p class="yp-field-group__title">' + title + '</p>' : '' ) +
				'<div class="yp-field-grid">' + fields.map( fieldHtml ).join( '' ) + '</div>';
		}

		fieldInputsEl.innerHTML = ( groupHtml( 'On the label', required ) + groupHtml( 'Optional details', optional ) ) ||
			'<p class="description">This design has no customization fields.</p>';

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
				syncColorGroup( fieldId );
				updateStageField( fieldId );
				updateActiveVariantCardSummary();
			} );
		} );

		fieldInputsEl.querySelectorAll( '[data-color-value]' ).forEach( function ( dot ) {
			dot.addEventListener( 'click', function () {
				var input = dot.closest( '[data-color-group]' ).querySelector( 'input[type="color"]' );
				input.value = dot.getAttribute( 'data-color-value' ).toLowerCase();
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			} );
		} );

		fieldInputsEl.querySelectorAll( '.yp-corner-style-options' ).forEach( function ( group ) {
			var fieldId = group.getAttribute( 'data-field-id' );
			group.querySelectorAll( '[data-corner-value]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					activeVariant().values[ fieldId ] = button.getAttribute( 'data-corner-value' );
					syncCornerStyleGroup( group, fieldId );
					renderSizeOptions();
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
		syncColorChoices();

		schema.field_schema.forEach( function ( field ) {
			if ( 'color_choice' === field.type ) {
				return;
			}

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
			if ( 'color' === field.type ) {
				syncColorGroup( field.id );
			}
		} );

		// Switching to a batch label with a different Corner Finish
		// redraws the size cards' corners to match.
		if ( sizeOptionsEl.childElementCount ) {
			renderSizeOptions();
		}
	}


	/* ---------- Color choices ---------- */

	/*
	 * Direct request: the 3D prints' numbered color dots on label
	 * templates, so customers can pick e.g. a background and a text
	 * color. Each choice arrives as a `color_choice` field
	 * (YeffoPrint_Label_Color_Meta::virtual_fields()): its pick is one
	 * more value on the batch label, and the stage repaints to match —
	 * a fill behind the artwork (background), every text field (text),
	 * or a tinted shape layer over the artwork (layer).
	 */
	function colorChoiceFields() {
		return ( schema && schema.field_schema ? schema.field_schema : [] ).filter( function ( field ) {
			return 'color_choice' === field.type;
		} );
	}

	function colorChoiceValue( field ) {
		return ( activeVariant() && activeVariant().values[ field.id ] ) || field.default || '';
	}

	function colorOptionName( field, hex ) {
		var match = ( field.options || [] ).filter( function ( option ) {
			return option.hex.toUpperCase() === ( hex || '' ).toUpperCase();
		} )[ 0 ];
		return match ? match.name : ( hex ? 'Custom ' + hex.toUpperCase() : '' );
	}

	function hasColorDot( field ) {
		return field.position && null !== field.position.x && undefined !== field.position.x && null !== field.position.y && undefined !== field.position.y;
	}

	function relativeLuminance( hex ) {
		var n = parseInt( ( hex || '#000000' ).replace( '#', '' ), 16 );
		return [ n >> 16, ( n >> 8 ) & 255, n & 255 ].map( function ( v ) {
			v /= 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} ).reduce( function ( sum, v, i ) {
			return sum + v * [ 0.2126, 0.7152, 0.0722 ][ i ];
		}, 0 );
	}

	/** Background vs text readability, only when the template offers both. */
	function colorContrastOk() {
		var fields = colorChoiceFields();
		var bg = fields.filter( function ( f ) { return 'background' === f.target; } )[ 0 ];
		var tx = fields.filter( function ( f ) { return 'text' === f.target; } )[ 0 ];
		if ( ! bg || ! tx ) {
			return null;
		}
		var a = relativeLuminance( colorChoiceValue( bg ) );
		var b = relativeLuminance( colorChoiceValue( tx ) );
		return ( Math.max( a, b ) + 0.05 ) / ( Math.min( a, b ) + 0.05 ) >= 3;
	}

	function renderColorChoices() {
		if ( ! colorSectionEl || ! colorChoicesEl ) {
			return;
		}

		var fields = colorChoiceFields();
		colorSectionEl.hidden = ! fields.length;
		if ( ! fields.length ) {
			colorChoicesEl.innerHTML = '';
			return;
		}

		colorChoicesEl.innerHTML = fields.map( function ( field, index ) {
			return (
				'<div class="yp-color-choice" data-color-choice="' + escapeHtml( field.id ) + '" data-choice-index="' + index + '">' +
					'<div class="yp-color-choice__head">' +
						'<span class="yp-color-choice__num" aria-hidden="true">' + ( index + 1 ) + '</span>' +
						'<div class="yp-color-choice__text">' +
							'<span class="yp-color-choice__name" id="yp-color-choice-' + escapeHtml( field.id ) + '">' + escapeHtml( field.label ) + '</span>' +
							( field.hint ? '<span class="yp-color-choice__hint">' + escapeHtml( field.hint ) + '</span>' : '' ) +
						'</div>' +
						'<span class="yp-color-choice__picked" data-color-picked><i></i><span></span></span>' +
					'</div>' +
					'<div class="yp-color-choice__swatches" role="radiogroup" aria-labelledby="yp-color-choice-' + escapeHtml( field.id ) + '">' +
						( field.options || [] ).map( function ( option ) {
							return '<button type="button" role="radio" aria-checked="false" class="yp-color-swatch" style="background:' + escapeHtml( option.hex ) + '" data-color-hex="' + escapeHtml( option.hex ) + '" title="' + escapeHtml( option.name ) + '" aria-label="' + escapeHtml( option.name ) + '"></button>';
						} ).join( '' ) +
						( field.any_color
							? '<label class="yp-color-swatch yp-color-swatch--any" title="Any color"><span class="screen-reader-text">Any color</span><input type="color" data-color-any /></label><span class="yp-color-choice__any-label">Any color</span>'
							: '' ) +
					'</div>' +
					( 'text' === field.target ? '<p class="yp-color-choice__contrast" data-color-contrast hidden></p>' : '' ) +
				'</div>'
			);
		} ).join( '' ) +
		'<p class="yp-color-choices__note">We send a proof before anything prints, so colors can still be tweaked after you see it.</p>';

		colorChoicesEl.querySelectorAll( '[data-color-choice]' ).forEach( function ( card ) {
			var field = fields[ parseInt( card.getAttribute( 'data-choice-index' ), 10 ) ];

			function pick( hex ) {
				activeVariant().values[ field.id ] = hex;
				syncColorChoices();
				applyStageColors();
				updateActiveVariantCardSummary();
			}

			card.querySelectorAll( '[data-color-hex]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					pick( button.getAttribute( 'data-color-hex' ) );
				} );
			} );

			var anyInput = card.querySelector( '[data-color-any]' );
			if ( anyInput ) {
				anyInput.addEventListener( 'input', function () {
					pick( anyInput.value.toUpperCase() );
				} );
			}

			// Hovering or focusing a step lights up its dot on the preview.
			[ 'mouseenter', 'focusin' ].forEach( function ( type ) {
				card.addEventListener( type, function () {
					setActiveColorDot( field.id );
				} );
			} );
		} );

		syncColorChoices();
	}

	function syncColorChoices() {
		if ( ! colorChoicesEl ) {
			return;
		}

		colorChoiceFields().forEach( function ( field ) {
			var card = colorChoicesEl.querySelector( '[data-color-choice="' + field.id + '"]' );
			if ( ! card ) {
				return;
			}
			var value = colorChoiceValue( field ).toUpperCase();
			var matched = false;

			card.querySelectorAll( '[data-color-hex]' ).forEach( function ( button ) {
				var on = button.getAttribute( 'data-color-hex' ).toUpperCase() === value;
				matched = matched || on;
				button.classList.toggle( 'is-selected', on );
				button.setAttribute( 'aria-checked', on ? 'true' : 'false' );
			} );

			var any = card.querySelector( '.yp-color-swatch--any' );
			if ( any ) {
				any.classList.toggle( 'is-selected', ! matched && !! value );
				any.style.setProperty( '--yp-any-color', ! matched && value ? value : 'transparent' );
				if ( /^#[0-9A-F]{6}$/.test( value ) ) {
					any.querySelector( 'input' ).value = value.toLowerCase();
				}
			}

			var picked = card.querySelector( '[data-color-picked]' );
			picked.querySelector( 'i' ).style.background = value;
			picked.querySelector( 'span' ).textContent = colorOptionName( field, value );

			var contrastEl = card.querySelector( '[data-color-contrast]' );
			if ( contrastEl ) {
				var ok = colorContrastOk();
				contrastEl.hidden = null === ok;
				contrastEl.classList.toggle( 'is-low', false === ok );
				contrastEl.textContent = false === ok ? 'These two colors are hard to read together.' : '✓ Easy to read';
			}
		} );
	}

	function setActiveColorDot( fieldId ) {
		stageEl.querySelectorAll( '[data-color-dot]' ).forEach( function ( dot ) {
			dot.classList.toggle( 'is-active', dot.getAttribute( 'data-color-dot' ) === fieldId );
		} );
		if ( colorChoicesEl ) {
			colorChoicesEl.querySelectorAll( '[data-color-choice]' ).forEach( function ( card ) {
				card.classList.toggle( 'is-active', card.getAttribute( 'data-color-choice' ) === fieldId );
			} );
		}
	}

	/**
	 * Repaints the stage for the active label's picks. Label View only —
	 * Vial View is a reference photo. Safe to call repeatedly: it clears
	 * its own elements first.
	 */
	function applyStageColors() {
		stageEl.querySelectorAll( '[data-color-el]' ).forEach( function ( el ) {
			el.parentNode.removeChild( el );
		} );

		var fields = colorChoiceFields();
		var isLabel = 'label' === state.view;
		var textColor = null;
		var backgroundImg = stageEl.querySelector( '.yp-stage__background' );

		fields.forEach( function ( field, index ) {
			var value = colorChoiceValue( field );

			if ( 'text' === field.target ) {
				textColor = value;
			}

			if ( ! isLabel ) {
				return;
			}

			if ( 'background' === field.target ) {
				var fill = document.createElement( 'div' );
				fill.className = 'yp-stage__color-fill';
				fill.setAttribute( 'data-color-el', '' );
				fill.style.background = value;
				stageEl.insertBefore( fill, stageEl.firstChild );
			} else if ( 'layer' === field.target && field.layer_url ) {
				var layer = document.createElement( 'div' );
				var mask = 'url("' + field.layer_url.replace( /"/g, '%22' ) + '")';
				layer.className = 'yp-stage__color-layer';
				layer.setAttribute( 'data-color-el', '' );
				layer.style.background = value;
				layer.style.webkitMaskImage = mask;
				layer.style.maskImage = mask;
				if ( backgroundImg && backgroundImg.nextSibling ) {
					stageEl.insertBefore( layer, backgroundImg.nextSibling );
				} else {
					stageEl.appendChild( layer );
				}
			}

			if ( hasColorDot( field ) ) {
				var dot = document.createElement( 'span' );
				dot.className = 'yp-stage__color-dot';
				dot.setAttribute( 'data-color-el', '' );
				dot.setAttribute( 'data-color-dot', field.id );
				dot.setAttribute( 'aria-hidden', 'true' );
				dot.style.left = field.position.x + '%';
				dot.style.top = field.position.y + '%';
				dot.innerHTML = ( index + 1 ) + '<i style="background:' + escapeHtml( value ) + '"></i>';
				stageEl.appendChild( dot );
			}
		} );

		// Text color wins over each field's own admin text color.
		schema.field_schema.forEach( function ( field ) {
			if ( 'text' !== field.type && 'textarea' !== field.type ) {
				return;
			}
			var el = stageEl.querySelector( '.yp-stage__field[data-field-id="' + field.id + '"]' );
			if ( el ) {
				el.style.color = textColor || field.text_color || '#000000';
			}
		} );
	}

	/* ---------- Quantity ---------- */

	/**
	 * Presets plus an "Other" option that reveals a number box (direct
	 * request: "there needs to be an 'other' option where users can
	 * type in a quantity"). Markup/wiring in label-pickers.js.
	 */
	function renderQuantityControl() {
		var presets = schema.quantity_presets || [];
		quantityEl.innerHTML = window.YPLabelPickers.quantityHtml( presets, activeVariant().quantity, 'yp-quantity-input' );
		window.YPLabelPickers.bindQuantity( quantityEl, presets, setActiveVariantQuantity );
	}

	function setActiveVariantQuantity( quantity ) {
		activeVariant().quantity = quantity;
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
		var firstField = schema.field_schema.filter( function ( field ) { return 'color_choice' !== field.type; } )[ 0 ];
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
			applyStageColors();
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

		applyStageColors();
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
		var el = ensureCartStatusEl();
		el.textContent = message;
		el.classList.toggle( 'is-error', !! isError );
	}

	function clearCartStatus() {
		if ( cartStatusEl ) {
			cartStatusEl.remove();
			cartStatusEl = null;
		}
	}

	function ensureCartStatusEl() {
		if ( ! cartStatusEl ) {
			cartStatusEl = document.createElement( 'div' );
			cartStatusEl.className = 'yp-configurator__cart-status';
			summaryEl.insertAdjacentElement( 'afterend', cartStatusEl );
		}
		return cartStatusEl;
	}

	// Both this endpoint's own explicit nonce check (class-rest-
	// security.php) and WordPress core's own cookie/nonce check (which
	// runs even earlier, before any endpoint code) reject the same
	// underlying problem — a stale nonce that no longer matches the
	// visitor's actual session — under two different error codes.
	var NONCE_ERROR_CODES = [ 'rest_cookie_invalid_nonce', 'yeffoprint_invalid_nonce' ];

	function submitAddToCart( isRetry ) {
		clearCartStatus();

		if ( needsCustomSize() && ! customSizeValid() ) {
			showCartStatus( 'Enter your label’s width and height (0.25 to 24 inches) under Size.', true );
			if ( customSizeEl ) {
				customSizeEl.classList.add( 'is-invalid' );
				customSizeEl.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				customSizeEl.querySelector( parseFloat( state.customWidthIn ) >= 0.25 ? '[data-yp-custom-height]' : '[data-yp-custom-width]' ).focus( { preventScroll: true } );
			}
			return;
		}

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

		if ( needsCustomSize() ) {
			payload.custom_width_in = parseFloat( state.customWidthIn );
			payload.custom_height_in = parseFloat( state.customHeightIn );
		}

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
	// Logged-in customers POST /saved-designs immediately. Guests POST
	// /saved-designs/pending (class-guest-saved-design.php), which stashes
	// the batch in the WooCommerce session + a durable token. Guests can
	// email themselves a resume link (?pending=) or log in so the batch
	// is claimed into a real yp_saved_design. Buttons stay visible either
	// way (templates/*.html can't conditionally omit them).

	var pendingSaveToken = null;

	function showGuestSavePanel( resultData ) {
		pendingSaveToken = resultData.token || null;
		var el = ensureCartStatusEl();
		el.classList.remove( 'is-error' );
		el.innerHTML =
			'<p class="yp-configurator__save-msg">' + escapeHtml( resultData.message || 'Design saved for this browser.' ) + '</p>' +
			'<form class="yp-configurator__email-save" data-yp-email-save>' +
				'<label class="screen-reader-text" for="yp-pending-email">Email</label>' +
				'<input id="yp-pending-email" type="email" name="email" required placeholder="Email yourself a link" autocomplete="email" />' +
				'<button type="submit" class="wp-block-button__link is-style-outline">Email link</button>' +
			'</form>' +
			( resultData.login_url
				? '<p class="yp-configurator__save-login"><a href="' + escapeHtml( resultData.login_url ) + '">Log in to keep it under Saved Designs</a></p>'
				: '' );

		var form = el.querySelector( '[data-yp-email-save]' );
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				submitPendingEmail( form );
			} );
		}
	}

	function submitPendingEmail( form ) {
		var emailInput = form.querySelector( 'input[type="email"]' );
		var email = emailInput ? emailInput.value.trim() : '';
		var submitBtn = form.querySelector( 'button[type="submit"]' );

		if ( ! email || ! pendingSaveToken ) {
			showCartStatus( 'Enter an email address to get your resume link.', true );
			return;
		}

		if ( submitBtn ) {
			submitBtn.disabled = true;
		}

		fetch( yeffoprintConfigurator.restUrl + 'saved-designs/pending/email', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': yeffoprintConfigurator.nonce },
			body: JSON.stringify( { token: pendingSaveToken, email: email } )
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( submitBtn ) {
					submitBtn.disabled = false;
				}
				if ( ! result.ok ) {
					showCartStatus( ( result.data && result.data.message ) || "Couldn't send that email.", true );
					return;
				}
				showCartStatus( result.data.message || 'Check your email for a link to finish this design anytime.', false );
			} )
			.catch( function () {
				if ( submitBtn ) {
					submitBtn.disabled = false;
				}
				showCartStatus( "Couldn't reach the server — please try again.", true );
			} );
	}

	if ( saveDesignButtons.length ) {
		if ( ! yeffoprintConfigurator.isLoggedIn ) {
			saveDesignButtons.forEach( function ( button ) {
				button.textContent = button.closest( '[data-yp-sticky-bar]' ) ? 'Save' : 'Save this design';
			} );
		}

		saveDesignButtons.forEach( function ( saveDesignButton ) {
			saveDesignButton.addEventListener( 'click', function () {
				clearCartStatus();
				saveDesignButtons.forEach( function ( button ) {
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

				var endpoint = yeffoprintConfigurator.isLoggedIn
					? 'saved-designs'
					: 'saved-designs/pending';

				fetch( yeffoprintConfigurator.restUrl + endpoint, {
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
						saveDesignButtons.forEach( function ( button ) {
							button.disabled = false;
						} );

						if ( ! result.ok ) {
							showCartStatus( ( result.data && result.data.message ) || "Couldn't save this design.", true );
							return;
						}

						if ( result.data && result.data.pending ) {
							if ( result.data.emailed ) {
								showCartStatus( result.data.message || 'Check your email for a link to finish this design anytime.', false );
								return;
							}
							showGuestSavePanel( result.data );
							return;
						}

						showCartStatus( 'Design saved — find it under Saved Designs in My Account.', false );
					} )
					.catch( function () {
						saveDesignButtons.forEach( function ( button ) {
							button.disabled = false;
						} );
						showCartStatus( "Couldn't reach the server — please try again.", true );
					} );
			} );
		} );
	}

	init();
} )();
