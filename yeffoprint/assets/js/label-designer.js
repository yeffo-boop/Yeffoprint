/**
 * Label Designer — a freeform canvas the customer designs their own
 * label on (direct request: "a full live product label customizer...
 * input a size, add text, easy graphics, shapes/lines, change the font,
 * colors, etc and have it show a live preview"). A different shape of
 * flow than the Template configurator: that one only lets a customer
 * fill in values into fields an admin already positioned (configurator.js's
 * fields are 0-100% coordinates set once in the admin editor, never
 * moved by a customer); here the customer adds/moves/resizes/styles
 * their own elements freely, backed by Fabric.js (assets/vendor/
 * fabric.min.js, vendored locally — this theme's one exception to
 * "everything local except Google Fonts," since a production feature
 * shouldn't depend on a third-party CDN's uptime).
 *
 * Submits through the *existing* Custom Design new_design mode
 * (class-custom-order-controller.php) — same $25 design fee as any
 * other new-design request. Direct clarification: the exported canvas
 * PNG is a template staff still build the real print file from, not a
 * print-ready file itself, so it does *not* qualify for own_design's
 * fee-free path (that's reserved for a customer's own already-finished,
 * print-ready file). It goes through the same proof/status pipeline as
 * any other Custom Design order either way. Pricing is
 * the one place this flow diverges from every other one on the site:
 * width_mm/height_mm (not a preset Size) drive a dynamic per-label
 * price server-side (YeffoPrint_Pricing_Rule::dynamic_base_price()) —
 * this file only ever shows an *estimate* of that; the authoritative
 * number always comes from a pricing-preview round trip, same
 * "instant estimate, server has final say" pattern configurator.js
 * already uses for Template pricing.
 *
 * Fidelity/experience round (direct follow-up: "what other features can
 * we add... to get the final product as close to it as possible" ->
 * "Let's do them all!"): a safe-zone guide (a DOM overlay, not a Fabric
 * object — see updateSafeZoneGuide()'s own comment for why), alignment/
 * snap guides while dragging, zoom, a layers panel with per-object lock,
 * starter layouts for a fresh empty canvas, and a flat "Preview on
 * Product" overlay. See each section below for the relevant comments.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintLabelDesigner === 'undefined' || typeof fabric === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'yp-label-designer' );
	if ( ! root ) {
		return;
	}

	var MM_PER_INCH = 25.4;
	var MAX_CANVAS_PX_WIDTH  = 640;
	var MAX_CANVAS_PX_HEIGHT = 480;
	var MAX_PX_PER_INCH      = 220;
	var EXPORT_DPI           = 300;
	var DRAFT_STORAGE_KEY    = 'yeffoprintLabelDesignerDraft';
	var SAFE_ZONE_MARGIN_MM  = 2;
	var SNAP_THRESHOLD_PX    = 6;
	var ZOOM_MIN  = 0.5;
	var ZOOM_MAX  = 2;
	var ZOOM_STEP = 0.25;
	// Extra per-object properties Fabric's default toJSON()/toObject()
	// wouldn't otherwise serialize — needed so a locked layer (see the
	// layers-panel section) stays locked across undo/redo and a draft
	// reload, not just for the rest of the current page session.
	var EXTRA_OBJECT_PROPS = [ 'selectable', 'evented', 'hasControls' ];

	var statusEl   = root.querySelector( '.yp-configurator__status' );
	var form       = document.getElementById( 'yp-label-designer-form' );
	var widthInput  = document.getElementById( 'yp-ld-width' );
	var heightInput = document.getElementById( 'yp-ld-height' );
	var sizePresetGroupEl = root.querySelector( '[data-yp-ld-size-presets]' );
	var sizePresetRadios = sizePresetGroupEl ? Array.prototype.slice.call( sizePresetGroupEl.querySelectorAll( 'input[name="ld_size_preset"]' ) ) : [];
	var sizePresetHintEl = root.querySelector( '[data-yp-ld-size-preset-hint]' );
	var materialSelect  = document.getElementById( 'yp-ld-material' );
	var quantityInput   = document.getElementById( 'yp-ld-quantity' );
	var quantityPresetsEl = root.querySelector( '[data-yp-ld-quantity-presets]' );
	var bgColorInput  = document.getElementById( 'yp-ld-bg-color' );
	var brandInput    = document.getElementById( 'yp-ld-brand' );
	var notesInput    = document.getElementById( 'yp-ld-notes' );
	var feeEl         = root.querySelector( '[data-yp-ld-fee]' );
	var unitPriceEl   = root.querySelector( '[data-yp-ld-unit-price]' );
	var totalEl       = root.querySelector( '[data-yp-ld-total]' );
	var submitButton  = document.getElementById( 'yp-ld-submit' ) || root.querySelector( '[data-yp-ld-submit]' );

	var toolbarEl   = root.querySelector( '.yp-ld__toolbar' );
	var undoButton  = root.querySelector( '[data-yp-ld-undo]' );
	var redoButton  = root.querySelector( '[data-yp-ld-redo]' );
	var duplicateButton = root.querySelector( '[data-yp-ld-duplicate]' );
	var deleteButton = root.querySelector( '[data-yp-ld-delete]' );
	var frontButton = root.querySelector( '[data-yp-ld-front]' );
	var backButton  = root.querySelector( '[data-yp-ld-back]' );
	var clearButton = root.querySelector( '[data-yp-ld-clear]' );

	var iconToggle  = root.querySelector( '[data-yp-ld-icon-toggle]' );
	var iconPanel   = root.querySelector( '[data-yp-ld-icon-panel]' );
	var imageToggle = root.querySelector( '[data-yp-ld-image-toggle]' );
	var imageInput  = root.querySelector( '[data-yp-ld-image-input]' );

	var propertiesEl   = root.querySelector( '[data-yp-ld-properties]' );
	var fontFamilySelect = root.querySelector( '[data-yp-ld-font-family]' );
	var fontSizeInput  = root.querySelector( '[data-yp-ld-font-size]' );
	var boldButton     = root.querySelector( '[data-yp-ld-bold]' );
	var italicButton   = root.querySelector( '[data-yp-ld-italic]' );
	var textAlignSelect = root.querySelector( '[data-yp-ld-text-align]' );
	var fillInput      = root.querySelector( '[data-yp-ld-fill]' );
	var strokeInput    = root.querySelector( '[data-yp-ld-stroke]' );
	var strokeWidthInput = root.querySelector( '[data-yp-ld-stroke-width]' );

	var layoutsEl = root.querySelector( '[data-yp-ld-layouts]' );
	var layoutButtons = layoutsEl ? Array.prototype.slice.call( layoutsEl.querySelectorAll( '[data-yp-ld-layout]' ) ) : [];
	var layoutDismissButton = root.querySelector( '[data-yp-ld-layout-dismiss]' );

	var editorRowEl    = root.querySelector( '.yp-ld__editor-row' );
	var safeZoneEl     = root.querySelector( '[data-yp-ld-safe-zone]' );
	var layersListEl   = root.querySelector( '[data-yp-ld-layers-list]' );

	var zoomOutButton   = root.querySelector( '[data-yp-ld-zoom-out]' );
	var zoomInButton    = root.querySelector( '[data-yp-ld-zoom-in]' );
	var zoomResetButton = root.querySelector( '[data-yp-ld-zoom-reset]' );
	var zoomLabelEl     = root.querySelector( '[data-yp-ld-zoom-label]' );

	var previewToggleButton = root.querySelector( '[data-yp-ld-preview-toggle]' );
	var productPreviewEl    = root.querySelector( '[data-yp-ld-product-preview]' );
	var previewSnapshotEl   = productPreviewEl ? productPreviewEl.querySelector( '[data-preview-snapshot]' ) : null;
	var backgroundFieldEl   = root.querySelector( '.yp-ld__background' );

	var FONT_FAMILIES = [
		'Inter', 'Geist', 'Playfair Display', 'Merriweather',
		'Poppins', 'Pacifico', 'Bebas Neue', 'Caveat',
		'Oswald', 'Lora', 'Josefin Sans', 'Dancing Script'
	];

	var materialsData      = [];
	var quantityPresets    = [];
	var canvas              = null;
	var history              = [];
	var historyIndex         = -1;
	var isRestoringHistory   = false;
	var priceDebounceTimer   = null;
	var draftSaveDebounceTimer = null;
	var formErrorEl          = null;
	var lastConfirmedDims    = null;
	var lastConfirmedSizePreset = null;
	var currentZoom          = 1;
	var snapGuideLines       = [];
	var nudgeHistoryDebounceTimer = null;

	// Incremented while a logo upload's fetch()+fabric.Image.fromURL()
	// round-trip is in flight (see the imageInput change handler below)
	// and checked by the submit handler — without this, hitting Submit
	// mid-upload would silently export a design missing that image,
	// since the object doesn't exist on the canvas until both steps
	// finish.
	var pendingUploadCount  = 0;

	// The 12 curated fonts (FONT_FAMILIES, populated further down) load
	// via one Google Fonts stylesheet with `display=swap` — the browser
	// renders text in a fallback face immediately and swaps the real one
	// in once its file downloads, with no automatic Fabric re-render on
	// that swap. document.fonts.load() resolves once a face is actually
	// usable; kicked off once canvas init knows FONT_FAMILIES exists, and
	// awaited before export so a customer who picks an unusual font and
	// submits within the first second doesn't ship text in the wrong
	// typeface.
	var fontsReadyPromise   = null;

	// Every image the customer uploads via "+ Image" (usually a logo) is
	// kept here by upload id, separate from the flattened PNG the canvas
	// exports on submit — that export is print-resolution but is a
	// re-rasterized composite of the whole label, not the original file.
	// Staff need the original (often higher-quality, sometimes vector-
	// sourced) logo on hand for print prep, so every id collected here
	// rides along on submit under CANVAS_SOURCE_IMAGE_UPLOADS, kept even
	// if the customer later deletes that image from the canvas — simpler
	// than tracking canvas-object-to-upload-id lifetime, and erring
	// toward keeping a file staff might still want beats losing it.
	var logoUploadIds       = [];

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}

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

	/* ---------- Setup: materials + quantity presets ---------- */

	function populateFontFamilySelect() {
		fontFamilySelect.innerHTML = FONT_FAMILIES.map( function ( family ) {
			return '<option value="' + escapeHtml( family ) + '" style="font-family:\'' + escapeHtml( family ) + '\'">' + escapeHtml( family ) + '</option>';
		} ).join( '' );
	}

	/**
	 * Standard CSS Font Loading API, not a new dependency — resolves once
	 * each curated face is actually usable (immediately if the browser
	 * already has it cached). document.fonts.ready is combined in too,
	 * as a belt-and-suspenders fallback for a font whose family name
	 * document.fonts.load() couldn't match exactly. Once ready, a single
	 * requestRenderAll() catches any text already typed in a font that
	 * was still swapping when it was drawn — the on-screen canvas ends
	 * up matching what will export, not just the export step itself.
	 */
	function startFontPreload() {
		if ( ! window.document.fonts || 'function' !== typeof document.fonts.load ) {
			fontsReadyPromise = Promise.resolve();
			return;
		}
		var loaders = FONT_FAMILIES.map( function ( family ) {
			return document.fonts.load( '600 16px "' + family + '"' ).catch( function () {} );
		} );
		fontsReadyPromise = Promise.all( loaders.concat( [ document.fonts.ready ] ) ).then( function () {
			if ( canvas ) {
				canvas.requestRenderAll();
			}
		} );
	}

	function populateMaterialSelect() {
		materialSelect.innerHTML = materialsData.map( function ( material ) {
			var label = material.name + ( false === material.in_stock ? ' (Out of stock)' : '' );
			return '<option value="' + material.id + '"' + ( false === material.in_stock ? ' disabled' : '' ) + '>' + escapeHtml( label ) + '</option>';
		} ).join( '' );
	}

	function populateQuantityPresets() {
		if ( ! quantityPresets.length ) {
			return;
		}
		quantityPresetsEl.innerHTML = quantityPresets.map( function ( preset ) {
			return '<button type="button" class="yp-quantity-preset" data-preset="' + preset + '">' + preset + '</button>';
		} ).join( '' );

		quantityPresetsEl.querySelectorAll( '[data-preset]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				quantityInput.value = button.getAttribute( 'data-preset' );
				refreshPricing();
			} );
		} );
	}

	function loadOptions() {
		return fetch( yeffoprintLabelDesigner.restUrl + 'custom-orders/options', {
			headers: { 'X-WP-Nonce': yeffoprintLabelDesigner.nonce }
		} )
			.then( function ( response ) { return response.json(); } )
			.then( function ( data ) {
				materialsData   = data.materials || [];
				quantityPresets = data.quantity_presets || [];
				populateMaterialSelect();
				populateQuantityPresets();
			} );
	}

	/* ---------- Canvas sizing ---------- */

	function pxPerInch( widthIn, heightIn ) {
		return Math.max( 20, Math.min( MAX_PX_PER_INCH, MAX_CANVAS_PX_WIDTH / widthIn, MAX_CANVAS_PX_HEIGHT / heightIn ) );
	}

	function currentDimensionsIn() {
		return {
			width:  Math.max( 0.2, parseFloat( widthInput.value ) || 0.2 ),
			height: Math.max( 0.2, parseFloat( heightInput.value ) || 0.2 )
		};
	}

	function checkedSizePresetRadio() {
		return sizePresetRadios.filter( function ( radio ) { return radio.checked; } )[ 0 ] || null;
	}

	function setSizeLock( isLocked ) {
		widthInput.disabled = isLocked;
		heightInput.disabled = isLocked;
	}

	function updateSizePresetHint( radio ) {
		if ( ! sizePresetHintEl ) {
			return;
		}
		if ( ! radio || 'custom' === radio.value ) {
			sizePresetHintEl.textContent = 'Custom size — enter your own width and height.';
			return;
		}
		var name = radio.closest( '.yp-size-preset' ).querySelector( '.yp-size-preset__name' ).textContent;
		sizePresetHintEl.textContent = 'Locked to ' + name + ' — choose Custom to enter your own width/height.';
	}

	function initCanvas() {
		var dims  = currentDimensionsIn();
		var scale = pxPerInch( dims.width, dims.height );
		lastConfirmedDims = dims;
		lastConfirmedSizePreset = checkedSizePresetRadio();

		canvas = new fabric.Canvas( 'yp-ld-canvas', {
			width:  dims.width * scale,
			height: dims.height * scale,
			backgroundColor: bgColorInput.value || '#ffffff',
			preserveObjectStacking: true
		} );

		canvas.on( 'object:added', onCanvasChanged );
		canvas.on( 'object:modified', onCanvasChanged );
		canvas.on( 'object:removed', onCanvasChanged );
		canvas.on( 'selection:created', onSelectionChanged );
		canvas.on( 'selection:updated', onSelectionChanged );
		canvas.on( 'selection:cleared', onSelectionChanged );
		canvas.on( 'object:moving', onObjectMoving );
		canvas.on( 'mouse:up', clearSnapGuides );

		applyZoom( 1 );
		renderLayersPanel();
		maybeShowLayoutsPicker();
		updateProductPreviewSilhouette();
		pushHistory();
	}

	function resizeCanvasToInputs() {
		if ( canvas.getObjects().length && ! window.confirm( 'Changing the label size will clear your current design. Continue?' ) ) {
			// Revert the inputs — and, if a size-preset radio triggered
			// this (rather than typing directly into the fields), revert
			// that too, or it would sit checked while showing the old
			// preset's dimensions: nothing to resize to, the customer
			// declined losing their design.
			widthInput.value  = lastConfirmedDims.width;
			heightInput.value = lastConfirmedDims.height;
			if ( lastConfirmedSizePreset ) {
				lastConfirmedSizePreset.checked = true;
				setSizeLock( 'custom' !== lastConfirmedSizePreset.value );
				updateSizePresetHint( lastConfirmedSizePreset );
			}
			return;
		}

		canvas.clear();
		canvas.backgroundColor = bgColorInput.value || '#ffffff';

		var dims  = currentDimensionsIn();
		applyZoom( 1 ); // A new size invalidates any prior zoom level — same rationale as the confirm above: start clean.
		lastConfirmedDims = dims;
		lastConfirmedSizePreset = checkedSizePresetRadio();
		renderLayersPanel();
		maybeShowLayoutsPicker();
		updateProductPreviewSilhouette();
		pushHistory();
		refreshPricing();
		saveDraftDebounced();
	}

	/* ---------- Zoom ---------- */

	/**
	 * Zoom is purely a *view* magnification, never a resize of the label
	 * itself — canvas.setZoom() scales how existing objects render
	 * without touching their own left/top/scale properties, and setting
	 * the canvas element's own width/height to the same multiple keeps
	 * the whole (now bigger/smaller) label fully visible rather than
	 * clipped, with .yp-ld__canvas-wrap's existing `overflow: auto`
	 * handling anything that still doesn't fit. Because pxPerInch()/
	 * currentDimensionsIn() are pure functions of the width/height
	 * inputs (never of currentZoom), the submit handler's own export
	 * multiplier math stays correct on its own — the one place zoom
	 * still has to be actively reset is right before toDataURL() itself,
	 * since Fabric's exported pixels otherwise reflect whatever zoom the
	 * canvas currently happens to be at.
	 */
	function applyZoom( zoom ) {
		if ( ! canvas ) {
			return;
		}
		currentZoom = Math.max( ZOOM_MIN, Math.min( ZOOM_MAX, zoom ) );
		var dims = currentDimensionsIn();
		var baseScale = pxPerInch( dims.width, dims.height );
		canvas.setZoom( currentZoom );
		canvas.setWidth( dims.width * baseScale * currentZoom );
		canvas.setHeight( dims.height * baseScale * currentZoom );
		canvas.renderAll();
		updateSafeZoneGuide();
		if ( zoomLabelEl ) {
			zoomLabelEl.textContent = Math.round( currentZoom * 100 ) + '%';
		}
	}

	if ( zoomInButton ) {
		zoomInButton.addEventListener( 'click', function () { applyZoom( currentZoom + ZOOM_STEP ); } );
	}
	if ( zoomOutButton ) {
		zoomOutButton.addEventListener( 'click', function () { applyZoom( currentZoom - ZOOM_STEP ); } );
	}
	if ( zoomResetButton ) {
		zoomResetButton.addEventListener( 'click', function () { applyZoom( 1 ); } );
	}

	/* ---------- Safe-zone guide ---------- */

	/**
	 * Direct request: keep the "final product as close to it as
	 * possible" — a dashed inset line warning that anything closer to
	 * the label's trim edge risks being cut off. Deliberately a plain
	 * DOM element positioned over the canvas (yp-ld__canvas-stage is
	 * position:relative and shrink-wraps to the canvas's own on-screen
	 * size — see label-designer.css), not a Fabric object: a Fabric
	 * object would need re-adding after every canvas.clear() (resize,
	 * "Start over") and every loadFromJSON() (undo/redo, draft restore),
	 * since none of those preserve arbitrary non-serialized objects. A
	 * plain DOM overlay needs none of that — it only has to be repainted
	 * when the canvas's on-screen size actually changes (dimensions or
	 * zoom), and since it's not part of the <canvas> element at all, it
	 * can never leak into canvas.toDataURL()'s export or canvas.toJSON()
	 * the way a same-canvas guide object would risk doing.
	 */
	function updateSafeZoneGuide() {
		if ( ! safeZoneEl || ! canvas ) {
			return;
		}
		var dims = currentDimensionsIn();
		var baseScale = pxPerInch( dims.width, dims.height );
		var marginPx = ( SAFE_ZONE_MARGIN_MM / MM_PER_INCH ) * baseScale * currentZoom;
		safeZoneEl.style.top    = marginPx + 'px';
		safeZoneEl.style.left   = marginPx + 'px';
		safeZoneEl.style.right  = marginPx + 'px';
		safeZoneEl.style.bottom = marginPx + 'px';
	}

	/* ---------- Alignment / snap guides ---------- */

	/**
	 * Every object this tool itself creates (addText/addShape/addIcon/
	 * the image-upload handler) sets originX/originY to 'center', so
	 * .left/.top already *are* each object's center coordinates — this
	 * lets edge/center snapping skip bounding-box origin math entirely
	 * and just work off left/top plus getScaledWidth()/Height(). Guides
	 * are temporary Fabric Lines (excludeFromExport, same as everything
	 * else that must never reach the print export or a saved draft),
	 * cleared and redrawn on every 'object:moving' tick and wiped on
	 * 'mouse:up' — see onCanvasChanged()'s own excludeFromExport check
	 * for why adding/removing these never pollutes undo history.
	 */
	function objectEdges( obj ) {
		var w = obj.getScaledWidth();
		var h = obj.getScaledHeight();
		return {
			centerX: obj.left,
			centerY: obj.top,
			left:    obj.left - ( w / 2 ),
			right:   obj.left + ( w / 2 ),
			top:     obj.top - ( h / 2 ),
			bottom:  obj.top + ( h / 2 )
		};
	}

	function findSnap( values, candidates ) {
		var best = null;
		values.forEach( function ( value ) {
			candidates.forEach( function ( candidate ) {
				var distance = Math.abs( value - candidate );
				if ( distance <= SNAP_THRESHOLD_PX && ( ! best || distance < best.distance ) ) {
					best = { value: value, target: candidate, distance: distance };
				}
			} );
		} );
		return best;
	}

	function addSnapGuide( isVertical, position ) {
		var points = isVertical
			? [ position, 0, position, canvas.getHeight() ]
			: [ 0, position, canvas.getWidth(), position ];
		var line = new fabric.Line( points, {
			stroke: '#EC008C',
			strokeWidth: 1,
			strokeDashArray: [ 4, 4 ],
			selectable: false,
			evented: false,
			excludeFromExport: true,
			hoverCursor: 'default'
		} );
		canvas.add( line );
		canvas.bringToFront( line );
		snapGuideLines.push( line );
	}

	function clearSnapGuides() {
		snapGuideLines.forEach( function ( line ) { canvas.remove( line ); } );
		snapGuideLines = [];
	}

	function onObjectMoving( event ) {
		var obj = event.target;
		if ( ! obj || 'center' !== obj.originX || 'center' !== obj.originY ) {
			return; // Safety net for the center-origin assumption above — every object this tool creates qualifies, so this only ever short-circuits on something unexpected.
		}
		clearSnapGuides();

		var edges = objectEdges( obj );
		var candidatesX = [ canvas.getWidth() / 2 ];
		var candidatesY = [ canvas.getHeight() / 2 ];

		canvas.getObjects().forEach( function ( other ) {
			if ( other === obj || other.excludeFromExport ) {
				return;
			}
			var otherEdges = objectEdges( other );
			candidatesX.push( otherEdges.left, otherEdges.centerX, otherEdges.right );
			candidatesY.push( otherEdges.top, otherEdges.centerY, otherEdges.bottom );
		} );

		var snapX = findSnap( [ edges.left, edges.centerX, edges.right ], candidatesX );
		if ( snapX ) {
			obj.set( 'left', obj.left + ( snapX.target - snapX.value ) );
			addSnapGuide( true, snapX.target );
		}

		var snapY = findSnap( [ edges.top, edges.centerY, edges.bottom ], candidatesY );
		if ( snapY ) {
			obj.set( 'top', obj.top + ( snapY.target - snapY.value ) );
			addSnapGuide( false, snapY.target );
		}

		obj.setCoords();
	}

	/* ---------- Layers panel ---------- */

	function objectLabel( obj ) {
		if ( 'textbox' === obj.type || 'text' === obj.type ) {
			var text = ( obj.text || '' ).trim();
			return 'Text: ' + ( text ? ( text.length > 20 ? text.slice( 0, 20 ) + '…' : text ) : '(empty)' );
		}
		if ( 'image' === obj.type ) { return 'Image'; }
		if ( 'rect' === obj.type ) { return 'Rectangle'; }
		if ( 'ellipse' === obj.type ) { return 'Ellipse'; }
		if ( 'triangle' === obj.type ) { return 'Triangle'; }
		if ( 'line' === obj.type ) { return 'Line'; }
		return 'Icon';
	}

	/**
	 * Locking is an editing convenience (prevents accidental selection/
	 * move), not a hide/exclude — a locked object still renders and
	 * still exports normally. Persists across undo/redo and a draft
	 * reload because EXTRA_OBJECT_PROPS tells canvas.toJSON() to keep
	 * `selectable`/`evented`/`hasControls` (Fabric's default serialization
	 * doesn't include them).
	 */
	function renderLayersPanel() {
		if ( ! layersListEl || ! canvas ) {
			return;
		}
		var objects = canvas.getObjects().filter( function ( obj ) { return ! obj.excludeFromExport; } );
		// getActiveObjects() (plural) returns every selected object in
		// both the single- and multi-select case, unlike
		// getActiveObject() — which returns the ActiveSelection wrapper
		// during a multi-select, something no individual obj here would
		// ever === match, leaving every row unhighlighted.
		var activeObjects = canvas.getActiveObjects();

		if ( ! objects.length ) {
			layersListEl.innerHTML = '<li class="yp-ld__layers-empty">No elements yet</li>';
			return;
		}

		layersListEl.innerHTML = objects.slice().reverse().map( function ( obj ) {
			var index = objects.indexOf( obj );
			var isLocked = false === obj.selectable;
			return '<li class="yp-ld__layer-row' + ( -1 !== activeObjects.indexOf( obj ) ? ' is-active' : '' ) + '">' +
				'<button type="button" class="yp-ld__layer-select" data-layer-select="' + index + '">' + escapeHtml( objectLabel( obj ) ) + '</button>' +
				'<button type="button" class="yp-ld__layer-lock' + ( isLocked ? ' is-active' : '' ) + '" data-layer-lock="' + index + '">' + ( isLocked ? 'Locked' : 'Lock' ) + '</button>' +
			'</li>';
		} ).join( '' );

		layersListEl.querySelectorAll( '[data-layer-select]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var obj = objects[ parseInt( button.getAttribute( 'data-layer-select' ), 10 ) ];
				if ( obj && false !== obj.selectable ) {
					canvas.setActiveObject( obj );
					canvas.renderAll();
				}
			} );
		} );

		layersListEl.querySelectorAll( '[data-layer-lock]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
				var obj = objects[ parseInt( button.getAttribute( 'data-layer-lock' ), 10 ) ];
				if ( ! obj ) {
					return;
				}
				var lock = false !== obj.selectable;
				if ( lock && obj === canvas.getActiveObject() ) {
					canvas.discardActiveObject();
				}
				obj.set( { selectable: ! lock, evented: ! lock, hasControls: ! lock } );
				canvas.renderAll();
				renderLayersPanel();
				pushHistory();
			} );
		} );
	}

	/* ---------- Starter layouts ---------- */

	/**
	 * Shown only while the canvas is genuinely empty (fresh visit, or a
	 * restored draft that happens to have nothing on it) — direct
	 * request: give the customer something other than a blank canvas to
	 * start from. Picking one just adds pre-positioned objects through
	 * the same canvas.add() path every other "+ Add" toolbar button
	 * already uses (percentage-of-canvas-size placement, same convention
	 * as addText()/addShape() below); "Start blank" (or ignoring the
	 * picker and using the toolbar directly) leaves today's empty-canvas
	 * behavior completely unchanged.
	 */
	var LAYOUTS = {
		centered: function () {
			var w = canvas.getWidth(), h = canvas.getHeight();
			return [
				new fabric.Textbox( 'Your Brand', {
					left: w / 2, top: h * 0.35, originX: 'center', originY: 'center',
					fontFamily: 'Geist', fontWeight: '700', fontSize: Math.round( h * 0.16 ),
					fill: '#141414', textAlign: 'center', width: w * 0.82
				} ),
				new fabric.Textbox( 'Product Name', {
					left: w / 2, top: h * 0.62, originX: 'center', originY: 'center',
					fontFamily: 'Inter', fontSize: Math.round( h * 0.09 ),
					fill: '#3A3A3C', textAlign: 'center', width: w * 0.82
				} )
			];
		},
		banner: function () {
			var w = canvas.getWidth(), h = canvas.getHeight();
			return [
				new fabric.Rect( {
					left: w / 2, top: h * 0.24, originX: 'center', originY: 'center',
					width: w * 0.96, height: h * 0.34, fill: '#141414'
				} ),
				new fabric.Textbox( 'Your Brand', {
					left: w / 2, top: h * 0.24, originX: 'center', originY: 'center',
					fontFamily: 'Geist', fontWeight: '700', fontSize: Math.round( h * 0.14 ),
					fill: '#ffffff', textAlign: 'center', width: w * 0.86
				} ),
				new fabric.Textbox( 'Product Name', {
					left: w / 2, top: h * 0.66, originX: 'center', originY: 'center',
					fontFamily: 'Inter', fontSize: Math.round( h * 0.09 ),
					fill: '#141414', textAlign: 'center', width: w * 0.86
				} )
			];
		},
		corner: function () {
			var w = canvas.getWidth(), h = canvas.getHeight();
			return [
				new fabric.Textbox( 'Brand', {
					left: w * 0.22, top: h * 0.2, originX: 'center', originY: 'center',
					fontFamily: 'Geist', fontWeight: '700', fontSize: Math.round( h * 0.12 ),
					fill: '#141414', textAlign: 'left', width: w * 0.5
				} ),
				new fabric.Textbox( 'Product Name', {
					left: w / 2, top: h * 0.65, originX: 'center', originY: 'center',
					fontFamily: 'Inter', fontSize: Math.round( h * 0.1 ),
					fill: '#3A3A3C', textAlign: 'center', width: w * 0.86
				} )
			];
		}
	};

	function hideLayoutsPicker() {
		if ( layoutsEl ) {
			layoutsEl.hidden = true;
		}
	}

	function maybeShowLayoutsPicker() {
		if ( ! layoutsEl || ! canvas ) {
			return;
		}
		var hasContent = canvas.getObjects().some( function ( obj ) { return ! obj.excludeFromExport; } );
		layoutsEl.hidden = hasContent;
	}

	function applyLayout( key ) {
		var factory = LAYOUTS[ key ];
		if ( ! factory || ! canvas ) {
			return;
		}
		factory().forEach( function ( obj ) { canvas.add( obj ); } );
		canvas.renderAll();
		hideLayoutsPicker();
		pushHistory();
		refreshPricing();
	}

	layoutButtons.forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			applyLayout( button.getAttribute( 'data-yp-ld-layout' ) );
		} );
	} );
	if ( layoutDismissButton ) {
		layoutDismissButton.addEventListener( 'click', hideLayoutsPicker );
	}

	/* ---------- Preview on Product ---------- */

	/**
	 * Deliberately a flat overlay, not a photorealistic cylindrical wrap
	 * simulation — the existing Template configurator's own "Vial View"
	 * (configurator.js) is a plain reference photo with no live
	 * compositing at all, so this is already a step up from that
	 * existing precedent, not a promise to exceed it. The silhouette is
	 * one of three inline SVGs in render.php (one per size preset),
	 * toggled by which one matches the checked size-preset radio's
	 * value; the label-shaped rectangle inside each SVG is purely a
	 * visual reference for where canvas.toDataURL()'s live snapshot gets
	 * overlaid via CSS (label-designer.css).
	 */
	function activeSizePresetValue() {
		var radio = checkedSizePresetRadio();
		return radio ? radio.value : 'custom';
	}

	function updateProductPreviewSilhouette() {
		if ( ! productPreviewEl ) {
			return;
		}
		var value = activeSizePresetValue();
		var visibleSvg = null;

		productPreviewEl.querySelectorAll( '[data-preview-silhouette]' ).forEach( function ( svg ) {
			var isMatch = svg.getAttribute( 'data-preview-silhouette' ) === value;
			svg.hidden = ! isMatch;
			if ( isMatch ) {
				visibleSvg = svg;
			}
		} );

		// Each silhouette SVG shares the same viewBox, so its own
		// data-preview-label-area rect (in viewBox units) converts
		// directly to a percentage position/size for the snapshot <img>
		// — a plain sibling in the same position:relative stage
		// (label-designer.css), not anything drawn into the SVG itself.
		var labelArea = visibleSvg ? visibleSvg.querySelector( '[data-preview-label-area]' ) : null;
		if ( labelArea && previewSnapshotEl && visibleSvg.viewBox && visibleSvg.viewBox.baseVal ) {
			var viewBox = visibleSvg.viewBox.baseVal;
			previewSnapshotEl.style.left   = ( ( parseFloat( labelArea.getAttribute( 'x' ) ) / viewBox.width ) * 100 ) + '%';
			previewSnapshotEl.style.top    = ( ( parseFloat( labelArea.getAttribute( 'y' ) ) / viewBox.height ) * 100 ) + '%';
			previewSnapshotEl.style.width  = ( ( parseFloat( labelArea.getAttribute( 'width' ) ) / viewBox.width ) * 100 ) + '%';
			previewSnapshotEl.style.height = ( ( parseFloat( labelArea.getAttribute( 'height' ) ) / viewBox.height ) * 100 ) + '%';
		}
	}

	function updateProductPreviewSnapshot() {
		if ( ! productPreviewEl || productPreviewEl.hidden || ! canvas || ! previewSnapshotEl ) {
			return;
		}
		previewSnapshotEl.src = canvas.toDataURL( { format: 'png' } );
	}

	function toggleProductPreview() {
		if ( ! productPreviewEl ) {
			return;
		}
		var opening = productPreviewEl.hidden;
		productPreviewEl.hidden = ! opening;
		if ( editorRowEl ) {
			editorRowEl.hidden = opening;
		}
		if ( backgroundFieldEl ) {
			backgroundFieldEl.hidden = opening;
		}
		if ( previewToggleButton ) {
			previewToggleButton.textContent = opening ? 'Back to Editing' : 'Preview on Product';
		}
		if ( opening ) {
			if ( propertiesEl ) {
				propertiesEl.hidden = true;
			}
			if ( layoutsEl ) {
				layoutsEl.hidden = true;
			}
			updateProductPreviewSilhouette();
			updateProductPreviewSnapshot();
		} else {
			// Resyncs the properties panel to whatever's actually selected
			// right now, rather than trying to remember its pre-preview
			// state (which entering preview mode above just overwrote).
			onSelectionChanged();
			maybeShowLayoutsPicker();
		}
	}

	if ( previewToggleButton ) {
		previewToggleButton.addEventListener( 'click', toggleProductPreview );
	}

	/* ---------- Undo / redo ---------- */

	function pushHistory() {
		if ( isRestoringHistory ) {
			return;
		}
		history = history.slice( 0, historyIndex + 1 );
		history.push( JSON.stringify( canvas.toJSON( EXTRA_OBJECT_PROPS ) ) );
		historyIndex = history.length - 1;
		updateHistoryButtons();
		saveDraftDebounced();
	}

	function onCanvasChanged( event ) {
		// Fires for the temporary snap-guide Lines too (they're plain
		// canvas.add()/remove() calls like any other object) — those
		// must never count as a real design change: no history entry,
		// no pricing refresh, no layers-panel row.
		if ( event && event.target && event.target.excludeFromExport ) {
			return;
		}
		pushHistory();
		refreshPricing();
		renderLayersPanel();
		updateProductPreviewSnapshot();
	}

	function updateHistoryButtons() {
		undoButton.disabled = historyIndex <= 0;
		redoButton.disabled = historyIndex >= history.length - 1;
	}

	function restoreHistory( index ) {
		isRestoringHistory = true;
		canvas.loadFromJSON( history[ index ], function () {
			canvas.renderAll();
			isRestoringHistory = false;
			historyIndex = index;
			updateHistoryButtons();
			renderLayersPanel();
			maybeShowLayoutsPicker();
			updateProductPreviewSnapshot();
			saveDraftDebounced();
		} );
	}

	undoButton.addEventListener( 'click', function () {
		if ( historyIndex > 0 ) {
			restoreHistory( historyIndex - 1 );
		}
	} );

	redoButton.addEventListener( 'click', function () {
		if ( historyIndex < history.length - 1 ) {
			restoreHistory( historyIndex + 1 );
		}
	} );

	clearButton.addEventListener( 'click', function () {
		if ( canvas.getObjects().length && ! window.confirm( 'Clear your whole design and start over?' ) ) {
			return;
		}
		canvas.clear();
		canvas.backgroundColor = bgColorInput.value || '#ffffff';
		canvas.renderAll();
		renderLayersPanel();
		maybeShowLayoutsPicker();
		pushHistory();
	} );

	/* ---------- Selection / delete / layering ---------- */

	function onSelectionChanged() {
		var active = canvas.getActiveObject();
		deleteButton.disabled    = ! active;
		duplicateButton.disabled = ! active;
		frontButton.disabled  = ! active;
		backButton.disabled   = ! active;
		renderLayersPanel();

		if ( ! active ) {
			propertiesEl.hidden = true;
			return;
		}

		propertiesEl.hidden = false;
		var isText = 'textbox' === active.type;

		propertiesEl.querySelector( '[data-yp-ld-prop="font-family"]' ).hidden = ! isText;
		propertiesEl.querySelector( '[data-yp-ld-prop="font-size"]' ).hidden = ! isText;
		propertiesEl.querySelector( '[data-yp-ld-prop="text-style"]' ).hidden = ! isText;
		propertiesEl.querySelector( '[data-yp-ld-prop="align"]' ).hidden = ! isText;
		propertiesEl.querySelector( '[data-yp-ld-prop="stroke"]' ).hidden = isText;
		propertiesEl.querySelector( '[data-yp-ld-prop="stroke-width"]' ).hidden = isText;

		if ( isText ) {
			fontFamilySelect.value = active.fontFamily || 'Inter';
			fontSizeInput.value    = active.fontSize || 24;
			boldButton.classList.toggle( 'is-active', 'bold' === active.fontWeight );
			italicButton.classList.toggle( 'is-active', 'italic' === active.fontStyle );
			textAlignSelect.value  = active.textAlign || 'left';
			fillInput.value        = toHexColor( active.fill ) || '#000000';
		} else {
			fillInput.value   = toHexColor( active.fill ) || '#000000';
			strokeInput.value = toHexColor( active.stroke ) || '#000000';
			strokeWidthInput.value = active.strokeWidth || 0;
		}
	}

	function toHexColor( value ) {
		if ( ! value || 'string' !== typeof value || '#' !== value.charAt( 0 ) ) {
			return null;
		}
		return value.length === 7 ? value : null;
	}

	/**
	 * canvas.remove() looks up each argument in canvas._objects by
	 * identity — an active ActiveSelection (a shift-click or marquee
	 * multi-select) is never itself a member of that array, it's a
	 * virtual grouping object, so canvas.remove(active) on a multi-
	 * select silently removed nothing at all. Discard the selection
	 * first (dropping the ActiveSelection wrapper) and remove the
	 * underlying objects directly — remove() already accepts multiple
	 * arguments, so this covers both the single- and multi-select case.
	 */
	deleteButton.addEventListener( 'click', function () {
		var active = canvas.getActiveObject();
		if ( ! active ) {
			return;
		}
		var targets = 'activeSelection' === active.type ? active.getObjects().slice() : [ active ];
		canvas.discardActiveObject();
		canvas.remove.apply( canvas, targets );
		canvas.renderAll();
	} );

	frontButton.addEventListener( 'click', function () {
		var active = canvas.getActiveObject();
		if ( active ) {
			canvas.bringToFront( active );
			renderLayersPanel();
			pushHistory();
		}
	} );

	backButton.addEventListener( 'click', function () {
		var active = canvas.getActiveObject();
		if ( active ) {
			canvas.sendToBack( active );
			renderLayersPanel();
			pushHistory();
		}
	} );

	/**
	 * Works on a single object or a multi-select alike. fabric.Object's
	 * clone() is async in v5 (same callback shape as fabric.Image.fromURL()
	 * above, since a clone may need to re-load an image source) — a
	 * pending-count is used instead of Promise.all() to stay in the same
	 * callback style the rest of this file already uses. Each clone gets
	 * a small fixed offset so it reads as a new object sitting next to
	 * the original, not directly on top of it; a real design tool leaves
	 * the *new* copy selected (and moving) rather than the original, so
	 * the clones become the active selection once every clone() callback
	 * has fired.
	 */
	function duplicateActive() {
		var active = canvas.getActiveObject();
		if ( ! active ) {
			return;
		}
		var sources = 'activeSelection' === active.type ? active.getObjects().slice() : [ active ];
		var clones = [];
		var remaining = sources.length;

		canvas.discardActiveObject();

		sources.forEach( function ( source ) {
			source.clone( function ( clone ) {
				clone.set( { left: source.left + 14, top: source.top + 14 } );
				clone.setCoords();
				canvas.add( clone );
				clones.push( clone );
				remaining--;
				if ( 0 === remaining ) {
					if ( clones.length > 1 ) {
						canvas.setActiveObject( new fabric.ActiveSelection( clones, { canvas: canvas } ) );
					} else {
						canvas.setActiveObject( clones[ 0 ] );
					}
					canvas.requestRenderAll();
				}
			}, EXTRA_OBJECT_PROPS );
		} );
	}

	duplicateButton.addEventListener( 'click', duplicateActive );

	document.addEventListener( 'keydown', function ( event ) {
		if ( ! canvas || document.activeElement !== document.body ) {
			return;
		}
		var active = canvas.getActiveObject();

		if ( ( 'Delete' === event.key || 'Backspace' === event.key ) && active ) {
			deleteButton.click();
			return;
		}

		if ( ( event.metaKey || event.ctrlKey ) && 'd' === event.key.toLowerCase() && active ) {
			event.preventDefault();
			duplicateActive();
			return;
		}

		var arrowDeltas = { ArrowUp: [ 0, -1 ], ArrowDown: [ 0, 1 ], ArrowLeft: [ -1, 0 ], ArrowRight: [ 1, 0 ] };
		if ( arrowDeltas[ event.key ] && active ) {
			// Textbox-in-edit-mode gets to keep the arrow keys for
			// moving its text cursor — only nudge the object when it's
			// merely selected, not actively being typed into.
			if ( active.isEditing ) {
				return;
			}
			event.preventDefault();
			var step  = event.shiftKey ? 10 : 1;
			var delta = arrowDeltas[ event.key ];
			active.set( { left: active.left + delta[ 0 ] * step, top: active.top + delta[ 1 ] * step } );
			active.setCoords();
			canvas.requestRenderAll();

			// Debounced like saveDraftDebounced()/priceDebounceTimer
			// elsewhere in this file — holding an arrow key fires many
			// keydowns per second, and pushing one undo-history entry
			// per repeat would make Undo useless for anything else.
			clearTimeout( nudgeHistoryDebounceTimer );
			nudgeHistoryDebounceTimer = setTimeout( function () {
				pushHistory();
				refreshPricing();
				updateProductPreviewSnapshot();
			}, 400 );
		}
	} );

	/* ---------- Property panel bindings ---------- */

	function withActive( callback ) {
		var active = canvas.getActiveObject();
		if ( active ) {
			callback( active );
			canvas.renderAll();
			pushHistory();
		}
	}

	fontFamilySelect.addEventListener( 'change', function () {
		withActive( function ( obj ) { obj.set( 'fontFamily', fontFamilySelect.value ); } );
	} );

	fontSizeInput.addEventListener( 'change', function () {
		withActive( function ( obj ) { obj.set( 'fontSize', parseInt( fontSizeInput.value, 10 ) || 24 ); } );
	} );

	boldButton.addEventListener( 'click', function () {
		withActive( function ( obj ) {
			var next = 'bold' === obj.fontWeight ? 'normal' : 'bold';
			obj.set( 'fontWeight', next );
			boldButton.classList.toggle( 'is-active', 'bold' === next );
		} );
	} );

	italicButton.addEventListener( 'click', function () {
		withActive( function ( obj ) {
			var next = 'italic' === obj.fontStyle ? 'normal' : 'italic';
			obj.set( 'fontStyle', next );
			italicButton.classList.toggle( 'is-active', 'italic' === next );
		} );
	} );

	textAlignSelect.addEventListener( 'change', function () {
		withActive( function ( obj ) { obj.set( 'textAlign', textAlignSelect.value ); } );
	} );

	fillInput.addEventListener( 'input', function () {
		withActive( function ( obj ) { obj.set( 'fill', fillInput.value ); } );
	} );

	strokeInput.addEventListener( 'input', function () {
		withActive( function ( obj ) { obj.set( 'stroke', strokeInput.value ); } );
	} );

	strokeWidthInput.addEventListener( 'change', function () {
		withActive( function ( obj ) { obj.set( 'strokeWidth', parseInt( strokeWidthInput.value, 10 ) || 0 ); } );
	} );

	/* ---------- Adding elements ---------- */

	function centerOf() {
		return { left: canvas.getWidth() / 2, top: canvas.getHeight() / 2 };
	}

	function addAndSelect( obj ) {
		canvas.add( obj );
		canvas.setActiveObject( obj );
		canvas.renderAll();
	}

	function addText() {
		var center = centerOf();
		addAndSelect( new fabric.Textbox( 'Your text', {
			left: center.left, top: center.top, originX: 'center', originY: 'center',
			fontFamily: 'Inter', fontSize: Math.round( canvas.getWidth() / 10 ), fill: '#000000',
			width: canvas.getWidth() * 0.7
		} ) );
	}

	function addShape( kind ) {
		var center = centerOf();
		var size    = Math.min( canvas.getWidth(), canvas.getHeight() ) * 0.35;
		var common  = { left: center.left, top: center.top, originX: 'center', originY: 'center', fill: '#C2007A' };
		var shape;

		if ( 'rect' === kind ) {
			shape = new fabric.Rect( Object.assign( {}, common, { width: size * 1.4, height: size } ) );
		} else if ( 'ellipse' === kind ) {
			shape = new fabric.Ellipse( Object.assign( {}, common, { rx: size * 0.7, ry: size * 0.5 } ) );
		} else if ( 'triangle' === kind ) {
			shape = new fabric.Triangle( Object.assign( {}, common, { width: size, height: size } ) );
		} else if ( 'line' === kind ) {
			shape = new fabric.Line( [ -size, 0, size, 0 ], { left: center.left, top: center.top, originX: 'center', originY: 'center', stroke: '#000000', strokeWidth: 6 } );
		}

		if ( shape ) {
			addAndSelect( shape );
		}
	}

	toolbarEl.querySelectorAll( '[data-yp-ld-add]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var kind = button.getAttribute( 'data-yp-ld-add' );
			if ( 'text' === kind ) {
				addText();
			} else {
				addShape( kind );
			}
		} );
	} );

	/* ---------- Icons ---------- */

	function renderIconPanel() {
		var icons = window.yeffoprintLabelDesignerIcons || [];
		iconPanel.innerHTML = icons.map( function ( icon ) {
			return '<button type="button" class="yp-ld__icon-button" data-icon-id="' + icon.id + '" title="' + escapeHtml( icon.label ) + '">' + icon.svg + '</button>';
		} ).join( '' );

		iconPanel.querySelectorAll( '[data-icon-id]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				addIcon( button.getAttribute( 'data-icon-id' ) );
			} );
		} );
	}

	function addIcon( iconId ) {
		var icon = ( window.yeffoprintLabelDesignerIcons || [] ).filter( function ( i ) { return i.id === iconId; } )[ 0 ];
		if ( ! icon ) {
			return;
		}

		fabric.loadSVGFromString( icon.svg, function ( objects, options ) {
			var group  = fabric.util.groupSVGElements( objects, options );
			var center = centerOf();
			var size    = Math.min( canvas.getWidth(), canvas.getHeight() ) * 0.3;

			group.set( {
				left: center.left, top: center.top, originX: 'center', originY: 'center',
				fill: '#141414'
			} );
			group.scaleToWidth( size );
			addAndSelect( group );
		} );
	}

	iconToggle.addEventListener( 'click', function () {
		iconPanel.hidden = ! iconPanel.hidden;
	} );

	/* ---------- Image upload ---------- */

	imageToggle.addEventListener( 'click', function () {
		imageInput.click();
	} );

	imageInput.addEventListener( 'change', function () {
		var file = imageInput.files[ 0 ];
		imageInput.value = '';
		if ( ! file ) {
			return;
		}

		var formData = new FormData();
		formData.append( 'files[]', file );

		// Counted from click to canvas-add (not just the network request):
		// the submit handler checks this before export, since the object
		// doesn't exist on the canvas — and so wouldn't make it into the
		// exported PNG — until fabric.Image.fromURL()'s callback fires,
		// which is a second async step after the upload itself finishes.
		pendingUploadCount++;

		fetch( yeffoprintLabelDesigner.restUrl + 'custom-orders/uploads', {
			method: 'POST',
			headers: { 'X-WP-Nonce': yeffoprintLabelDesigner.nonce },
			body: formData
		} )
			.then( function ( response ) { return response.json(); } )
			.then( function ( data ) {
				var result = ( data.files || [] )[ 0 ];
				if ( ! result || ! result.success ) {
					showFormError( ( result && result.message ) || "Couldn't upload that image." );
					pendingUploadCount--;
					return;
				}

				if ( result.id && logoUploadIds.indexOf( result.id ) === -1 ) {
					logoUploadIds.push( result.id );
				}

				fabric.Image.fromURL( result.url, function ( img ) {
					var maxSize = Math.min( canvas.getWidth(), canvas.getHeight() ) * 0.6;
					if ( img.width > maxSize || img.height > maxSize ) {
						img.scaleToWidth( maxSize );
					}
					var center = centerOf();
					img.set( { left: center.left, top: center.top, originX: 'center', originY: 'center' } );
					addAndSelect( img );
					pendingUploadCount--;
				}, { crossOrigin: 'anonymous' } );
			} )
			.catch( function () {
				showFormError( "Couldn't upload that image. Please try again." );
				pendingUploadCount--;
			} );
	} );

	/* ---------- Background color ---------- */

	bgColorInput.addEventListener( 'input', function () {
		canvas.backgroundColor = bgColorInput.value;
		canvas.renderAll();
	} );
	bgColorInput.addEventListener( 'change', function () { pushHistory(); } );

	/* ---------- Dimension inputs ---------- */

	widthInput.addEventListener( 'change', function () { resizeCanvasToInputs(); } );
	heightInput.addEventListener( 'change', function () { resizeCanvasToInputs(); } );

	/**
	 * Size presets — direct request: "some preset size options and also
	 * a custom option: Peptide Vials 45mmx21mm, Oils Labels: 60x30,
	 * Custom." Presets just pre-fill + lock the same width/height inputs
	 * everything else already reads from (currentDimensionsIn(), pricing,
	 * the draft) rather than being a parallel source of truth — "Custom"
	 * simply unlocks them back to today's manual entry. width/height stay
	 * in inches throughout (what the canvas and pricing formula use); the
	 * preset values are pre-converted from the requested mm sizes. The
	 * `step` on both inputs moved from 0.1 to 0.01 so those conversions
	 * (e.g. 45mm -> 1.77in) land on an exact step multiple — the native
	 * number input would otherwise silently block form submission on a
	 * value that doesn't align to `step`.
	 */
	sizePresetRadios.forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			if ( ! radio.checked ) {
				return;
			}
			updateSizePresetHint( radio );
			updateProductPreviewSilhouette();
			if ( 'custom' === radio.value ) {
				setSizeLock( false );
				lastConfirmedSizePreset = radio;
				return;
			}
			setSizeLock( true );
			widthInput.value  = radio.dataset.widthIn;
			heightInput.value = radio.dataset.heightIn;
			resizeCanvasToInputs(); // Also updates lastConfirmedSizePreset on confirm, refreshes pricing, and saves the draft.
		} );
	} );

	/* ---------- Pricing ---------- */

	function materialAdjustment( materialId ) {
		var material = materialsData.filter( function ( m ) { return m.id === materialId; } )[ 0 ];
		return material && material.price_adjustment ? parseFloat( material.price_adjustment ) : 0;
	}

	function formatMoney( amount ) {
		return '$' + amount.toFixed( 2 );
	}

	function localEstimate() {
		var dims      = currentDimensionsIn();
		var widthMm   = dims.width * MM_PER_INCH;
		var heightMm  = dims.height * MM_PER_INCH;
		var materialId = parseInt( materialSelect.value, 10 ) || 0;
		var quantity   = Math.max( 1, parseInt( quantityInput.value, 10 ) || 1 );

		// Same $25 design fee as any other new-design request — this
		// design is a template our designer still builds the real print
		// file from, not a print-ready file itself (direct clarification:
		// "I can't just download that and print").
		var designFee = parseFloat( yeffoprintLabelDesigner.designFee ) || 0;
		var unitPrice = 0.3168 + ( 0.0000351 * widthMm * heightMm ) + materialAdjustment( materialId );

		if ( feeEl ) {
			feeEl.textContent = formatMoney( designFee );
		}
		unitPriceEl.textContent = formatMoney( unitPrice );
		totalEl.textContent     = formatMoney( ( unitPrice * quantity ) + designFee );
	}

	function fetchAuthoritativePricing() {
		var dims     = currentDimensionsIn();
		var widthMm  = dims.width * MM_PER_INCH;
		var heightMm = dims.height * MM_PER_INCH;
		var materialId = parseInt( materialSelect.value, 10 ) || 0;
		var quantity   = Math.max( 1, parseInt( quantityInput.value, 10 ) || 1 );

		if ( ! materialId ) {
			return;
		}

		fetch( yeffoprintLabelDesigner.restUrl + 'custom-orders/pricing-preview', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': yeffoprintLabelDesigner.nonce },
			body: JSON.stringify( {
				mode: 'new_design',
				width_mm: widthMm,
				height_mm: heightMm,
				material_id: materialId,
				quantity: quantity
			} )
		} )
			.then( function ( response ) { return response.ok ? response.json() : null; } )
			.then( function ( data ) {
				if ( ! data || ! data.rows || ! data.rows[ 0 ] ) {
					return;
				}
				if ( feeEl ) {
					feeEl.textContent = formatMoney( data.design_fee );
				}
				unitPriceEl.textContent = formatMoney( data.rows[ 0 ].unit_price_after_discount );
				totalEl.textContent     = formatMoney( data.total );
			} )
			.catch( function () {} );
	}

	function refreshPricing() {
		localEstimate();
		clearTimeout( priceDebounceTimer );
		priceDebounceTimer = setTimeout( fetchAuthoritativePricing, 400 );
	}

	materialSelect.addEventListener( 'change', refreshPricing );
	quantityInput.addEventListener( 'input', refreshPricing );
	quantityInput.addEventListener( 'change', saveDraftDebounced );

	/* ---------- Draft autosave (localStorage) ---------- */

	function saveDraftDebounced() {
		clearTimeout( draftSaveDebounceTimer );
		draftSaveDebounceTimer = setTimeout( saveDraft, 600 );
	}

	function saveDraft() {
		if ( ! canvas ) {
			return;
		}
		try {
			var checkedPreset = checkedSizePresetRadio();
			window.localStorage.setItem( DRAFT_STORAGE_KEY, JSON.stringify( {
				widthIn: widthInput.value,
				heightIn: heightInput.value,
				sizePreset: checkedPreset ? checkedPreset.value : '',
				materialId: materialSelect.value,
				quantity: quantityInput.value,
				bgColor: bgColorInput.value,
				brandName: brandInput.value,
				notes: notesInput.value,
				canvasJson: canvas.toJSON( EXTRA_OBJECT_PROPS ),
				logoUploadIds: logoUploadIds
			} ) );
		} catch ( e ) {
			// Private browsing / storage disabled / quota exceeded — the
			// design itself is unaffected, just not recoverable on reload.
		}
	}

	function loadDraft() {
		var raw;
		try {
			raw = window.localStorage.getItem( DRAFT_STORAGE_KEY );
		} catch ( e ) {
			return null;
		}
		if ( ! raw ) {
			return null;
		}
		try {
			return JSON.parse( raw );
		} catch ( e ) {
			return null;
		}
	}

	function clearDraft() {
		try {
			window.localStorage.removeItem( DRAFT_STORAGE_KEY );
		} catch ( e ) {}
	}

	/* ---------- Submit ---------- */

	function dataUrlToBlob( dataUrl ) {
		var parts = dataUrl.split( ',' );
		var mime  = parts[ 0 ].match( /:(.*?);/ )[ 1 ];
		var binary = atob( parts[ 1 ] );
		var bytes  = new Uint8Array( binary.length );
		for ( var i = 0; i < binary.length; i++ ) {
			bytes[ i ] = binary.charCodeAt( i );
		}
		return new Blob( [ bytes ], { type: mime } );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		clearFormError();

		var dims       = currentDimensionsIn();
		var materialId = parseInt( materialSelect.value, 10 ) || 0;
		var quantity   = parseInt( quantityInput.value, 10 ) || 0;
		var brandName  = brandInput.value.trim();

		if ( ! brandName ) {
			showFormError( 'Brand name is required.' );
			return;
		}
		if ( ! materialId ) {
			showFormError( 'Please choose a material.' );
			return;
		}
		if ( quantity < 1 ) {
			showFormError( 'Quantity must be at least 1.' );
			return;
		}
		if ( ! canvas.getObjects().length ) {
			showFormError( 'Add at least one element to your label before continuing.' );
			return;
		}
		// A logo upload is a two-step async round-trip (REST upload, then
		// fabric.Image.fromURL() to actually add it to the canvas) — the
		// object simply isn't there yet to export if this fires mid-upload.
		if ( pendingUploadCount > 0 ) {
			showFormError( 'Please wait for your image to finish uploading before continuing.' );
			return;
		}

		submitButton.disabled = true;
		submitButton.textContent = 'Finalizing fonts…';

		// Google Fonts load with display=swap — text can still be showing
		// a fallback face at this exact moment if a font was only just
		// picked. Wait for fontsReadyPromise (already resolved in the
		// overwhelmingly common case, so this is normally instant) before
		// exporting, so the print file never ships in the wrong typeface.
		( fontsReadyPromise || Promise.resolve() ).then( function () {
			submitButton.textContent = 'Exporting your design…';

			// Zoom is a view-only magnification (see applyZoom()'s own
			// comment) — exporting while zoomed in/out would otherwise feed
			// Fabric's own current zoom state into toDataURL() on top of the
			// multiplier below, which isn't a combination worth trusting.
			// Reset to 1 right before export; restored below only on
			// failure, since success navigates away before it'd matter.
			var zoomBeforeExport = currentZoom;
			if ( 1 !== currentZoom ) {
				applyZoom( 1 );
			}

			var widthMm  = dims.width * MM_PER_INCH;
			var heightMm = dims.height * MM_PER_INCH;
			var currentScale = pxPerInch( dims.width, dims.height );
			var multiplier    = ( EXPORT_DPI ) / currentScale;

			var dataUrl = canvas.toDataURL( { format: 'png', multiplier: multiplier } );
			var blob     = dataUrlToBlob( dataUrl );
			var file     = new File( [ blob ], 'label-design.png', { type: 'image/png' } );

			var formData = new FormData();
			formData.append( 'files[]', file );

			fetch( yeffoprintLabelDesigner.restUrl + 'custom-orders/uploads', {
				method: 'POST',
				headers: { 'X-WP-Nonce': yeffoprintLabelDesigner.nonce },
				body: formData
			} )
				.then( function ( response ) { return response.json(); } )
				.then( function ( data ) {
					var result = ( data.files || [] )[ 0 ];
					if ( ! result || ! result.success ) {
						throw new Error( ( result && result.message ) || "Couldn't upload your design." );
					}

					return fetch( yeffoprintLabelDesigner.restUrl + 'custom-orders', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': yeffoprintLabelDesigner.nonce },
						body: JSON.stringify( {
							mode: 'new_design',
							uploads: [ result.id ],
							source_image_uploads: logoUploadIds,
							width_mm: widthMm,
							height_mm: heightMm,
							material_id: materialId,
							quantity: quantity,
							brand_name: brandName,
							instructions: notesInput.value,
							canvas_design: JSON.stringify( canvas.toJSON( EXTRA_OBJECT_PROPS ) )
						} )
					} );
				} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					if ( ! result.ok ) {
						throw new Error( ( result.data && result.data.message ) || "Couldn't submit your design. Please try again." );
					}
					clearDraft();
					window.location.href = result.data.checkout_url;
				} )
				.catch( function ( error ) {
					submitButton.disabled = false;
					submitButton.textContent = 'Continue to Payment';
					showFormError( error.message || "Couldn't submit your design. Please try again." );
					if ( 1 !== zoomBeforeExport ) {
						applyZoom( zoomBeforeExport );
					}
				} );
		} );
	} );

	/* ---------- Init ---------- */

	function restoreDraftIfAny() {
		var draft = loadDraft();
		if ( ! draft ) {
			return;
		}

		widthInput.value  = draft.widthIn || widthInput.value;
		heightInput.value = draft.heightIn || heightInput.value;
		bgColorInput.value = draft.bgColor || bgColorInput.value;
		brandInput.value   = draft.brandName || '';
		notesInput.value   = draft.notes || '';

		// draft.sizePreset only exists on a draft saved after this feature
		// shipped — an older draft (or one saved while "Custom" was
		// selected) falls back to 'custom' so its saved width/height are
		// never silently overwritten by a preset default.
		var draftPreset = sizePresetRadios.filter( function ( radio ) { return radio.value === draft.sizePreset; } )[ 0 ]
			|| sizePresetRadios.filter( function ( radio ) { return 'custom' === radio.value; } )[ 0 ]
			|| null;
		if ( draftPreset ) {
			draftPreset.checked = true;
			sizePresetRadios.forEach( function ( radio ) {
				if ( radio !== draftPreset ) {
					radio.checked = false;
				}
			} );
			setSizeLock( 'custom' !== draftPreset.value );
			updateSizePresetHint( draftPreset );
		}

		initCanvas();
		updateProductPreviewSilhouette();

		if ( draft.canvasJson ) {
			isRestoringHistory = true;
			canvas.loadFromJSON( draft.canvasJson, function () {
				canvas.renderAll();
				isRestoringHistory = false;
				renderLayersPanel();
				maybeShowLayoutsPicker();
				pushHistory();
				refreshPricing();
			} );
		}

		if ( draft.materialId ) {
			materialSelect.value = draft.materialId;
		}
		if ( draft.quantity ) {
			quantityInput.value = draft.quantity;
		}
		if ( Array.isArray( draft.logoUploadIds ) ) {
			logoUploadIds = draft.logoUploadIds;
		}
	}

	function init() {
		populateFontFamilySelect();
		startFontPreload();
		renderIconPanel();

		loadOptions().then( function () {
			if ( loadDraft() ) {
				restoreDraftIfAny();
			} else {
				initCanvas();
			}

			refreshPricing();

			statusEl.hidden = true;
			form.hidden = false;
		} ).catch( function () {
			statusEl.textContent = "Couldn't load the designer. Please refresh and try again.";
			statusEl.setAttribute( 'data-state', 'error' );
		} );
	}

	init();
} )();
