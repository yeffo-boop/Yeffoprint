/**
 * Shared Size / Material / Quantity picker markup for the label designer
 * (configurator.js on a Template's page) and the custom label form
 * (custom-order-form.js).
 *
 * Direct request: show each label size "in an image" — a card with the
 * label drawn to scale and its dimensions — and pick the material from a
 * picture of the material itself (not a vial photo), with an animated
 * shimmer on the holographic finishes.
 *
 * Everything is drawn from data an admin already enters: a Size's print
 * width/height (Catalog → Sizes) and a Material's swatch finish
 * (Catalog → Materials, class-commerce-record-meta.php's SWATCH_FINISH).
 * Every card in one picker shares a single scale (groupScale()), so a
 * new size is automatically drawn in true proportion to the others with
 * no code change.
 *
 * Pure string builders plus one canvas texture — no state, no listeners.
 * Callers own selection state and wire their own click handlers, which
 * keeps each form's existing data flow untouched.
 */

( function () {
	'use strict';

	var MM_PER_INCH = 25.4;

	var CHECK_HTML =
		'<span class="yp-pick-check" aria-hidden="true">' +
			'<svg viewBox="0 0 12 12" focusable="false"><path d="M2.5 6.2l2.3 2.3 4.7-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
		'</span>';

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}

	function round( value ) {
		return Math.round( value * 10 ) / 10;
	}

	function hasDimensions( size ) {
		return size && size.print_width_mm > 0 && size.print_height_mm > 0;
	}

	/** 45 → '1.77″' (two decimals, trailing zero dropped: 60.96 mm → '2.4″'). */
	function inches( mm ) {
		return ( mm / MM_PER_INCH ).toFixed( 2 ).replace( /0$/, '' ).replace( /\.$/, '' ) + '″';
	}

	function mm( value ) {
		return String( Math.round( value * 10 ) / 10 );
	}

	/** '+$0.03' / 'No add-on'. */
	function adjustmentLabel( amount ) {
		if ( ! amount ) {
			return 'No add-on';
		}
		return ( amount > 0 ? '+' : '−' ) + '$' + Math.abs( amount ).toFixed( 2 );
	}

	/**
	 * Pixels per mm that fits the largest width and the largest height in
	 * `sizes` into a boxW × boxH area — one number for the whole picker,
	 * so the drawings compare honestly against each other.
	 */
	function groupScale( sizes, boxW, boxH ) {
		var maxW = 0;
		var maxH = 0;
		( sizes || [] ).forEach( function ( size ) {
			if ( hasDimensions( size ) ) {
				maxW = Math.max( maxW, size.print_width_mm );
				maxH = Math.max( maxH, size.print_height_mm );
			}
		} );
		if ( ! maxW || ! maxH ) {
			return 0;
		}
		return Math.min( boxW / maxW, boxH / maxH );
	}

	/**
	 * The label outline at `scale` px/mm, bottom-aligned in a boxW × boxH
	 * area, with a few placeholder text bars so it reads as a label.
	 * `withDimensions` adds width/height dimension lines in inches;
	 * `rounded` rounds the corners (the Corner Finish field's choice).
	 * A size with no dimensions (e.g. "Custom") draws a dashed outline.
	 */
	function sizeDrawing( size, scale, boxW, boxH, options ) {
		options = options || {};
		var withDimensions = !! options.withDimensions && hasDimensions( size );
		var sidePad = withDimensions ? 30 : 4;
		var bottomPad = withDimensions ? 20 : 4;
		var viewW = boxW + sidePad + 4;
		var viewH = boxH + bottomPad + 4;

		var isCustom = ! hasDimensions( size ) || ! scale;
		var w = isCustom ? boxW * 0.62 : size.print_width_mm * scale;
		var h = isCustom ? boxH * 0.5 : size.print_height_mm * scale;
		var x = round( ( boxW - w ) / 2 + 2 );
		var y = round( boxH - h + 2 );
		var r = options.rounded ? round( Math.min( w, h ) * 0.12 ) : 1;
		w = round( w );
		h = round( h );

		var svg = '<svg class="yp-size-drawing" width="' + viewW + '" height="' + viewH + '" viewBox="0 0 ' + viewW + ' ' + viewH + '" aria-hidden="true" focusable="false">';
		svg += '<rect class="yp-size-drawing__label' + ( isCustom ? ' is-custom' : '' ) + '" x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" rx="' + r + '"/>';

		if ( isCustom ) {
			svg += '<text class="yp-size-drawing__dim" x="' + round( x + w / 2 ) + '" y="' + round( y + h / 2 + 3 ) + '" text-anchor="middle">W × H</text>';
		} else {
			var inset = x + w * 0.1;
			var bar = Math.max( 2, round( Math.min( w, h ) * 0.12 ) );
			svg += '<rect class="yp-size-drawing__ink" x="' + round( inset ) + '" y="' + round( y + h * 0.22 ) + '" width="' + round( w * 0.45 ) + '" height="' + bar + '" rx="' + round( bar / 2 ) + '"/>';
			svg += '<rect class="yp-size-drawing__line" x="' + round( inset ) + '" y="' + round( y + h * 0.46 ) + '" width="' + round( w * 0.58 ) + '" height="' + round( bar * 0.7 ) + '" rx="' + round( bar * 0.35 ) + '"/>';
			svg += '<rect class="yp-size-drawing__line" x="' + round( inset ) + '" y="' + round( y + h * 0.64 ) + '" width="' + round( w * 0.4 ) + '" height="' + round( bar * 0.7 ) + '" rx="' + round( bar * 0.35 ) + '"/>';
			svg += '<circle class="yp-size-drawing__line" cx="' + round( x + w * 0.82 ) + '" cy="' + round( y + h / 2 ) + '" r="' + round( Math.min( h * 0.18, w * 0.1 ) ) + '"/>';
		}

		if ( withDimensions ) {
			var by = round( y + h + 10 );
			var rx = round( x + w + 8 );
			svg += '<path class="yp-size-drawing__guide" d="M' + x + ' ' + ( by - 3 ) + 'v6M' + round( x + w ) + ' ' + ( by - 3 ) + 'v6M' + x + ' ' + by + 'H' + round( x + w ) + '"/>';
			svg += '<text class="yp-size-drawing__dim" x="' + round( x + w / 2 ) + '" y="' + ( by + 12 ) + '" text-anchor="middle">' + inches( size.print_width_mm ) + '</text>';
			svg += '<path class="yp-size-drawing__guide" d="M' + ( rx - 3 ) + ' ' + y + 'h6M' + ( rx - 3 ) + ' ' + round( y + h ) + 'h6M' + rx + ' ' + y + 'V' + round( y + h ) + '"/>';
			svg += '<text class="yp-size-drawing__dim" x="' + ( rx + 4 ) + '" y="' + round( y + h / 2 + 3 ) + '">' + inches( size.print_height_mm ) + '</text>';
		}

		return svg + '</svg>';
	}

	/**
	 * A full size card (product page). `options.scale` comes from
	 * groupScale() over every size in the same picker.
	 */
	function sizeCardHtml( size, options ) {
		var selected = !! options.selected;
		var meta = hasDimensions( size )
			? mm( size.print_width_mm ) + ' × ' + mm( size.print_height_mm ) + ' mm'
			: 'Any size';

		return (
			'<button type="button" role="radio" aria-checked="' + ( selected ? 'true' : 'false' ) + '" class="yp-size-card' + ( selected ? ' is-selected' : '' ) + '" data-option-group="size" data-option-id="' + size.id + '">' +
				CHECK_HTML +
				sizeDrawing( size, options.scale, options.boxW || 96, options.boxH || 54, { withDimensions: true, rounded: options.rounded } ) +
				'<span class="yp-size-card__name">' + escapeHtml( size.name ) + '</span>' +
				'<span class="yp-size-card__mm">' + meta + '</span>' +
				( size.fit_note ? '<span class="yp-size-card__fits">' + escapeHtml( size.fit_note ) + '</span>' : '' ) +
				'<span class="yp-size-card__adj' + ( size.price_adjustment ? '' : ' is-zero' ) + '">' + adjustmentLabel( size.price_adjustment ) + ( size.price_adjustment ? '/label' : '' ) + '</span>' +
			'</button>'
		);
	}

	/** A compact size tile for a scrolling strip (custom label form rows). */
	function sizeTileHtml( size, options ) {
		var selected = !! options.selected;
		return (
			'<button type="button" role="radio" aria-checked="' + ( selected ? 'true' : 'false' ) + '" class="yp-size-tile' + ( selected ? ' is-selected' : '' ) + '"' + ( options.attrs || '' ) + '>' +
				sizeDrawing( size, options.scale, options.boxW || 62, options.boxH || 48, { rounded: true } ) +
				'<span class="yp-size-tile__name">' + escapeHtml( size.name ) + '</span>' +
				'<span class="yp-size-tile__mm">' + ( hasDimensions( size ) ? mm( size.print_width_mm ) + '×' + mm( size.print_height_mm ) : 'any' ) + '</span>' +
			'</button>'
		);
	}

	/* ---------- Material swatches ---------- */

	var FINISHES = [ 'glossy', 'matte', 'clear', 'metallic', 'holographic', 'prism' ];
	var SHIMMER_FINISHES = [ 'holographic', 'prism' ];
	var prismTextureUrl = null;

	function finishFor( material ) {
		return material && FINISHES.indexOf( material.swatch_finish ) !== -1 ? material.swatch_finish : 'glossy';
	}

	/**
	 * Prism: cracked-ice shards over a silver base, drawn once to a
	 * canvas and reused as a background image (so it's not a photo an
	 * admin has to supply). Seeded, so it looks identical on every load.
	 */
	function prismTexture() {
		if ( null !== prismTextureUrl ) {
			return prismTextureUrl;
		}
		prismTextureUrl = '';
		try {
			var canvas = document.createElement( 'canvas' );
			canvas.width = 360;
			canvas.height = 120;
			var ctx = canvas.getContext( '2d' );
			var seed = 7;
			var rand = function () {
				seed = ( seed * 16807 ) % 2147483647;
				return seed / 2147483647;
			};

			var base = ctx.createLinearGradient( 0, 0, 360, 120 );
			base.addColorStop( 0, '#b9bec4' );
			base.addColorStop( 0.35, '#eef0f2' );
			base.addColorStop( 0.6, '#c3c8cd' );
			base.addColorStop( 1, '#f4f5f6' );
			ctx.fillStyle = base;
			ctx.fillRect( 0, 0, 360, 120 );

			for ( var i = 0; i < 150; i++ ) {
				var cx = rand() * 380 - 10;
				var cy = rand() * 140 - 10;
				var radius = 10 + rand() * 22;
				var angle = rand() * 6.28;
				ctx.beginPath();
				for ( var k = 0; k < 3; k++ ) {
					var a = angle + k * 2.09 + rand() * 0.6;
					ctx.lineTo( cx + Math.cos( a ) * radius, cy + Math.sin( a ) * radius );
				}
				ctx.closePath();
				// Mostly silver facets, some tinted: reads as foil first,
				// rainbow second (direct request: "a bit more silver like
				// the original materials").
				ctx.fillStyle = rand() < 0.45
					? 'hsla(210, 6%, ' + Math.round( 70 + rand() * 25 ) + '%, 0.75)'
					: 'hsla(' + Math.floor( rand() * 360 ) + ', 70%, ' + Math.round( 74 + rand() * 14 ) + '%, 0.5)';
				ctx.fill();
				ctx.strokeStyle = 'rgba(255, 255, 255, 0.75)';
				ctx.lineWidth = 0.8;
				ctx.stroke();
			}
			prismTextureUrl = canvas.toDataURL( 'image/png' );
		} catch ( e ) {
			prismTextureUrl = '';
		}
		return prismTextureUrl;
	}

	/** Just the swatch itself — a span the caller sizes/shapes with CSS. */
	function swatchHtml( material, extraClass, extraStyle ) {
		var finish = finishFor( material );
		var style = extraStyle || '';
		if ( 'prism' === finish && prismTexture() ) {
			style += 'background-image:url(' + prismTexture() + ');';
		}
		return '<span class="yp-swatch yp-swatch--' + finish + ( extraClass ? ' ' + extraClass : '' ) + '"' + ( style ? ' style="' + style + '"' : '' ) + ' aria-hidden="true"></span>';
	}

	function isShimmer( material ) {
		return SHIMMER_FINISHES.indexOf( finishFor( material ) ) !== -1;
	}

	/** A full material card (product page): swatch strip, name, description, thickness, add-on. */
	function materialCardHtml( material, options ) {
		var selected = !! options.selected;
		var outOfStock = false === material.in_stock;
		var details = [];
		if ( material.thickness_mil ) {
			details.push( '<b>' + material.thickness_mil + ' mil</b>' );
		}
		details.push( outOfStock
			? '<span class="yp-material-card__oos">Back soon</span>'
			: '<span class="yp-material-card__adj' + ( material.price_adjustment ? '' : ' is-zero' ) + '">' + adjustmentLabel( material.price_adjustment ) + ( material.price_adjustment ? '/label' : '' ) + '</span>' );

		return (
			'<button type="button" role="radio" aria-checked="' + ( selected ? 'true' : 'false' ) + '" class="yp-material-card' + ( selected ? ' is-selected' : '' ) + '" data-option-group="material" data-option-id="' + material.id + '"' + ( outOfStock ? ' disabled' : '' ) + '>' +
				CHECK_HTML +
				swatchHtml( material, 'yp-material-card__swatch' ) +
				'<span class="yp-material-card__body">' +
					'<span class="yp-material-card__name">' + escapeHtml( material.name ) + ( isShimmer( material ) ? ' <span class="yp-material-card__tag">Foil</span>' : '' ) + '</span>' +
					( material.description ? '<span class="yp-material-card__desc">' + escapeHtml( material.description ) + '</span>' : '' ) +
					'<span class="yp-material-card__meta">' + details.join( ' · ' ) + '</span>' +
				'</span>' +
			'</button>'
		);
	}

	/** A round swatch with its name underneath (custom label form rows). */
	function materialDotHtml( material, options ) {
		var selected = !! options.selected;
		var outOfStock = false === material.in_stock;
		return (
			'<button type="button" role="radio" aria-checked="' + ( selected ? 'true' : 'false' ) + '" class="yp-material-dot' + ( selected ? ' is-selected' : '' ) + '"' + ( outOfStock ? ' disabled' : '' ) + ( options.attrs || '' ) + '>' +
				swatchHtml( material, 'yp-material-dot__swatch' ) +
				'<span class="yp-material-dot__name">' + escapeHtml( material.name ) + '</span>' +
				'<span class="yp-material-dot__meta">' + ( outOfStock ? 'Back soon' : adjustmentLabel( material.price_adjustment ) ) + '</span>' +
			'</button>'
		);
	}

	/* ---------- Quantity ---------- */

	/**
	 * Preset buttons plus an "Other" button (direct request: "there needs
	 * to be an 'other' option where users can type in a quantity"). The
	 * number input is shown only while Other is active — which it is
	 * whenever the current quantity isn't one of the presets, e.g. a
	 * restored saved design with 75 labels.
	 */
	function quantityHtml( presets, quantity, inputId ) {
		var isOther = ( presets || [] ).indexOf( quantity ) === -1;
		return (
			'<div class="yp-qty-picker" role="group" aria-label="Quantity">' +
				( presets || [] ).map( function ( amount ) {
					return '<button type="button" class="yp-quantity-preset' + ( amount === quantity && ! isOther ? ' is-active' : '' ) + '" data-preset="' + amount + '" aria-pressed="' + ( amount === quantity && ! isOther ? 'true' : 'false' ) + '">' + amount + '</button>';
				} ).join( '' ) +
				'<button type="button" class="yp-quantity-preset yp-quantity-preset--other' + ( isOther ? ' is-active' : '' ) + '" data-qty-other aria-pressed="' + ( isOther ? 'true' : 'false' ) + '" aria-controls="' + inputId + '">Other</button>' +
			'</div>' +
			'<div class="yp-qty-other" data-qty-other-wrap' + ( isOther ? '' : ' hidden' ) + '>' +
				'<label for="' + inputId + '">Enter a quantity</label>' +
				'<input type="number" min="1" step="1" inputmode="numeric" id="' + inputId + '" class="yp-quantity-input" value="' + quantity + '" />' +
			'</div>'
		);
	}

	/**
	 * Wires a quantityHtml() block. `onChange(quantity)` fires on every
	 * preset click and every valid typed value.
	 */
	function bindQuantity( container, presets, onChange ) {
		var wrap = container.querySelector( '[data-qty-other-wrap]' );
		var input = wrap.querySelector( 'input' );
		var otherButton = container.querySelector( '[data-qty-other]' );

		function setActive( button ) {
			container.querySelectorAll( '.yp-quantity-preset' ).forEach( function ( b ) {
				var on = b === button;
				b.classList.toggle( 'is-active', on );
				b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
		}

		container.querySelectorAll( '[data-preset]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var amount = parseInt( button.getAttribute( 'data-preset' ), 10 );
				setActive( button );
				wrap.hidden = true;
				input.value = amount;
				onChange( amount );
			} );
		} );

		otherButton.addEventListener( 'click', function () {
			setActive( otherButton );
			wrap.hidden = false;
			input.focus();
			input.select();
		} );

		input.addEventListener( 'input', function () {
			var value = parseInt( input.value, 10 );
			if ( value >= 1 ) {
				onChange( value );
			}
		} );

		input.addEventListener( 'blur', function () {
			var value = parseInt( input.value, 10 );
			if ( ! ( value >= 1 ) ) {
				input.value = 1;
				onChange( 1 );
			}
		} );
	}

	window.YPLabelPickers = {
		inches: inches,
		hasDimensions: hasDimensions,
		groupScale: groupScale,
		sizeDrawing: sizeDrawing,
		sizeCardHtml: sizeCardHtml,
		sizeTileHtml: sizeTileHtml,
		swatchHtml: swatchHtml,
		materialCardHtml: materialCardHtml,
		materialDotHtml: materialDotHtml,
		quantityHtml: quantityHtml,
		bindQuantity: bindQuantity
	};
} )();
