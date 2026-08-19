jQuery( function ( $ ) {

	if ( typeof PLC_DATA === 'undefined' ) {
		return;
	}

	var canvas = document.getElementById( 'plc-canvas' );
	if ( ! canvas ) {
		return;
	}
	var ctx = canvas.getContext( '2d' );
	var img = new Image();
	img.crossOrigin = 'anonymous';
	var imageLoaded = false;

	var layout = { scale: 1, offsetX: 0, offsetY: 0, drawW: 0, drawH: 0 };

	function computeLayout() {
		var cw = canvas.width;
		var ch = canvas.height;
		var iw = PLC_DATA.imageWidth;
		var ih = PLC_DATA.imageHeight;
		var scale = Math.min( cw / iw, ch / ih );
		var drawW = iw * scale;
		var drawH = ih * scale;
		layout = {
			scale: scale,
			offsetX: ( cw - drawW ) / 2,
			offsetY: ( ch - drawH ) / 2,
			drawW: drawW,
			drawH: drawH
		};
	}

	function fieldValue( id, zoneKey ) {
		var $el = $( '#' + id );
		var val = $el.length ? $.trim( $el.val() ) : '';
		if ( ! val && PLC_DATA.zones[ zoneKey ] ) {
			val = PLC_DATA.zones[ zoneKey ].default_value || '';
		}
		return val;
	}

	function drawZoneText( zoneKey, text ) {
		var zone = PLC_DATA.zones[ zoneKey ];
		if ( ! zone || ! zone.enabled || ! text ) {
			return;
		}
		var x = layout.offsetX + ( zone.x / 100 ) * layout.drawW;
		var y = layout.offsetY + ( zone.y / 100 ) * layout.drawH;
		var fontSize = Math.round( zone.font_size * layout.scale );

		ctx.font = ( zone.weight === 'bold' ? 'bold ' : '' ) + fontSize + 'px Arial, sans-serif';
		ctx.fillStyle = zone.color || '#ffffff';
		ctx.textAlign = zone.align || 'left';
		ctx.textBaseline = 'top';
		ctx.fillText( text, x, y );
	}

	function render() {
		ctx.clearRect( 0, 0, canvas.width, canvas.height );

		if ( imageLoaded ) {
			computeLayout();
			ctx.drawImage( img, layout.offsetX, layout.offsetY, layout.drawW, layout.drawH );
		}

		drawZoneText( 'compound_name', fieldValue( 'plc_compound_name', 'compound_name' ) );
		drawZoneText( 'strength', fieldValue( 'plc_strength', 'strength' ) );
		drawZoneText( 'batch_number', fieldValue( 'plc_batch_number', 'batch_number' ) );
		drawZoneText( 'expiration_date', fieldValue( 'plc_expiration_date', 'expiration_date' ) );
	}

	img.onload = function () {
		imageLoaded = true;
		render();
	};
	img.src = PLC_DATA.imageUrl;

	$( document ).on( 'input change', '#plc-customizer input, #plc-customizer select, #plc-customizer textarea', function () {
		render();
		updatePriceDisplay();
	} );

	// --- Price add-on display (visual only; server recalculates authoritatively) ---
	function updatePriceDisplay() {
		var base = parseFloat( PLC_DATA.basePrice ) || 0;
		var addon = 0;

		var $size = $( '#plc_size option:selected' );
		if ( $size.length ) {
			addon += parseFloat( $size.data( 'price' ) ) || 0;
		}
		var $media = $( '#plc_media option:selected' );
		if ( $media.length ) {
			addon += parseFloat( $media.data( 'price' ) ) || 0;
		}

		var $priceEl = $( '.summary .price' ).first();
		if ( $priceEl.length && ( $size.length || $media.length ) ) {
			// Non-destructive note near the price rather than rewriting WooCommerce's markup.
			var $note = $( '#plc-price-addon-note' );
			if ( ! $note.length ) {
				$note = $( '<div id="plc-price-addon-note" class="plc-price-addon-note"></div>' );
				$priceEl.after( $note );
			}
			if ( addon > 0 ) {
				$note.text( '+ ' + PLC_DATA.currencySymbol + addon.toFixed( 2 ) + ' for selected options' );
			} else {
				$note.text( '' );
			}
		}
	}

	// --- Validate + package data before add-to-cart submits ---
	$( 'form.cart' ).on( 'submit', function ( e ) {
		var $form = $( this );
		var $error = $( '#plc-error-message' );
		$error.hide().text( '' );

		var errors = [];

		if ( PLC_DATA.zones.compound_name && PLC_DATA.zones.compound_name.enabled && ! $.trim( $( '#plc_compound_name' ).val() ) ) {
			errors.push( 'Compound name is required.' );
		}
		if ( PLC_DATA.zones.strength && PLC_DATA.zones.strength.enabled && ! $.trim( $( '#plc_strength' ).val() ) ) {
			errors.push( 'Strength / dosage is required.' );
		}
		if ( $( '#plc_size' ).length && ! $( '#plc_size' ).val() ) {
			errors.push( 'Please select a label size.' );
		}
		if ( $( '#plc_media' ).length && ! $( '#plc_media' ).val() ) {
			errors.push( 'Please select a media / vinyl type.' );
		}
		if ( $( '#plc_confirm' ).length && ! $( '#plc_confirm' ).is( ':checked' ) ) {
			errors.push( 'Please confirm your label details are accurate.' );
		}

		if ( errors.length ) {
			e.preventDefault();
			$error.html( errors.join( '<br>' ) ).show();
			$( 'html, body' ).animate( { scrollTop: $( '#plc-customizer' ).offset().top - 100 }, 300 );
			return false;
		}

		var data = {
			compound_name: fieldValue( 'plc_compound_name', 'compound_name' ),
			strength: fieldValue( 'plc_strength', 'strength' ),
			batch_number: fieldValue( 'plc_batch_number', 'batch_number' ),
			expiration_date: fieldValue( 'plc_expiration_date', 'expiration_date' ),
			size: $( '#plc_size' ).val() || '',
			media: $( '#plc_media' ).val() || '',
			color: $( '#plc_color' ).length ? $( '#plc_color' ).val() : '',
			design_notes: $.trim( $( '#plc_design_notes' ).val() || '' )
		};

		$( '#plc_data_hidden' ).val( JSON.stringify( data ) );

		try {
			$( '#plc_preview_image_hidden' ).val( canvas.toDataURL( 'image/png' ) );
		} catch ( err ) {
			// Canvas may be tainted if the template image isn't served with CORS headers.
			$( '#plc_preview_image_hidden' ).val( '' );
		}
	} );

	render();
	updatePriceDisplay();
} );
