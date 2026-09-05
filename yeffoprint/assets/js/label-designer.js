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
 * Submits through the *existing* Custom Design own_design mode
 * (class-custom-order-controller.php) exactly like a customer who
 * already has a print-ready file elsewhere — no design fee, the
 * exported PNG becomes the print file, and it goes through the same
 * proof/status pipeline as any other Custom Design order. Pricing is
 * the one place this flow diverges from every other one on the site:
 * width_mm/height_mm (not a preset Size) drive a dynamic per-label
 * price server-side (YeffoPrint_Pricing_Rule::dynamic_base_price()) —
 * this file only ever shows an *estimate* of that; the authoritative
 * number always comes from a pricing-preview round trip, same
 * "instant estimate, server has final say" pattern configurator.js
 * already uses for Template pricing.
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

	var statusEl   = root.querySelector( '.yp-configurator__status' );
	var form       = document.getElementById( 'yp-label-designer-form' );
	var widthInput  = document.getElementById( 'yp-ld-width' );
	var heightInput = document.getElementById( 'yp-ld-height' );
	var materialSelect  = document.getElementById( 'yp-ld-material' );
	var quantityInput   = document.getElementById( 'yp-ld-quantity' );
	var quantityPresetsEl = root.querySelector( '[data-yp-ld-quantity-presets]' );
	var bgColorInput  = document.getElementById( 'yp-ld-bg-color' );
	var brandInput    = document.getElementById( 'yp-ld-brand' );
	var notesInput    = document.getElementById( 'yp-ld-notes' );
	var unitPriceEl   = root.querySelector( '[data-yp-ld-unit-price]' );
	var totalEl       = root.querySelector( '[data-yp-ld-total]' );
	var submitButton  = document.getElementById( 'yp-ld-submit' ) || root.querySelector( '[data-yp-ld-submit]' );

	var toolbarEl   = root.querySelector( '.yp-ld__toolbar' );
	var undoButton  = root.querySelector( '[data-yp-ld-undo]' );
	var redoButton  = root.querySelector( '[data-yp-ld-redo]' );
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

	function initCanvas() {
		var dims  = currentDimensionsIn();
		var scale = pxPerInch( dims.width, dims.height );
		lastConfirmedDims = dims;

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

		pushHistory();
	}

	function resizeCanvasToInputs() {
		if ( canvas.getObjects().length && ! window.confirm( 'Changing the label size will clear your current design. Continue?' ) ) {
			// Revert the inputs to the last confirmed size — nothing to
			// resize to, the customer declined losing their design.
			widthInput.value  = lastConfirmedDims.width;
			heightInput.value = lastConfirmedDims.height;
			return;
		}

		canvas.clear();
		canvas.backgroundColor = bgColorInput.value || '#ffffff';

		var dims  = currentDimensionsIn();
		var scale = pxPerInch( dims.width, dims.height );
		canvas.setWidth( dims.width * scale );
		canvas.setHeight( dims.height * scale );
		canvas.renderAll();
		lastConfirmedDims = dims;
		pushHistory();
		refreshPricing();
		saveDraftDebounced();
	}

	/* ---------- Undo / redo ---------- */

	function pushHistory() {
		if ( isRestoringHistory ) {
			return;
		}
		history = history.slice( 0, historyIndex + 1 );
		history.push( JSON.stringify( canvas.toJSON() ) );
		historyIndex = history.length - 1;
		updateHistoryButtons();
		saveDraftDebounced();
	}

	function onCanvasChanged() {
		pushHistory();
		refreshPricing();
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
		pushHistory();
	} );

	/* ---------- Selection / delete / layering ---------- */

	function onSelectionChanged() {
		var active = canvas.getActiveObject();
		deleteButton.disabled = ! active;
		frontButton.disabled  = ! active;
		backButton.disabled   = ! active;

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

	deleteButton.addEventListener( 'click', function () {
		var active = canvas.getActiveObject();
		if ( ! active ) {
			return;
		}
		canvas.discardActiveObject();
		canvas.remove( active );
		canvas.renderAll();
	} );

	frontButton.addEventListener( 'click', function () {
		var active = canvas.getActiveObject();
		if ( active ) {
			canvas.bringToFront( active );
			pushHistory();
		}
	} );

	backButton.addEventListener( 'click', function () {
		var active = canvas.getActiveObject();
		if ( active ) {
			canvas.sendToBack( active );
			pushHistory();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( ( 'Delete' === event.key || 'Backspace' === event.key ) && canvas && canvas.getActiveObject() && document.activeElement === document.body ) {
			deleteButton.click();
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
					return;
				}

				fabric.Image.fromURL( result.url, function ( img ) {
					var maxSize = Math.min( canvas.getWidth(), canvas.getHeight() ) * 0.6;
					if ( img.width > maxSize || img.height > maxSize ) {
						img.scaleToWidth( maxSize );
					}
					var center = centerOf();
					img.set( { left: center.left, top: center.top, originX: 'center', originY: 'center' } );
					addAndSelect( img );
				}, { crossOrigin: 'anonymous' } );
			} )
			.catch( function () {
				showFormError( "Couldn't upload that image. Please try again." );
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

		var unitPrice = 0.3168 + ( 0.0000351 * widthMm * heightMm ) + materialAdjustment( materialId );
		unitPriceEl.textContent = formatMoney( unitPrice );
		totalEl.textContent     = formatMoney( unitPrice * quantity );
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
				mode: 'own_design',
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
			window.localStorage.setItem( DRAFT_STORAGE_KEY, JSON.stringify( {
				widthIn: widthInput.value,
				heightIn: heightInput.value,
				materialId: materialSelect.value,
				quantity: quantityInput.value,
				bgColor: bgColorInput.value,
				brandName: brandInput.value,
				notes: notesInput.value,
				canvasJson: canvas.toJSON()
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

		submitButton.disabled = true;
		submitButton.textContent = 'Exporting your design…';

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
						mode: 'own_design',
						uploads: [ result.id ],
						width_mm: widthMm,
						height_mm: heightMm,
						material_id: materialId,
						quantity: quantity,
						brand_name: brandName,
						instructions: notesInput.value,
						canvas_design: JSON.stringify( canvas.toJSON() )
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

		initCanvas();

		if ( draft.canvasJson ) {
			isRestoringHistory = true;
			canvas.loadFromJSON( draft.canvasJson, function () {
				canvas.renderAll();
				isRestoringHistory = false;
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
	}

	function init() {
		populateFontFamilySelect();
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
