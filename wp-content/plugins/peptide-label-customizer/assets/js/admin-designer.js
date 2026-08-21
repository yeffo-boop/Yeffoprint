jQuery( function ( $ ) {

	var frame;

	function toggleDesigner() {
		if ( $( '#plc_enabled' ).is( ':checked' ) ) {
			$( '#plc-designer-wrap' ).show();
		} else {
			$( '#plc-designer-wrap' ).hide();
		}
	}
	$( '#plc_enabled' ).on( 'change', toggleDesigner );
	toggleDesigner();

	// --- Media uploader for template image ---
	$( '#plc-upload-image' ).on( 'click', function ( e ) {
		e.preventDefault();
		if ( frame ) {
			frame.open();
			return;
		}
		frame = wp.media( {
			title: 'Choose Label Template Image',
			multiple: false,
			library: { type: 'image' }
		} );
		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			$( '#plc-image-id' ).val( attachment.id );
			$( '#plc-template-image' ).attr( 'src', attachment.url ).show();
			$( '#plc-canvas-area' ).show();
			$( '#plc-zone-table' ).show();
			$( '#plc-remove-image' ).show();
		} );
		frame.open();
	} );

	$( '#plc-remove-image' ).on( 'click', function ( e ) {
		e.preventDefault();
		$( '#plc-image-id' ).val( '' );
		$( '#plc-template-image' ).attr( 'src', '' );
		$( '#plc-canvas-area' ).hide();
		$( '#plc-zone-table' ).hide();
		$( this ).hide();
	} );

	// --- Zone marker enable/disable + positioning ---
	function markerFor( zoneKey ) {
		return $( '.plc-zone-marker[data-zone="' + zoneKey + '"]' );
	}

	function refreshMarkerVisibility() {
		$( '.plc-zone-enabled' ).each( function () {
			var $cb = $( this );
			var zoneKey = $cb.closest( 'tr' ).data( 'zone-row' );
			var $marker = markerFor( zoneKey );
			if ( $cb.is( ':checked' ) ) {
				$marker.show();
			} else {
				$marker.hide();
			}
		} );
	}

	function positionMarkerFromInputs( zoneKey ) {
		var $row = $( 'tr[data-zone-row="' + zoneKey + '"]' );
		var x = parseFloat( $row.find( '.plc-zone-x' ).val() ) || 0;
		var y = parseFloat( $row.find( '.plc-zone-y' ).val() ) || 0;
		markerFor( zoneKey ).css( { left: x + '%', top: y + '%' } );
	}

	function initMarkers() {
		$( '.plc-zone-marker' ).each( function () {
			var zoneKey = $( this ).data( 'zone' );
			positionMarkerFromInputs( zoneKey );
		} );
		refreshMarkerVisibility();
	}

	$( '.plc-zone-enabled' ).on( 'change', refreshMarkerVisibility );

	// Draggable markers, constrained to the image stage, storing position as %.
	function makeDraggable() {
		var $stage = $( '#plc-image-stage' );
		$( '.plc-zone-marker' ).draggable( {
			containment: $stage,
			start: function () {
				$( this ).addClass( 'plc-dragging' );
			},
			stop: function () {
				$( this ).removeClass( 'plc-dragging' );
				var zoneKey = $( this ).data( 'zone' );
				var stageW = $stage.width();
				var stageH = $stage.height();
				var left = parseFloat( $( this ).css( 'left' ) );
				var top = parseFloat( $( this ).css( 'top' ) );
				var xPct = stageW ? ( left / stageW ) * 100 : 0;
				var yPct = stageH ? ( top / stageH ) * 100 : 0;
				var $row = $( 'tr[data-zone-row="' + zoneKey + '"]' );
				$row.find( '.plc-zone-x' ).val( xPct.toFixed( 2 ) );
				$row.find( '.plc-zone-y' ).val( yPct.toFixed( 2 ) );
			}
		} );
	}

	$( '#plc-template-image' ).on( 'load', function () {
		initMarkers();
		makeDraggable();
	} );
	// In case the image is already loaded (cached) by the time this runs.
	if ( $( '#plc-template-image' ).length && $( '#plc-template-image' ).attr( 'src' ) ) {
		initMarkers();
		makeDraggable();
	}

	// --- Pricing option rows (size / media) ---
	$( '.plc-add-row' ).on( 'click', function () {
		var group = $( this ).data( 'group' );
		var $table = $( '.plc-option-table[data-group="' + group + '"] tbody' );
		var idx = $table.find( 'tr' ).length;
		var fieldName = 'plc_' + group;
		var row = '<tr>' +
			'<td><input type="text" class="regular-text" name="' + fieldName + '[' + idx + '][label]" value="" /></td>' +
			'<td><input type="number" step="0.01" min="0" name="' + fieldName + '[' + idx + '][price]" value="0" /></td>' +
			'<td><button type="button" class="button plc-remove-row">&times;</button></td>' +
			'</tr>';
		$table.append( row );
	} );

	$( document ).on( 'click', '.plc-remove-row', function () {
		$( this ).closest( 'tr' ).remove();
	} );

} );
