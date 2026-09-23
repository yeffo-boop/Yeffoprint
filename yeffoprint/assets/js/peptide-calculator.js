/**
 * Peptide Reconstitution Calculator page (templates/peptide-calculator.html).
 * Pure client-side math, no REST calls:
 *
 *   concentration (mg/mL) = peptide in vial (mg) ÷ bacteriostatic water (mL)
 *   volume per dose (mL)  = dose (mg) ÷ concentration
 *   units to draw         = volume × 100   (U-100 insulin syringe)
 *
 * Also draws the syringe (inline SVG, rebuilt only when the syringe size
 * changes) and keeps the vial-label preview in the tie-in section in
 * sync with the same numbers.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.yp-pcalc' );
	if ( ! root ) {
		return;
	}

	var form = root.querySelector( '[data-yp-pcalc-form]' );
	var vialInput = document.getElementById( 'yp-pcalc-vial' );
	var waterInput = document.getElementById( 'yp-pcalc-water' );
	var doseInput = document.getElementById( 'yp-pcalc-dose' );
	var nameInput = document.getElementById( 'yp-pcalc-name' );

	function out( key ) {
		return root.querySelector( '[data-yp-pcalc="' + key + '"]' );
	}

	var SVG_NS = 'http://www.w3.org/2000/svg';
	// Barrel geometry, in the SVG's own viewBox units (680 × 112).
	var BX = 60;
	var BW = 520;
	var BY = 30;
	var BH = 48;
	var SYRINGE_ML = { 30: '0.3 mL', 50: '0.5 mL', 100: '1 mL' };

	var svg = out( 'syringe' );
	var liquid;
	var plunger;
	var mark;
	var flagText;
	var builtFor = null;

	function fmt( n, maxDigits ) {
		if ( ! isFinite( n ) ) {
			return '–';
		}
		var d = maxDigits == null ? 2 : maxDigits;
		return Number( n.toFixed( d ) ).toLocaleString( 'en-US', { maximumFractionDigits: d } );
	}

	function el( tag, attrs, parent ) {
		var node = document.createElementNS( SVG_NS, tag );
		Object.keys( attrs ).forEach( function ( k ) {
			node.setAttribute( k, attrs[ k ] );
		} );
		if ( parent ) {
			parent.appendChild( node );
		}
		return node;
	}

	// "2.5" + small "mg/mL" suffix, as in the static markup.
	function setValue( node, value, unit ) {
		node.textContent = value;
		if ( unit ) {
			var span = document.createElement( 'span' );
			span.textContent = unit;
			node.appendChild( span );
		}
	}

	// "Pull the plunger to the <b>10</b> mark." — text pieces with the
	// numbers bolded, built without innerHTML.
	function setHint( parts ) {
		var hint = out( 'hint' );
		hint.textContent = '';
		parts.forEach( function ( part, i ) {
			if ( i % 2 ) {
				var b = document.createElement( 'b' );
				b.textContent = part;
				hint.appendChild( b );
			} else {
				hint.appendChild( document.createTextNode( part ) );
			}
		} );
	}

	function buildSyringe( cap ) {
		svg.textContent = '';

		var defs = el( 'defs', {}, svg );
		var ok = el( 'linearGradient', { id: 'yp-pcalc-liquid', x1: '0', x2: '1', y1: '0', y2: '0' }, defs );
		el( 'stop', { offset: '0', 'stop-color': '#00AEEF', 'stop-opacity': '0.85' }, ok );
		el( 'stop', { offset: '1', 'stop-color': '#EC008C', 'stop-opacity': '0.75' }, ok );
		var over = el( 'linearGradient', { id: 'yp-pcalc-liquid-over', x1: '0', x2: '1', y1: '0', y2: '0' }, defs );
		el( 'stop', { offset: '0', 'stop-color': '#F5B400', 'stop-opacity': '0.8' }, over );
		el( 'stop', { offset: '1', 'stop-color': '#EC008C', 'stop-opacity': '0.8' }, over );

		// Needle and hub.
		el( 'rect', { x: 4, y: BY + BH / 2 - 1.2, width: 40, height: 2.4, rx: 1.2, class: 'yp-pcalc-s__metal' }, svg );
		el( 'path', {
			d: 'M42 ' + ( BY + 13 ) + ' L' + BX + ' ' + ( BY + 6 ) + ' L' + BX + ' ' + ( BY + BH - 6 ) + ' L42 ' + ( BY + BH - 13 ) + 'Z',
			class: 'yp-pcalc-s__metal',
			opacity: '0.8',
		}, svg );

		// Barrel, liquid, finger flange.
		el( 'rect', { x: BX, y: BY, width: BW, height: BH, rx: 6, class: 'yp-pcalc-s__barrel' }, svg );
		liquid = el( 'rect', { x: BX + 1, y: BY + 3, width: BW - 2, height: BH - 6, rx: 3, fill: 'url(#yp-pcalc-liquid)', class: 'yp-pcalc-s__liquid' }, svg );
		el( 'rect', { x: BX + BW - 2, y: BY - 12, width: 8, height: BH + 24, rx: 3, class: 'yp-pcalc-s__metal' }, svg );

		// Graduations: 1-unit marks on 0.3/0.5 mL syringes, 2-unit marks
		// on 1 mL — the same as the printed barrels.
		var minor = cap === 100 ? 2 : 1;
		var labelEvery = cap === 100 ? 10 : 5;
		for ( var u = 0; u <= cap; u += minor ) {
			var x = BX + ( u / cap ) * BW;
			var major = u % labelEvery === 0;
			el( 'line', {
				x1: x,
				x2: x,
				y1: BY,
				y2: BY + ( major ? 16 : 9 ),
				class: 'yp-pcalc-s__tick' + ( major ? ' yp-pcalc-s__tick--major' : '' ),
			}, svg );
			if ( major ) {
				el( 'text', { x: x, y: BY - 8, class: 'yp-pcalc-s__num' }, svg ).textContent = u;
			}
		}

		// Plunger: stopper, rod and thumb rest move together.
		plunger = el( 'g', { class: 'yp-pcalc-s__moves' }, svg );
		el( 'rect', { x: BX - 2, y: BY + 4, width: 12, height: BH - 8, rx: 3, class: 'yp-pcalc-s__stopper' }, plunger );
		el( 'rect', { x: BX + 10, y: BY + BH / 2 - 4, width: BW + 6, height: 8, rx: 2, class: 'yp-pcalc-s__metal', opacity: '0.6' }, plunger );
		el( 'rect', { x: BX + BW + 14, y: BY - 6, width: 7, height: BH + 12, rx: 3, class: 'yp-pcalc-s__metal' }, plunger );

		// The "draw to" marker under the barrel.
		mark = el( 'g', { class: 'yp-pcalc-s__moves' }, svg );
		el( 'line', { x1: BX, x2: BX, y1: BY - 2, y2: BY + BH + 9, class: 'yp-pcalc-s__mark' }, mark );
		el( 'rect', { x: BX - 30, y: BY + BH + 8, width: 60, height: 22, rx: 11, class: 'yp-pcalc-s__flag' }, mark );
		flagText = el( 'text', { x: BX, y: BY + BH + 23.5, class: 'yp-pcalc-s__flag-text' }, mark );

		builtFor = cap;
	}

	function drawSyringe( units, cap, overCap ) {
		if ( builtFor !== cap ) {
			buildSyringe( cap );
		}
		var f = isFinite( units ) ? Math.max( 0, Math.min( units / cap, 1 ) ) : 0;
		var dx = f * BW;
		liquid.style.transform = 'scaleX(' + Math.max( f, 0.0001 ) + ')';
		liquid.setAttribute( 'fill', overCap ? 'url(#yp-pcalc-liquid-over)' : 'url(#yp-pcalc-liquid)' );
		plunger.style.transform = 'translateX(' + dx + 'px)';
		mark.style.transform = 'translateX(' + dx + 'px)';
		mark.style.opacity = f > 0 ? '1' : '0';
		flagText.textContent = overCap ? cap + '+ u' : fmt( units, 1 ) + ' u';
		out( 'cap-left' ).textContent = 'U-100 · ' + SYRINGE_ML[ cap ];
		var step = cap === 100 ? 2 : 1;
		out( 'cap-right' ).textContent = step + ( step === 1 ? ' unit' : ' units' ) + ' per mark';
	}

	function read() {
		var unit = form.querySelector( 'input[name="doseUnit"]:checked' ).value;
		var dose = parseFloat( doseInput.value );
		return {
			vialMg: parseFloat( vialInput.value ),
			waterMl: parseFloat( waterInput.value ),
			dose: dose,
			unit: unit,
			doseMg: unit === 'mcg' ? dose / 1000 : dose,
			cap: parseInt( form.querySelector( 'input[name="syringe"]:checked' ).value, 10 ),
		};
	}

	function syncChips() {
		root.querySelectorAll( '[data-yp-pcalc-chips]' ).forEach( function ( group ) {
			var v = parseFloat( document.getElementById( group.getAttribute( 'data-yp-pcalc-chips' ) ).value );
			group.querySelectorAll( '.yp-pcalc__chip' ).forEach( function ( chip ) {
				chip.setAttribute( 'aria-pressed', String( parseFloat( chip.getAttribute( 'data-v' ) ) === v ) );
			} );
		} );
	}

	function showWarning( text ) {
		var warn = out( 'warn' );
		warn.hidden = ! text;
		out( 'warn-text' ).textContent = text || '';
	}

	function updateLabel( s, conc ) {
		out( 'label-mg' ).textContent = s.vialMg > 0 ? fmt( s.vialMg, 2 ) + ' mg' : '– mg';
		out( 'label-recon' ).textContent = isFinite( conc )
			? '+ ' + fmt( s.waterMl, 2 ) + ' mL bac water → ' + fmt( conc, 3 ) + ' mg/mL'
			: '+ – mL bac water';
	}

	function update() {
		var s = read();
		syncChips();

		var valid = s.vialMg > 0 && s.waterMl > 0 && s.doseMg > 0;
		var conc = s.vialMg / s.waterMl;
		var vol = s.doseMg / conc;
		var units = vol * 100;
		// Epsilon so 5 mg ÷ 0.25 mg lands on 20, not 19.999….
		var doses = Math.floor( s.vialMg / s.doseMg + 1e-9 );
		var overCap = valid && units > s.cap + 1e-9;
		var step = s.cap === 100 ? 2 : 1;

		if ( ! valid ) {
			out( 'units' ).textContent = '–';
			setHint( [ 'Fill in all three amounts to see where to draw.' ] );
			[ 'conc', 'vol', 'doses' ].forEach( function ( k ) {
				setValue( out( k ), '–' );
			} );
			[ 'per-unit', 'vol-sub', 'doses-sub', 'm-conc', 'm-vol', 'm-units' ].forEach( function ( k ) {
				out( k ).textContent = '';
			} );
			showWarning( '' );
			drawSyringe( NaN, s.cap, false );
			updateLabel( s, NaN );
			return;
		}

		out( 'units' ).textContent = fmt( units, 1 );

		var onMark = Math.abs( units / step - Math.round( units / step ) ) < 0.05;
		if ( overCap ) {
			setHint( [ 'More than this syringe holds.' ] );
		} else if ( onMark ) {
			setHint( [ 'Pull the plunger to the ', fmt( Math.round( units / step ) * step, 0 ), ' mark.' ] );
		} else {
			var lo = Math.floor( units / step ) * step;
			setHint( [ 'Between the ', String( lo ), ' and ', String( lo + step ), ' marks. A little more or less water gives a cleaner line.' ] );
		}

		setValue( out( 'conc' ), fmt( conc, 3 ), 'mg/mL' );
		out( 'per-unit' ).textContent = fmt( conc * 10, 2 ) + ' mcg per unit';
		setValue( out( 'vol' ), fmt( vol, 3 ), 'mL' );
		out( 'vol-sub' ).textContent = 'of ' + fmt( s.waterMl, 2 ) + ' mL in the vial';
		setValue( out( 'doses' ), fmt( doses, 0 ) );
		out( 'doses-sub' ).textContent = 'at ' + fmt( s.dose, 3 ) + ' ' + s.unit + ' each';

		out( 'm-conc' ).textContent = fmt( s.vialMg, 3 ) + ' mg ÷ ' + fmt( s.waterMl, 3 ) + ' mL = ' + fmt( conc, 3 ) + ' mg/mL';
		out( 'm-vol' ).textContent = fmt( s.doseMg, 4 ) + ' mg ÷ ' + fmt( conc, 3 ) + ' mg/mL = ' + fmt( vol, 3 ) + ' mL';
		out( 'm-units' ).textContent = fmt( vol, 3 ) + ' mL × 100 = ' + fmt( units, 1 ) + ' units';

		if ( s.doseMg > s.vialMg ) {
			showWarning( 'This dose is more than the whole vial holds (' + fmt( s.vialMg, 3 ) + ' mg). Double-check the dose unit.' );
		} else if ( overCap ) {
			var fits = [ 30, 50, 100 ].filter( function ( c ) {
				return c >= units;
			} )[ 0 ];
			showWarning( fits
				? 'This dose needs ' + fmt( units, 1 ) + ' units, which won’t fit a ' + SYRINGE_ML[ s.cap ] + ' syringe. Switch to the ' + SYRINGE_ML[ fits ] + ' syringe or add less water.'
				: 'This dose needs ' + fmt( units, 1 ) + ' units, more than a 1 mL syringe holds. Add less water to concentrate the vial.' );
		} else if ( units < 2 ) {
			showWarning( 'Under 2 units is hard to measure accurately. Adding more water spreads the dose over more marks.' );
		} else {
			showWarning( '' );
		}

		drawSyringe( units, s.cap, overCap );
		updateLabel( s, conc );
	}

	root.querySelectorAll( '[data-yp-pcalc-chips]' ).forEach( function ( group ) {
		group.addEventListener( 'click', function ( e ) {
			var chip = e.target.closest( '.yp-pcalc__chip' );
			if ( ! chip ) {
				return;
			}
			document.getElementById( group.getAttribute( 'data-yp-pcalc-chips' ) ).value = chip.getAttribute( 'data-v' );
			update();
		} );
	} );

	// Switching mcg ↔ mg converts the number so the dose itself stays put.
	form.querySelectorAll( 'input[name="doseUnit"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			var d = parseFloat( doseInput.value );
			if ( d > 0 ) {
				doseInput.value = String( Number( ( radio.value === 'mg' ? d / 1000 : d * 1000 ).toFixed( 4 ) ) );
			}
		} );
	} );

	form.addEventListener( 'input', update );
	form.addEventListener( 'change', update );
	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
	} );

	if ( nameInput ) {
		nameInput.addEventListener( 'input', function () {
			out( 'label-name' ).textContent = nameInput.value.trim() || 'Your Product';
		} );
	}

	update();
} )();
