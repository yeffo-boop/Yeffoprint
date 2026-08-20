/**
 * Template "Customization Fields" repeater — admin UI for
 * YeffoPrint_Field_Schema (see includes/schema/class-field-schema.php).
 *
 * Vanilla JS, no build step: state lives in memory as a plain array,
 * every mutation re-renders the list and re-syncs a hidden JSON input
 * that submits with the normal WordPress post form (classic meta box
 * save flow — no AJAX/REST needed for this, since it's edited and
 * saved as part of the same screen). Reordering uses Move Up/Down
 * buttons rather than drag-and-drop so it stays fully keyboard
 * operable.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintFieldSchema === 'undefined' ) {
		return;
	}

	var config = yeffoprintFieldSchema;
	var state = Array.isArray( config.fields ) ? JSON.parse( JSON.stringify( config.fields ) ) : [];

	document.addEventListener( 'DOMContentLoaded', function () {
		var app = document.getElementById( 'yp-field-schema-app' );
		if ( ! app ) {
			return;
		}

		var list = app.querySelector( '.yp-field-schema-list' );
		var input = document.getElementById( 'yp-field-schema-input' );
		var addButton = document.getElementById( 'yp-field-schema-add' );
		var preview = document.getElementById( 'yp-field-position-preview' );

		/* ---------- Insert from preset ----------
		   Copies a reusable Field Preset's fields (label, type, max
		   chars, alignment, font sizing, formatting, tooltip text) into
		   this Template's own list — direct request: recreating the same
		   fields on every Template "is a lot." Position isn't part of
		   what's reused (a preset's saved position is just whatever
		   default it was authored with) — the admin still drags each
		   inserted field into place on *this* Template's own artwork via
		   the existing picker below, same as any other field. Duplicate
		   field ids across inserted/existing fields are fine to leave
		   as-is here: the server (YeffoPrint_Field_Schema::sanitize())
		   already de-duplicates ids on save regardless of source. */
		if ( config.presets && config.presets.length ) {
			var presetWrap = document.createElement( 'p' );
			presetWrap.className = 'yp-field-preset-insert';
			presetWrap.innerHTML =
				'<select id="yp-field-preset-select">' +
					'<option value="">' + escapeHtml( config.i18n.selectPreset || 'Select a preset' ) + '</option>' +
					config.presets.map( function ( preset ) {
						return '<option value="' + preset.id + '">' + escapeHtml( preset.name ) + ' (' + preset.fields.length + ')</option>';
					} ).join( '' ) +
				'</select> ' +
				'<button type="button" class="button" id="yp-field-preset-insert">' + escapeHtml( config.i18n.insertPreset || 'Insert Preset' ) + '</button>';

			addButton.closest( 'p' ).insertAdjacentElement( 'beforebegin', presetWrap );

			document.getElementById( 'yp-field-preset-insert' ).addEventListener( 'click', function () {
				var select = document.getElementById( 'yp-field-preset-select' );
				var preset = config.presets.filter( function ( p ) { return String( p.id ) === select.value; } )[ 0 ];
				if ( ! preset || ! preset.fields.length ) {
					return;
				}

				preset.fields.forEach( function ( field ) {
					state.push( JSON.parse( JSON.stringify( field ) ) );
				} );
				select.value = '';
				render();
			} );
		}

		function escapeHtml( value ) {
			var div = document.createElement( 'div' );
			div.textContent = value == null ? '' : String( value );
			return div.innerHTML;
		}

		function optionsHtml( options, selected ) {
			return Object.keys( options ).map( function ( value ) {
				var isSelected = value === selected ? ' selected' : '';
				return '<option value="' + escapeHtml( value ) + '"' + isSelected + '>' + escapeHtml( options[ value ] ) + '</option>';
			} ).join( '' );
		}

		function fieldRowHtml( field, index, total ) {
			var title = field.label ? field.label : 'Untitled field';

			return (
				'<fieldset class="yp-field-schema-row" role="listitem" data-index="' + index + '">' +
					'<legend>' + escapeHtml( title ) + '</legend>' +
					'<div class="yp-field-schema-grid">' +
						labeled( 'Label', inputHtml( 'text', index, 'label', field.label ) ) +
						labeled( 'Field ID (leave blank to auto-generate)', inputHtml( 'text', index, 'id', field.id ) ) +
						labeled( 'Type', selectHtml( index, 'type', config.types, field.type ) ) +
						labeled( 'Default value', inputHtml( 'text', index, 'default', field.default ) ) +
						labeled( 'Max characters', inputHtml( 'number', index, 'max_chars', field.max_chars, { min: 1 } ) ) +
						labeled( 'Alignment', selectHtml( index, 'alignment', config.alignments, field.alignment ) ) +
						labeled( 'Min font size (px)', inputHtml( 'number', index, 'font_size_min', field.font_size_min, { min: 1 } ) ) +
						labeled( 'Max font size (px)', inputHtml( 'number', index, 'font_size_max', field.font_size_max, { min: 1 } ) ) +
						labeled( 'Text color', inputHtml( 'color', index, 'text_color', field.text_color || '#000000' ) ) +
						labeled( 'Horizontal position (%)', inputHtml( 'number', index, 'position.x', field.position && field.position.x, { min: 0, max: 100 } ) ) +
						labeled( 'Vertical position (%)', inputHtml( 'number', index, 'position.y', field.position && field.position.y, { min: 0, max: 100 } ) ) +
						labeled( 'QR code size (% of stage width) — only used by the QR code type', inputHtml( 'number', index, 'qr_size', field.qr_size == null ? 20 : field.qr_size, { min: 5, max: 60 } ) ) +
						labeled( 'Formatting rule', selectHtml( index, 'formatting_rule', config.formattingRules, field.formatting_rule ) ) +
						labeled( 'Preview behavior', selectHtml( index, 'preview_behavior', config.previewBehaviors, field.preview_behavior ) ) +
						labeled( 'Tooltip / help text (shown to the customer next to this field)', '<textarea data-index="' + index + '" data-key="admin_description" rows="2" class="widefat">' + escapeHtml( field.admin_description ) + '</textarea>' ) +
						'<label class="yp-field-schema-checkbox"><input type="checkbox" data-index="' + index + '" data-key="required"' + ( field.required ? ' checked' : '' ) + ' /> Required</label>' +
						'<label class="yp-field-schema-checkbox"><input type="checkbox" data-index="' + index + '" data-key="show_in_preview"' + ( false !== field.show_in_preview ? ' checked' : '' ) + ' /> Show on live preview</label>' +
					'</div>' +
					'<div class="yp-field-schema-row-actions">' +
						'<button type="button" class="button-link" data-action="move-up" data-index="' + index + '"' + ( index === 0 ? ' disabled' : '' ) + '>' + escapeHtml( config.i18n.moveUp ) + '</button>' +
						'<button type="button" class="button-link" data-action="move-down" data-index="' + index + '"' + ( index === total - 1 ? ' disabled' : '' ) + '>' + escapeHtml( config.i18n.moveDown ) + '</button>' +
						'<button type="button" class="button-link-delete" data-action="remove" data-index="' + index + '">' + escapeHtml( config.i18n.removeField ) + '</button>' +
					'</div>' +
				'</fieldset>'
			);
		}

		function labeled( label, controlHtml ) {
			return '<label class="yp-field-schema-field"><span>' + escapeHtml( label ) + '</span>' + controlHtml + '</label>';
		}

		function inputHtml( type, index, key, value, extra ) {
			var attrs = extra ? Object.keys( extra ).map( function ( k ) {
				return k + '="' + extra[ k ] + '"';
			} ).join( ' ' ) : '';

			return '<input type="' + type + '" data-index="' + index + '" data-key="' + key + '" value="' + escapeHtml( value == null ? '' : value ) + '" class="widefat" ' + attrs + ' />';
		}

		function selectHtml( index, key, options, selected ) {
			return '<select data-index="' + index + '" data-key="' + key + '" class="widefat">' + optionsHtml( options, selected ) + '</select>';
		}

		function render() {
			if ( ! state.length ) {
				list.innerHTML = '<p class="yp-field-schema-empty">' + escapeHtml( config.i18n.empty ) + '</p>';
			} else {
				list.innerHTML = state.map( function ( field, index ) {
					return fieldRowHtml( field, index, state.length );
				} ).join( '' );
			}

			renderPreview();
			syncHiddenInput();
		}

		/* ---------- Drag-to-position preview ---------- */
		// Shows the Template's own artwork with a draggable marker per
		// field, so "where will this land" is answered by dragging a
		// label onto the actual image instead of guessing raw x/y
		// percentages — those number inputs stay too, for exact
		// adjustment, and stay in sync with the drag in both directions.

		var draggingIndex = null;

		function renderPreview() {
			if ( ! preview ) {
				return;
			}

			if ( ! config.previewImageUrl ) {
				preview.innerHTML = '<p class="description">' + escapeHtml( config.i18n.noPreview ) + '</p>';
				return;
			}

			if ( ! state.length ) {
				preview.innerHTML = '';
				return;
			}

			var markersHtml = state.map( function ( field, index ) {
				var x = field.position && ! isNaN( parseFloat( field.position.x ) ) ? parseFloat( field.position.x ) : 50;
				var y = field.position && ! isNaN( parseFloat( field.position.y ) ) ? parseFloat( field.position.y ) : 50;
				return '<button type="button" class="yp-field-position-marker" data-index="' + index + '" style="left:' + x + '%;top:' + y + '%;" title="Drag to reposition, or use arrow keys to nudge (hold Shift for bigger steps)">' + escapeHtml( field.label || ( 'Field ' + ( index + 1 ) ) ) + '</button>';
			} ).join( '' );

			preview.innerHTML =
				'<p class="description">' + escapeHtml( config.i18n.dragHint ) + '</p>' +
				'<div class="yp-field-position-stage" id="yp-field-position-stage">' +
					'<img src="' + escapeHtml( config.previewImageUrl ) + '" alt="" />' +
					markersHtml +
				'</div>';
		}

		function updateMarkerLabel( index ) {
			var marker = preview.querySelector( '.yp-field-position-marker[data-index="' + index + '"]' );
			if ( marker ) {
				marker.textContent = state[ index ].label || ( 'Field ' + ( index + 1 ) );
			}
		}

		function updateMarkerFromState( index ) {
			var marker = preview.querySelector( '.yp-field-position-marker[data-index="' + index + '"]' );
			if ( ! marker || ! state[ index ].position ) {
				return;
			}
			var x = parseFloat( state[ index ].position.x );
			var y = parseFloat( state[ index ].position.y );
			marker.style.left = ( isNaN( x ) ? 50 : Math.min( 100, Math.max( 0, x ) ) ) + '%';
			marker.style.top = ( isNaN( y ) ? 50 : Math.min( 100, Math.max( 0, y ) ) ) + '%';
		}

		function pointFromEvent( event ) {
			if ( event.touches && event.touches.length ) {
				return { x: event.touches[ 0 ].clientX, y: event.touches[ 0 ].clientY };
			}
			return { x: event.clientX, y: event.clientY };
		}

		function applyMarkerPosition( index, x, y ) {
			x = Math.round( Math.min( 100, Math.max( 0, x ) ) * 10 ) / 10;
			y = Math.round( Math.min( 100, Math.max( 0, y ) ) * 10 ) / 10;

			setValue( index, 'position.x', x );
			setValue( index, 'position.y', y );
			syncHiddenInput();
			updateMarkerFromState( index );

			var xInput = list.querySelector( '[data-index="' + index + '"][data-key="position.x"]' );
			var yInput = list.querySelector( '[data-index="' + index + '"][data-key="position.y"]' );
			if ( xInput ) {
				xInput.value = x;
			}
			if ( yInput ) {
				yInput.value = y;
			}
		}

		function setMarkerPosition( index, clientX, clientY ) {
			var stage = document.getElementById( 'yp-field-position-stage' );
			var rect = stage && stage.getBoundingClientRect();
			if ( ! rect || ! rect.width || ! rect.height ) {
				return;
			}

			applyMarkerPosition(
				index,
				( ( clientX - rect.left ) / rect.width ) * 100,
				( ( clientY - rect.top ) / rect.height ) * 100
			);
		}

		// Keyboard nudging — direct follow-up to a report that fine mouse-
		// dragging can't reliably land a tight margin near an edge (e.g.
		// 2-3% in from the side). Focus a marker (it's a real <button>,
		// already tabbable) and arrow keys move it in small, precise
		// steps instead of fighting drag precision — 0.5% normally, 2%
		// with Shift held for covering more distance quickly first.
		var NUDGE_STEP = 0.5;
		var NUDGE_STEP_LARGE = 2;

		preview.addEventListener( 'keydown', function ( event ) {
			var marker = event.target.closest( '.yp-field-position-marker' );
			if ( ! marker ) {
				return;
			}

			var deltas = {
				ArrowLeft:  [ -1, 0 ],
				ArrowRight: [ 1, 0 ],
				ArrowUp:    [ 0, -1 ],
				ArrowDown:  [ 0, 1 ]
			};
			var delta = deltas[ event.key ];
			if ( ! delta ) {
				return;
			}

			event.preventDefault();

			var index = parseInt( marker.getAttribute( 'data-index' ), 10 );
			var step = event.shiftKey ? NUDGE_STEP_LARGE : NUDGE_STEP;
			var position = state[ index ].position || { x: 50, y: 50 };
			var currentX = parseFloat( position.x );
			var currentY = parseFloat( position.y );

			applyMarkerPosition(
				index,
				( isNaN( currentX ) ? 50 : currentX ) + delta[ 0 ] * step,
				( isNaN( currentY ) ? 50 : currentY ) + delta[ 1 ] * step
			);
		} );

		preview.addEventListener( 'mousedown', startDrag );
		preview.addEventListener( 'touchstart', startDrag, { passive: false } );

		function startDrag( event ) {
			var marker = event.target.closest( '.yp-field-position-marker' );
			if ( ! marker ) {
				return;
			}
			draggingIndex = parseInt( marker.getAttribute( 'data-index' ), 10 );
			marker.classList.add( 'is-dragging' );
			event.preventDefault();
		}

		function onDragMove( event ) {
			if ( null === draggingIndex ) {
				return;
			}
			var point = pointFromEvent( event );
			setMarkerPosition( draggingIndex, point.x, point.y );
			event.preventDefault();
		}

		function endDrag() {
			if ( null === draggingIndex ) {
				return;
			}
			var marker = preview.querySelector( '.yp-field-position-marker.is-dragging' );
			if ( marker ) {
				marker.classList.remove( 'is-dragging' );
			}
			draggingIndex = null;
		}

		document.addEventListener( 'mousemove', onDragMove );
		document.addEventListener( 'touchmove', onDragMove, { passive: false } );
		document.addEventListener( 'mouseup', endDrag );
		document.addEventListener( 'touchend', endDrag );

		function syncHiddenInput() {
			input.value = JSON.stringify( state );
		}

		function setValue( index, key, value ) {
			if ( key.indexOf( '.' ) !== -1 ) {
				var parts = key.split( '.' );
				if ( ! state[ index ][ parts[ 0 ] ] ) {
					state[ index ][ parts[ 0 ] ] = {};
				}
				state[ index ][ parts[ 0 ] ][ parts[ 1 ] ] = value;
			} else {
				state[ index ][ key ] = value;
			}
		}

		list.addEventListener( 'input', function ( event ) {
			var target = event.target;
			var index = target.getAttribute( 'data-index' );
			var key = target.getAttribute( 'data-key' );
			if ( index === null || ! key ) {
				return;
			}

			var value = 'checkbox' === target.type ? target.checked : target.value;
			setValue( parseInt( index, 10 ), key, value );
			syncHiddenInput();

			if ( 'label' === key ) {
				var fieldset = target.closest( '.yp-field-schema-row' );
				var legend = fieldset && fieldset.querySelector( 'legend' );
				if ( legend ) {
					legend.textContent = value || 'Untitled field';
				}
				updateMarkerLabel( parseInt( index, 10 ) );
			}

			if ( 'position.x' === key || 'position.y' === key ) {
				updateMarkerFromState( parseInt( index, 10 ) );
			}
		} );

		list.addEventListener( 'change', function ( event ) {
			var target = event.target;
			var index = target.getAttribute( 'data-index' );
			var key = target.getAttribute( 'data-key' );
			if ( index === null || ! key || 'SELECT' !== target.tagName ) {
				return;
			}

			setValue( parseInt( index, 10 ), key, target.value );
			syncHiddenInput();
		} );

		list.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( 'button[data-action]' );
			if ( ! button ) {
				return;
			}

			var action = button.getAttribute( 'data-action' );
			var index = parseInt( button.getAttribute( 'data-index' ), 10 );

			if ( 'remove' === action ) {
				state.splice( index, 1 );
			} else if ( 'move-up' === action && index > 0 ) {
				var prev = state[ index - 1 ];
				state[ index - 1 ] = state[ index ];
				state[ index ] = prev;
			} else if ( 'move-down' === action && index < state.length - 1 ) {
				var next = state[ index + 1 ];
				state[ index + 1 ] = state[ index ];
				state[ index ] = next;
			}

			render();
		} );

		addButton.addEventListener( 'click', function () {
			state.push( {
				id: '',
				label: '',
				type: 'text',
				default: '',
				required: false,
				max_chars: 40,
				position: { x: 50, y: 50 },
				alignment: 'center',
				font_size_min: 10,
				font_size_max: 24,
				text_color: '#000000',
				formatting_rule: 'none',
				preview_behavior: 'scale-to-fit',
				admin_description: '',
				qr_size: 20,
				show_in_preview: true
			} );
			render();
		} );

		render();
	} );
} )();
