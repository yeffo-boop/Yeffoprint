/**
 * Draws an SVG label template (YeffoPrint_Label_Svg) live: customer
 * colors set CSS variables, customer text fills the {slots} and shrinks
 * to its fit box. Shared by the product page (configurator.js) and the
 * admin app's Template drawer.
 *
 *   var label = YPLabelSvg.mount( containerEl, svgPayload, {
 *     colors: { bg: '#0D1B4C', 'stripe-1': '#EC008C' },
 *     values: { 'compound-name': 'BPC-157' },
 *     placeholders: { 'compound-name': 'Compound Name' },
 *     font: 'Inter',            // Template's preview font, used as a fallback
 *     qrUrl: function ( text ) { return '...png'; }
 *   } );
 *   label.setColors( {...} ); label.setValues( {...} ); label.highlight( 'stripe-1' );
 *
 * The file itself was cleaned on upload (no scripts, no outside URLs);
 * every mount gets its own id suffix so several copies of one file
 * (colorway tiles) never share gradients or clip paths.
 */
( function () {
	'use strict';

	var counter = 0;

	/** Gives every id in the file (and every reference to it) a per-copy suffix. */
	function uniqueIds( markup ) {
		var suffix = '-yp' + ( ++counter );
		var ids = [];
		markup.replace( /\sid="([^"]+)"/g, function ( m, id ) {
			ids.push( id );
			return m;
		} );
		if ( ! ids.length ) {
			return markup;
		}
		var escaped = ids.map( function ( id ) {
			return id.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
		} );
		var refRe = new RegExp( '(url\\(\\s*[\'"]?#|href="#)(' + escaped.join( '|' ) + ')(?=[\'")\\s]|")', 'g' );
		return markup
			.replace( /\sid="([^"]+)"/g, function ( m, id ) { return ' id="' + id + suffix + '"'; } )
			.replace( refRe, function ( m, prefix, id ) { return prefix + id + suffix; } );
	}

	function fillSlot( template, slotFields, values, placeholders ) {
		var anyValue = false;
		var text = template.replace( /\{([a-z0-9_-]+)\}/g, function ( m, slot ) {
			var fieldId = slotFields[ slot ];
			var value = fieldId && values[ fieldId ] ? String( values[ fieldId ] ).trim() : '';
			if ( value ) {
				anyValue = true;
				return value;
			}
			return fieldId && placeholders[ fieldId ] ? placeholders[ fieldId ] : '';
		} );
		return { text: text.replace( /\s{2,}/g, ' ' ).trim(), anyValue: anyValue };
	}

	function mount( container, payload, options ) {
		options = options || {};
		container.innerHTML = uniqueIds( payload.markup );
		var svg = container.querySelector( 'svg' );
		if ( ! svg ) {
			return null;
		}
		svg.setAttribute( 'role', 'img' );
		svg.setAttribute( 'preserveAspectRatio', 'xMidYMid meet' );
		svg.style.display = 'block';
		svg.style.width = '100%';
		svg.style.height = '100%';

		var slotFields = payload.slot_fields || {};
		var state = { colors: {}, values: {}, placeholders: options.placeholders || {} };

		var slotEls = Array.prototype.slice.call( svg.querySelectorAll( '[data-yp-slot]' ) ).map( function ( el ) {
			var family = window.getComputedStyle( el ).fontFamily || '';
			if ( options.font ) {
				el.style.fontFamily = ( family ? family + ', ' : '' ) + '"' + options.font + '", sans-serif';
			}
			// The design's own size, read before anything is shrunk.
			return { el: el, template: el.getAttribute( 'data-yp-slot' ), baseSize: parseFloat( window.getComputedStyle( el ).fontSize ) || 0, box: null };
		} );

		// A fit box belongs to the slot whose template uses that {name}.
		Array.prototype.forEach.call( svg.querySelectorAll( '[data-yp-box]' ), function ( box ) {
			var name = box.getAttribute( 'data-yp-box' );
			slotEls.forEach( function ( slot ) {
				if ( ! slot.box && slot.template.indexOf( '{' + name + '}' ) !== -1 ) {
					slot.box = box;
				}
			} );
		} );

		var qrRect = svg.querySelector( '[data-yp-qr]' );
		var qrImage = null;
		if ( qrRect ) {
			qrImage = document.createElementNS( 'http://www.w3.org/2000/svg', 'image' );
			[ 'x', 'y', 'width', 'height', 'transform' ].forEach( function ( attr ) {
				if ( qrRect.hasAttribute( attr ) ) {
					qrImage.setAttribute( attr, qrRect.getAttribute( attr ) );
				}
			} );
			qrImage.setAttribute( 'preserveAspectRatio', 'xMidYMid meet' );
			qrImage.style.display = 'none';
			qrRect.parentNode.insertBefore( qrImage, qrRect.nextSibling );
		}

		function fit( slot ) {
			if ( ! slot.box ) {
				return;
			}
			var el = slot.el;
			if ( ! slot.baseSize ) {
				return;
			}
			el.style.fontSize = slot.baseSize + 'px';
			var t = el.getBoundingClientRect();
			var b = slot.box.getBoundingClientRect();
			if ( ! t.width || ! b.width ) {
				return; // Not on screen yet; refit() runs again once it is.
			}
			var ratio = Math.min( 1, b.width / t.width, b.height && t.height ? b.height / t.height : 1 );
			if ( ratio < 1 ) {
				el.style.fontSize = Math.max( slot.baseSize * 0.2, slot.baseSize * ratio * 0.98 ) + 'px';
			}
		}

		function renderSlots() {
			slotEls.forEach( function ( slot ) {
				var filled = fillSlot( slot.template, slotFields, state.values, state.placeholders );
				slot.el.textContent = filled.text;
				slot.el.style.opacity = filled.anyValue ? '' : ( filled.text ? '0.45' : '0' );
				fit( slot );
			} );

			if ( qrImage ) {
				var qrField = slotFields.qr || null;
				var qrText = qrField ? String( state.values[ qrField ] || '' ).trim() : '';
				if ( qrText.length >= 4 && options.qrUrl ) {
					qrImage.setAttribute( 'href', options.qrUrl( qrText ) );
					qrImage.style.display = '';
				} else {
					qrImage.style.display = 'none';
				}
			}
		}

		var api = {
			svg: svg,
			setColors: function ( colors ) {
				Object.keys( colors || {} ).forEach( function ( key ) {
					state.colors[ key ] = colors[ key ];
					if ( colors[ key ] ) {
						svg.style.setProperty( '--yp-' + key, colors[ key ] );
					} else {
						svg.style.removeProperty( '--yp-' + key );
					}
				} );
			},
			setValues: function ( values ) {
				state.values = values || {};
				renderSlots();
			},
			setPlaceholders: function ( placeholders ) {
				state.placeholders = placeholders || {};
				renderSlots();
			},
			/** Re-measures fit boxes, e.g. after the stage becomes visible or resizes. */
			refit: function () {
				slotEls.forEach( fit );
			},
			/** Briefly pulses one part (or a list) so a customer sees what a color row changes. */
			highlight: function ( keys ) {
				Array.prototype.forEach.call( svg.querySelectorAll( '.yp-svg-hl' ), function ( el ) {
					el.classList.remove( 'yp-svg-hl' );
				} );
				( keys ? [].concat( keys ) : [] ).forEach( function ( key ) {
					Array.prototype.forEach.call( svg.querySelectorAll( '[style*="--yp-' + key + ',"]' ), function ( el ) {
						el.classList.add( 'yp-svg-hl' );
					} );
				} );
			}
		};

		api.setColors( options.colors || {} );
		api.setValues( options.values || {} );

		if ( window.ResizeObserver ) {
			new ResizeObserver( function () { api.refit(); } ).observe( container );
		}
		if ( document.fonts && document.fonts.ready ) {
			document.fonts.ready.then( function () { api.refit(); } );
		}

		return api;
	}

	/** Every part's starting color from the payload, as { key: hex }. */
	function defaults( fieldSchema ) {
		var out = {};
		( fieldSchema || [] ).forEach( function ( field ) {
			if ( 'color_choice' === field.type && field.part ) {
				out[ field.part ] = field.default;
			}
		} );
		return out;
	}

	// One shared stylesheet for the highlight pulse.
	if ( ! document.getElementById( 'yp-label-svg-style' ) ) {
		var style = document.createElement( 'style' );
		style.id = 'yp-label-svg-style';
		style.textContent = '@keyframes yp-svg-pulse{0%,100%{opacity:1}50%{opacity:.35}}' +
			'.yp-svg-hl{animation:yp-svg-pulse 1s ease-in-out 2}' +
			'@media (prefers-reduced-motion:reduce){.yp-svg-hl{animation:none}}';
		( document.head || document.documentElement ).appendChild( style );
	}

	window.YPLabelSvg = { mount: mount, defaults: defaults };
} )();
