/**
 * Shared "Customization Fields" repeater widget (docs/ARCHITECTURE.md,
 * Phase 5) — the drag-to-position field editor, factored out of the
 * classic assets/admin/field-schema.js so both views/templates.js and
 * views/field-presets.js can reuse the exact same UI/behavior instead
 * of each screen reimplementing it.
 *
 * Unlike the classic version (state synced to a hidden form input that
 * submits with a classic post-edit form), this one holds state purely
 * in memory and exposes it via getFields() — the caller reads that at
 * REST-save time. previewImageUrl is optional: a Field Preset has no
 * artwork (class-field-preset-editor.php's own reasoning), so passing
 * '' falls back to the same "no preview yet" empty state a Template
 * without a featured image yet would also show.
 *
 * YP.createFieldSchemaEditor(config) returns { getFields, setPreviewImage }.
 * config: { container, fields, types, alignments, formattingRules,
 *           previewBehaviors, qrMinMaxChars, qrMaxChars, previewImageUrl,
 *           presets, i18n }
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	YP.createFieldSchemaEditor = function ( config ) {
		var state = Array.isArray( config.fields ) ? JSON.parse( JSON.stringify( config.fields ) ) : [];
		var previewImageUrl = config.previewImageUrl || '';
		var i18n = config.i18n || {};

		config.container.innerHTML =
			'<div data-yp-fs-preview></div>' +
			'<div class="yp-field-schema-list" role="list" data-yp-fs-list></div>' +
			'<p class="yp-field-preset-insert" data-yp-fs-preset-row' + ( config.presets && config.presets.length ? '' : ' hidden' ) + '>' +
				'<select data-yp-fs-preset-select>' +
					'<option value="">' + YP.escapeHtml( i18n.selectPreset || 'Select a preset' ) + '</option>' +
					( config.presets || [] ).map( function ( preset ) {
						return '<option value="' + preset.id + '">' + YP.escapeHtml( preset.name ) + ' (' + preset.fields.length + ')</option>';
					} ).join( '' ) +
				'</select> ' +
				'<button type="button" class="wp-block-button__link is-style-outline" data-yp-fs-preset-insert>' + YP.escapeHtml( i18n.insertPreset || 'Insert Preset' ) + '</button>' +
			'</p>' +
			'<p><button type="button" class="wp-block-button__link is-style-outline" data-yp-fs-add>' + YP.escapeHtml( i18n.addField || 'Add Field' ) + '</button></p>';

		var preview = config.container.querySelector( '[data-yp-fs-preview]' );
		var list = config.container.querySelector( '[data-yp-fs-list]' );

		if ( config.presets && config.presets.length ) {
			config.container.querySelector( '[data-yp-fs-preset-insert]' ).addEventListener( 'click', function () {
				var select = config.container.querySelector( '[data-yp-fs-preset-select]' );
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

		function optionsHtml( options, selected ) {
			return Object.keys( options ).map( function ( value ) {
				return '<option value="' + YP.escapeAttr( value ) + '"' + ( value === selected ? ' selected' : '' ) + '>' + YP.escapeHtml( options[ value ] ) + '</option>';
			} ).join( '' );
		}

		function inputHtml( type, index, key, value, extra ) {
			var attrs = extra ? Object.keys( extra ).map( function ( k ) { return k + '="' + extra[ k ] + '"'; } ).join( ' ' ) : '';
			return '<input type="' + type + '" data-index="' + index + '" data-key="' + key + '" value="' + YP.escapeAttr( value == null ? '' : value ) + '" ' + attrs + ' />';
		}

		function selectHtml( index, key, options, selected ) {
			return '<select data-index="' + index + '" data-key="' + key + '">' + optionsHtml( options, selected ) + '</select>';
		}

		function labeled( label, controlHtml ) {
			return '<label class="yp-field-schema-field"><span>' + YP.escapeHtml( label ) + '</span>' + controlHtml + '</label>';
		}

		function fieldRowHtml( field, index, total ) {
			return (
				'<fieldset class="yp-field-schema-row" role="listitem" data-index="' + index + '">' +
					'<legend>' + YP.escapeHtml( field.label || 'Untitled field' ) + '</legend>' +
					'<div class="yp-field-schema-grid">' +
						labeled( 'Label', inputHtml( 'text', index, 'label', field.label ) ) +
						labeled( 'Field ID (leave blank to auto-generate)', inputHtml( 'text', index, 'id', field.id ) ) +
						labeled( 'Type', selectHtml( index, 'type', config.types, field.type ) ) +
						labeled(
							'Default value',
							'corner_style' === field.type
								? selectHtml( index, 'default', config.cornerStyleOptions, field.default )
								: inputHtml( 'text', index, 'default', field.default )
						) +
						labeled(
							'qr_code' === field.type ? 'Max characters (a URL — up to ' + config.qrMaxChars + ')' : 'Max characters',
							inputHtml( 'number', index, 'max_chars', field.max_chars, 'qr_code' === field.type ? { min: 1, max: config.qrMaxChars } : { min: 1 } )
						) +
						labeled( 'Alignment', selectHtml( index, 'alignment', config.alignments, field.alignment ) ) +
						labeled( 'Min font size (px)', inputHtml( 'number', index, 'font_size_min', field.font_size_min, { min: 1 } ) ) +
						labeled( 'Max font size (px)', inputHtml( 'number', index, 'font_size_max', field.font_size_max, { min: 1 } ) ) +
						labeled( 'Text color', inputHtml( 'color', index, 'text_color', field.text_color || '#000000' ) ) +
						labeled( 'Horizontal position (%)', inputHtml( 'number', index, 'position.x', field.position && field.position.x, { min: 0, max: 100 } ) ) +
						labeled( 'Vertical position (%)', inputHtml( 'number', index, 'position.y', field.position && field.position.y, { min: 0, max: 100 } ) ) +
						labeled( 'QR code size (% of stage width) — only used by the QR code type', inputHtml( 'number', index, 'qr_size', field.qr_size == null ? 20 : field.qr_size, { min: 5, max: 60 } ) ) +
						labeled( 'Formatting rule', selectHtml( index, 'formatting_rule', config.formattingRules, field.formatting_rule ) ) +
						labeled( 'Preview behavior', selectHtml( index, 'preview_behavior', config.previewBehaviors, field.preview_behavior ) ) +
						labeled( 'Tooltip / help text (shown to the customer next to this field)', '<textarea data-index="' + index + '" data-key="admin_description" rows="2">' + YP.escapeHtml( field.admin_description ) + '</textarea>' ) +
						'<label class="yp-field-schema-checkbox"><input type="checkbox" data-index="' + index + '" data-key="required"' + ( field.required ? ' checked' : '' ) + ' /> Required</label>' +
						'<label class="yp-field-schema-checkbox"><input type="checkbox" data-index="' + index + '" data-key="show_in_preview"' + ( false !== field.show_in_preview ? ' checked' : '' ) + ' /> Show on live preview</label>' +
					'</div>' +
					'<div class="yp-field-schema-row-actions">' +
						'<button type="button" class="yp-row-action" data-action="move-up" data-index="' + index + '"' + ( 0 === index ? ' disabled' : '' ) + '>' + YP.escapeHtml( i18n.moveUp || 'Move up' ) + '</button>' +
						'<button type="button" class="yp-row-action" data-action="move-down" data-index="' + index + '"' + ( index === total - 1 ? ' disabled' : '' ) + '>' + YP.escapeHtml( i18n.moveDown || 'Move down' ) + '</button>' +
						'<button type="button" class="yp-row-action" data-action="remove" data-index="' + index + '">' + YP.escapeHtml( i18n.removeField || 'Remove' ) + '</button>' +
					'</div>' +
				'</fieldset>'
			);
		}

		function render() {
			list.innerHTML = state.length
				? state.map( function ( field, index ) { return fieldRowHtml( field, index, state.length ); } ).join( '' )
				: '<p class="yp-field-schema-empty">' + YP.escapeHtml( i18n.empty || 'No fields yet. Add one below.' ) + '</p>';

			renderPreview();
		}

		/* ---------- Drag-to-position preview ---------- */

		var draggingIndex = null;

		function renderPreview() {
			if ( ! previewImageUrl ) {
				preview.innerHTML = '<p class="yp-field__hint">' + YP.escapeHtml( i18n.noPreview || 'No artwork to preview against.' ) + '</p>';
				return;
			}

			if ( ! state.length ) {
				preview.innerHTML = '';
				return;
			}

			var markersHtml = state.map( function ( field, index ) {
				var x = field.position && ! isNaN( parseFloat( field.position.x ) ) ? parseFloat( field.position.x ) : 50;
				var y = field.position && ! isNaN( parseFloat( field.position.y ) ) ? parseFloat( field.position.y ) : 50;
				return '<button type="button" class="yp-field-position-marker" data-index="' + index + '" style="left:' + x + '%;top:' + y + '%;" title="Drag to reposition, or use arrow keys to nudge (hold Shift for bigger steps)">' + YP.escapeHtml( field.label || ( 'Field ' + ( index + 1 ) ) ) + '</button>';
			} ).join( '' );

			preview.innerHTML =
				'<p class="yp-field__hint">' + YP.escapeHtml( i18n.dragHint || 'Drag a label to reposition it, or set exact percentages below.' ) + '</p>' +
				'<div class="yp-field-position-stage" data-yp-fs-stage">' +
					'<img src="' + YP.escapeAttr( previewImageUrl ) + '" alt="" />' +
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
			updateMarkerFromState( index );

			var xInput = list.querySelector( '[data-index="' + index + '"][data-key="position.x"]' );
			var yInput = list.querySelector( '[data-index="' + index + '"][data-key="position.y"]' );
			if ( xInput ) { xInput.value = x; }
			if ( yInput ) { yInput.value = y; }
		}

		function setMarkerPosition( index, clientX, clientY ) {
			var stage = config.container.querySelector( '[data-yp-fs-stage]' );
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

		var NUDGE_STEP = 0.5;
		var NUDGE_STEP_LARGE = 2;

		preview.addEventListener( 'keydown', function ( event ) {
			var marker = event.target.closest( '.yp-field-position-marker' );
			if ( ! marker ) {
				return;
			}
			var deltas = { ArrowLeft: [ -1, 0 ], ArrowRight: [ 1, 0 ], ArrowUp: [ 0, -1 ], ArrowDown: [ 0, 1 ] };
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

			applyMarkerPosition( index, ( isNaN( currentX ) ? 50 : currentX ) + delta[ 0 ] * step, ( isNaN( currentY ) ? 50 : currentY ) + delta[ 1 ] * step );
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

			setValue( parseInt( index, 10 ), key, 'checkbox' === target.type ? target.checked : target.value );

			if ( 'label' === key ) {
				var fieldset = target.closest( '.yp-field-schema-row' );
				var legend = fieldset && fieldset.querySelector( 'legend' );
				if ( legend ) {
					legend.textContent = target.value || 'Untitled field';
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

			if ( 'type' === key && 'qr_code' === target.value ) {
				var fieldIndex = parseInt( index, 10 );
				var current = parseInt( state[ fieldIndex ].max_chars, 10 ) || 0;
				if ( current < config.qrMinMaxChars ) {
					state[ fieldIndex ].max_chars = config.qrMinMaxChars;
					render();
				}
			}
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
				var prev = state[ index - 1 ]; state[ index - 1 ] = state[ index ]; state[ index ] = prev;
			} else if ( 'move-down' === action && index < state.length - 1 ) {
				var next = state[ index + 1 ]; state[ index + 1 ] = state[ index ]; state[ index ] = next;
			}

			render();
		} );

		config.container.querySelector( '[data-yp-fs-add]' ).addEventListener( 'click', function () {
			state.push( {
				id: '', label: '', type: 'text', default: '', required: false, max_chars: 40,
				position: { x: 50, y: 50 }, alignment: 'center', font_size_min: 10, font_size_max: 24,
				text_color: '#000000', formatting_rule: 'none', preview_behavior: 'scale-to-fit',
				admin_description: '', qr_size: 20, show_in_preview: true
			} );
			render();
		} );

		render();

		return {
			getFields: function () { return JSON.parse( JSON.stringify( state ) ); },
			setPreviewImage: function ( url ) { previewImageUrl = url || ''; renderPreview(); }
		};
	};
} )();
