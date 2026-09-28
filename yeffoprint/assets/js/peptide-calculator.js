/**
 * Peptide & Hormone Calculator page (templates/peptide-calculator.html).
 * Pure client-side math, no REST calls. Three calculators share one
 * results panel, switched by the tabs at the top (and deep-linkable as
 * #peptide / #iu / #hormone / #blend, or #blend-mix for Mixing my own):
 *
 *   Peptides (mg):   concentration = vial mg ÷ water mL;  volume = dose ÷ concentration
 *   HGH / HCG (IU):  concentration = vial IU ÷ water mL;  volume = dose IU ÷ concentration
 *   Hormones:        per injection = weekly mg ÷ injections per week;
 *                    volume = per injection ÷ vial mg/mL
 *   Blends, bought:  same as Peptides, with the concentration of the whole
 *                    blend (total mg ÷ water) or of the one peptide the
 *                    dose is measured by (its mg ÷ water)
 *   Blends, mixing:  doses in the mix = smallest (vial mg ÷ dose) across
 *                    the peptides — that vial goes in whole; every other
 *                    vial gives (doses × its dose ÷ its mg) of its water.
 *                    volume per dose = mixed mL ÷ doses in the mix
 *
 *   units to draw = volume (mL) × 100   (U-100 insulin syringe)
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
	var nameInput = document.getElementById( 'yp-pcalc-name' );
	var tabs = [].slice.call( root.querySelectorAll( '.yp-pcalc__mode' ) );
	var mode = 'peptide';

	function out( key ) {
		return root.querySelector( '[data-yp-pcalc="' + key + '"]' );
	}

	function num( id ) {
		return parseFloat( document.getElementById( id ).value );
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
		boldParts( hint, parts );
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

	/*
	 * Blend peptides: one row per peptide (name, mg in the vial, and — when
	 * mixing your own — the dose of it per injection). Rows live in
	 * `parts` and are redrawn only when one is added or removed.
	 */
	var parts = [
		{ name: 'BPC-157', mg: '10', dose: '250' },
		{ name: 'TB-500', mg: '10', dose: '500' },
	];
	var MAX_PARTS = 5;
	var partsBox = out( 'parts' );
	var addPart = out( 'add-part' );
	var ofSelect = document.getElementById( 'yp-pcalc-b-of' );

	function blendKind() {
		return form.querySelector( 'input[name="blendKind"]:checked' ).value;
	}

	function partName( part, i ) {
		return String( part.name || '' ).trim() || 'Peptide ' + ( i + 1 );
	}

	function numField( value, unit, label, onInput ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'yp-pcalc__num';
		var input = document.createElement( 'input' );
		input.className = 'yp-pcalc__input';
		input.type = 'number';
		input.inputMode = 'decimal';
		input.min = '0';
		input.step = 'any';
		input.value = value;
		input.setAttribute( 'aria-label', label );
		input.addEventListener( 'input', function () {
			onInput( input.value );
		} );
		var u = document.createElement( 'span' );
		u.className = 'yp-pcalc__unit';
		u.textContent = unit;
		wrap.appendChild( input );
		wrap.appendChild( u );
		return wrap;
	}

	function renderParts() {
		var mix = blendKind() === 'mix';
		var doseUnit = form.querySelector( 'input[name="bMixUnit"]:checked' ).value;
		partsBox.textContent = '';
		parts.forEach( function ( part, i ) {
			var row = document.createElement( 'div' );
			row.className = 'yp-pcalc__part';

			var top = document.createElement( 'div' );
			top.className = 'yp-pcalc__part-top';
			var name = document.createElement( 'input' );
			name.className = 'yp-pcalc__input yp-pcalc__part-name';
			name.type = 'text';
			name.maxLength = 40;
			name.value = part.name;
			name.placeholder = 'Peptide ' + ( i + 1 );
			name.setAttribute( 'aria-label', 'Peptide ' + ( i + 1 ) + ' name' );
			name.addEventListener( 'input', function () {
				part.name = name.value;
				fillOf();
			} );
			top.appendChild( name );
			if ( parts.length > 2 ) {
				var rm = document.createElement( 'button' );
				rm.type = 'button';
				rm.className = 'yp-pcalc__part-rm';
				rm.textContent = '×';
				rm.setAttribute( 'aria-label', 'Remove ' + partName( part, i ) );
				rm.addEventListener( 'click', function () {
					parts.splice( i, 1 );
					renderParts();
					update();
				} );
				top.appendChild( rm );
			}
			row.appendChild( top );

			var nums = document.createElement( 'div' );
			nums.className = 'yp-pcalc__row';
			nums.appendChild( numField( part.mg, mix ? 'mg vial' : 'mg', partName( part, i ) + ' mg in the vial', function ( v ) {
				part.mg = v;
			} ) );
			if ( mix ) {
				nums.appendChild( numField( part.dose, doseUnit + ' dose', partName( part, i ) + ' dose per injection', function ( v ) {
					part.dose = v;
				} ) );
			}
			row.appendChild( nums );
			partsBox.appendChild( row );
		} );
		addPart.hidden = parts.length >= MAX_PARTS;
		fillOf();
	}

	// "That dose is": the whole blend, or measured by one peptide in it.
	function fillOf() {
		var current = ofSelect.value || 'all';
		ofSelect.textContent = '';
		var all = document.createElement( 'option' );
		all.value = 'all';
		all.textContent = 'The whole blend';
		ofSelect.appendChild( all );
		parts.forEach( function ( part, i ) {
			var o = document.createElement( 'option' );
			o.value = String( i );
			o.textContent = 'The ' + partName( part, i ) + ' in it';
			ofSelect.appendChild( o );
		} );
		ofSelect.value = +current < parts.length || current === 'all' ? current : 'all';
	}

	addPart.addEventListener( 'click', function () {
		parts.push( { name: '', mg: '', dose: '' } );
		renderParts();
		var names = partsBox.querySelectorAll( '.yp-pcalc__part-name' );
		names[ names.length - 1 ].focus();
		update();
	} );

	// Fields and wording that change between Bought blended and Mixing my own.
	function syncBlendKind() {
		var mix = blendKind() === 'mix';
		out( 'parts-title' ).textContent = mix ? 'Vials you’re combining' : 'Peptides in the vial';
		out( 'parts-hint' ).textContent = mix ? 'mg in each vial, and your dose of it' : 'mg of each, printed on the vial';
		out( 'b-water-label' ).textContent = mix ? 'Water added to each vial' : 'Bacteriostatic water added';
		out( 'b-water-hint' ).textContent = mix ? 'before combining' : 'total volume';
		out( 'b-dose-field' ).hidden = mix;
		out( 'b-unit-field' ).hidden = ! mix;
		renderParts();
	}

	// "250 mcg BPC-157 + 250 mcg TB-500", in the unit the dose was entered in.
	function blendLine( amountsMg, unit ) {
		return amountsMg.map( function ( a ) {
			var v = unit === 'mcg' ? a.mg * 1000 : a.mg;
			return fmt( v, unit === 'mcg' ? 1 : 3 ) + ' ' + unit + ' ' + a.name;
		} ).join( ' + ' );
	}

	function blendParts( withDose ) {
		var unit = form.querySelector( 'input[name="bMixUnit"]:checked' ).value;
		var list = parts.map( function ( part, i ) {
			var dose = parseFloat( part.dose );
			return {
				i: i,
				name: partName( part, i ),
				mg: parseFloat( part.mg ),
				doseMg: unit === 'mcg' ? dose / 1000 : dose,
			};
		} );
		var ok = list.length >= 2 && list.every( function ( x ) {
			return x.mg > 0 && ( ! withDose || x.doseMg > 0 );
		} );
		return ok ? list : null;
	}

	function mlText( ml ) {
		return fmt( ml, 2 ) + ' mL';
	}

	/*
	 * Mixing your own: the step-by-step recipe. The vial that runs out
	 * first goes in whole; the others give only what the same number of
	 * doses needs, and the rest stays in its vial for next time.
	 */
	function mixRecipe( list, water ) {
		var doses = Math.min.apply( null, list.map( function ( x ) {
			return x.mg / x.doseMg;
		} ) );
		var base = list.filter( function ( x ) {
			return x.mg / x.doseMg <= doses + 1e-9;
		} )[ 0 ];
		var total = 0;
		list.forEach( function ( x ) {
			x.used = doses * x.doseMg;
			x.whole = x.used >= x.mg * 0.995;
			x.ml = x.whole ? water : ( x.used / x.mg ) * water;
			total += x.ml;
		} );
		var steps = [];
		steps.push( [ 'Add ', mlText( water ), ' of bacteriostatic water to each vial and swirl gently until it’s clear.' ] );
		var bigVial = total > 3 + 1e-9;
		if ( bigVial ) {
			steps.push( [ 'The blend will be ', mlText( total ), ', more than a standard 3 mL peptide vial holds. Combine it in an empty 5 or 10 mL sterile vial, or add less water to each vial.' ] );
		}
		var into = bigVial ? 'an empty sterile vial' : 'the ' + base.name + ' vial';
		var moved = 0;
		list.forEach( function ( x ) {
			if ( ! bigVial && x === base ) {
				return;
			}
			if ( bigVial && moved++ ) {
				into = 'the same vial';
			}
			var draw = x.whole ? 'Draw all ' + mlText( x.ml ) : 'Draw ' + mlText( x.ml );
			steps.push( [ draw + ' of ' + x.name + ( x.ml <= 1 ? ' (' + fmt( x.ml * 100, 0 ) + ' units)' : '' ) + ' and add it to ', into, x.whole ? '.' : '. Keep the rest (' + fmt( x.mg - x.used, 2 ) + ' mg) for next time.' ] );
		} );
		if ( bigVial ) {
			steps.push( [ 'Swirl gently. That vial now holds ', mlText( total ), ': your blend. Label it with what’s inside.' ] );
		} else {
			steps.push( [ 'The ' + base.name + ' vial now holds ', mlText( total ), ': your blend. Label it with what’s inside.' ] );
		}
		return { doses: doses, total: total, steps: steps, base: base };
	}

	/*
	 * Each mode's calc() returns null until its inputs are filled, else
	 * everything the shared results panel shows: the volume to draw, the
	 * three stats and three "how it's calculated" rows as
	 * [label, value, unit, sub] / [label, formula, worked example], an
	 * optional hard warning, the advice for an over-capacity dose, and
	 * the label preview's [amount, tag, detail line, second write-in].
	 */
	var MODES = {
		peptide: {
			desc: 'For peptides measured in mg. Enter the mg in the vial, the bacteriostatic water you’re adding, and your dose in mcg or mg.',
			title: 'Your vial',
			syringe: 50,
			calc: function () {
				var unit = form.querySelector( 'input[name="doseUnit"]:checked' ).value;
				var vial = num( 'yp-pcalc-vial' );
				var water = num( 'yp-pcalc-water' );
				var dose = num( 'yp-pcalc-dose' );
				var doseMg = unit === 'mcg' ? dose / 1000 : dose;
				if ( ! ( vial > 0 && water > 0 && doseMg > 0 ) ) {
					return null;
				}
				var conc = vial / water;
				var vol = doseMg / conc;
				return {
					vol: vol,
					stats: [
						[ 'Concentration', fmt( conc, 3 ), 'mg/mL', fmt( conc * 10, 2 ) + ' mcg per unit' ],
						[ 'Volume per dose', fmt( vol, 3 ), 'mL', 'of ' + fmt( water, 2 ) + ' mL in the vial' ],
						// Epsilon so 5 mg ÷ 0.25 mg lands on 20, not 19.999….
						[ 'Doses per vial', fmt( Math.floor( vial / doseMg + 1e-9 ), 0 ), '', 'at ' + fmt( dose, 3 ) + ' ' + unit + ' each' ],
					],
					steps: [
						[ 'Concentration', 'peptide (mg) ÷ water (mL)', fmt( vial, 3 ) + ' mg ÷ ' + fmt( water, 3 ) + ' mL = ' + fmt( conc, 3 ) + ' mg/mL' ],
						[ 'Volume per dose', 'dose ÷ concentration', fmt( doseMg, 4 ) + ' mg ÷ ' + fmt( conc, 3 ) + ' mg/mL = ' + fmt( vol, 3 ) + ' mL' ],
						[ 'Units to draw', 'volume (mL) × 100', fmt( vol, 3 ) + ' mL × 100 = ' + fmt( vol * 100, 1 ) + ' units' ],
					],
					tooMuch: doseMg > vial ? 'This dose is more than the whole vial holds (' + fmt( vial, 3 ) + ' mg). Double-check the dose unit.' : '',
					fix: 'add less water to concentrate the vial',
					label: [ fmt( vial, 2 ) + ' mg', 'Lyophilized', '+ ' + fmt( water, 2 ) + ' mL bac water → ' + fmt( conc, 3 ) + ' mg/mL', 'Mixed' ],
				};
			},
		},
		iu: {
			desc: 'For HGH, HCG and other vials measured in IU. Enter the IU in the vial, the bacteriostatic water you’re adding, and your dose in IU.',
			title: 'Your vial',
			syringe: 30,
			calc: function () {
				var vial = num( 'yp-pcalc-iu-vial' );
				var water = num( 'yp-pcalc-iu-water' );
				var dose = num( 'yp-pcalc-iu-dose' );
				if ( ! ( vial > 0 && water > 0 && dose > 0 ) ) {
					return null;
				}
				var conc = vial / water;
				var vol = dose / conc;
				return {
					vol: vol,
					stats: [
						[ 'Concentration', fmt( conc, 2 ), 'IU/mL', fmt( conc / 100, 3 ) + ' IU per unit' ],
						[ 'Volume per dose', fmt( vol, 3 ), 'mL', 'of ' + fmt( water, 2 ) + ' mL in the vial' ],
						[ 'Doses per vial', fmt( Math.floor( vial / dose + 1e-9 ), 0 ), '', 'at ' + fmt( dose, 2 ) + ' IU each' ],
					],
					steps: [
						[ 'Concentration', 'IU in vial ÷ water (mL)', fmt( vial, 2 ) + ' IU ÷ ' + fmt( water, 3 ) + ' mL = ' + fmt( conc, 2 ) + ' IU/mL' ],
						[ 'Volume per dose', 'dose (IU) ÷ concentration', fmt( dose, 2 ) + ' IU ÷ ' + fmt( conc, 2 ) + ' IU/mL = ' + fmt( vol, 3 ) + ' mL' ],
						[ 'Units to draw', 'volume (mL) × 100', fmt( vol, 3 ) + ' mL × 100 = ' + fmt( vol * 100, 1 ) + ' units' ],
					],
					tooMuch: dose > vial ? 'This dose is more than the whole vial holds (' + fmt( vial, 0 ) + ' IU).' : '',
					fix: 'add less water to concentrate the vial',
					label: [ fmt( vial, 0 ) + ' IU', 'Lyophilized', '+ ' + fmt( water, 2 ) + ' mL bac water → ' + fmt( conc, 2 ) + ' IU/mL', 'Mixed' ],
				};
			},
		},
		hormone: {
			desc: 'For ready-to-use vials like 250 mg/mL. Enter the vial’s concentration, your weekly dose, and how many injections you split it into. We’ll show each injection in mg, mL and syringe units.',
			title: 'Your vial & schedule',
			syringe: 100,
			calc: function () {
				var conc = num( 'yp-pcalc-h-conc' );
				var size = num( 'yp-pcalc-h-size' );
				var weekly = num( 'yp-pcalc-h-weekly' );
				var perWeek = Math.round( num( 'yp-pcalc-h-per-week' ) );
				if ( ! ( conc > 0 && weekly > 0 && perWeek > 0 ) ) {
					return null;
				}
				var perInj = weekly / perWeek;
				var vol = perInj / conc;
				// Vial size is only needed for "vial lasts" — the rest still
				// works without it.
				var total = size > 0 ? conc * size : NaN;
				return {
					vol: vol,
					stats: [
						[ 'Per injection', fmt( perInj, 2 ), 'mg', fmt( weekly, 2 ) + ' mg ÷ ' + perWeek + ' per week' ],
						[ 'Volume per injection', fmt( vol, 3 ), 'mL', fmt( vol * perWeek, 3 ) + ' mL per week' ],
						size > 0
							? [ 'Vial lasts', fmt( total / weekly, 1 ), 'weeks', fmt( Math.floor( total / perInj + 1e-9 ), 0 ) + ' injections · ' + fmt( total, 0 ) + ' mg total' ]
							: [ 'Vial lasts', '–', '', 'add the vial size' ],
					],
					steps: [
						[ 'Per injection', 'weekly dose ÷ injections per week', fmt( weekly, 2 ) + ' mg ÷ ' + perWeek + ' = ' + fmt( perInj, 2 ) + ' mg' ],
						[ 'Volume per injection', 'mg per injection ÷ concentration', fmt( perInj, 2 ) + ' mg ÷ ' + fmt( conc, 2 ) + ' mg/mL = ' + fmt( vol, 3 ) + ' mL' ],
						[ 'Units to draw', 'volume (mL) × 100', fmt( vol, 3 ) + ' mL × 100 = ' + fmt( vol * 100, 1 ) + ' units' ],
					],
					tooMuch: '',
					fix: 'use a 3 mL syringe and draw to the ' + fmt( vol, 2 ) + ' mL line, or split the dose into more injections per week',
					label: [ fmt( conc, 0 ) + ' mg/mL', size > 0 ? fmt( size, 2 ) + ' mL vial' : 'Multi-dose vial', size > 0 ? fmt( total, 0 ) + ' mg total · ' + fmt( size, 2 ) + ' mL' : '', 'Exp' ],
				};
			},
		},
		blend: {
			desc: 'For two or more peptides in one shot. Bought it blended? Enter the mg of each and the water. Mixing your own? We’ll show how much of each vial to combine.',
			title: 'Your blend',
			syringe: 50,
			calc: function () {
				var water = num( 'yp-pcalc-b-water' );
				if ( ! ( water > 0 ) ) {
					return null;
				}
				if ( blendKind() === 'mix' ) {
					return calcMix( water );
				}
				var list = blendParts( false );
				var unit = form.querySelector( 'input[name="bDoseUnit"]:checked' ).value;
				var dose = num( 'yp-pcalc-b-dose' );
				var doseMg = unit === 'mcg' ? dose / 1000 : dose;
				if ( ! list || ! ( doseMg > 0 ) ) {
					return null;
				}
				var totalMg = list.reduce( function ( n, x ) {
					return n + x.mg;
				}, 0 );
				var by = ofSelect.value === 'all' ? null : list[ +ofSelect.value ];
				var byMg = by ? by.mg : totalMg;
				var byName = by ? by.name : 'blend';
				var conc = byMg / water;
				var vol = doseMg / conc;
				var per = list.map( function ( x ) {
					return { name: x.name, mg: ( x.mg / water ) * vol };
				} );
				var concLine = list.map( function ( x ) {
					return fmt( x.mg / water, 2 ) + ' ' + x.name;
				} ).join( ' · ' );
				return {
					vol: vol,
					blend: 'Each dose has ' + blendLine( per, unit ) + '.',
					stats: [
						[ 'Concentration', fmt( totalMg / water, 3 ), 'mg/mL', concLine ],
						[ 'Volume per dose', fmt( vol, 3 ), 'mL', 'of ' + fmt( water, 2 ) + ' mL in the vial' ],
						[ 'Doses per vial', fmt( Math.floor( byMg / doseMg + 1e-9 ), 0 ), '', 'at ' + fmt( dose, 3 ) + ' ' + unit + ( by ? ' ' + by.name : ' of the blend' ) ],
					],
					steps: [
						[ 'Concentration', ( by ? by.name + ' (mg)' : 'all peptides (mg)' ) + ' ÷ water (mL)', fmt( byMg, 3 ) + ' mg ÷ ' + fmt( water, 3 ) + ' mL = ' + fmt( conc, 3 ) + ' mg/mL of ' + byName ],
						[ 'Volume per dose', 'dose ÷ concentration', fmt( doseMg, 4 ) + ' mg ÷ ' + fmt( conc, 3 ) + ' mg/mL = ' + fmt( vol, 3 ) + ' mL' ],
						[ 'Units to draw', 'volume (mL) × 100', fmt( vol, 3 ) + ' mL × 100 = ' + fmt( vol * 100, 1 ) + ' units' ],
					],
					tooMuch: doseMg > byMg ? 'This dose is more than the whole vial holds (' + fmt( byMg, 3 ) + ' mg' + ( by ? ' of ' + by.name : '' ) + '). Double-check the dose unit.' : '',
					fix: 'add less water to concentrate the vial',
					label: blendLabel( list, water ),
				};
			},
		},
	};

	function blendLabel( list, water ) {
		var total = list.reduce( function ( n, x ) {
			return n + ( x.used != null ? x.used : x.mg );
		}, 0 );
		return [
			list.map( function ( x ) {
				return fmt( x.used != null ? x.used : x.mg, 2 );
			} ).join( ' + ' ) + ' mg',
			'Blend',
			list.map( function ( x ) {
				return x.name;
			} ).join( ' + ' ) + ' · ' + fmt( total / water, 2 ) + ' mg/mL',
			'Mixed',
		];
	}

	function calcMix( water ) {
		var list = blendParts( true );
		if ( ! list ) {
			return null;
		}
		var unit = form.querySelector( 'input[name="bMixUnit"]:checked' ).value;
		var r = mixRecipe( list, water );
		var vol = r.total / r.doses;
		var totalMg = list.reduce( function ( n, x ) {
			return n + x.used;
		}, 0 );
		var doseMg = list.reduce( function ( n, x ) {
			return n + x.doseMg;
		}, 0 );
		return {
			vol: vol,
			blend: 'Each dose has ' + blendLine( list.map( function ( x ) {
				return { name: x.name, mg: x.doseMg };
			} ), unit ) + '.',
			recipe: r.steps,
			stats: [
				[ 'Mixed vial', fmt( r.total, 2 ), 'mL', fmt( totalMg, 2 ) + ' mg total · ' + fmt( totalMg / r.total, 3 ) + ' mg/mL' ],
				[ 'Volume per dose', fmt( vol, 3 ), 'mL', fmt( doseMg * 1000, 1 ) + ' mcg of blend per shot' ],
				[ 'Doses in the mix', fmt( Math.floor( r.doses + 1e-9 ), 0 ), '', 'uses the whole ' + r.base.name + ' vial' ],
			],
			steps: [
				[ 'Doses in the mix', 'smallest of: vial mg ÷ dose', fmt( r.base.mg, 3 ) + ' mg ÷ ' + fmt( r.base.doseMg, 4 ) + ' mg = ' + fmt( r.doses, 1 ) + ' doses (' + r.base.name + ')' ],
				[ 'Volume per dose', 'mixed mL ÷ doses', fmt( r.total, 3 ) + ' mL ÷ ' + fmt( r.doses, 1 ) + ' = ' + fmt( vol, 3 ) + ' mL' ],
				[ 'Units to draw', 'volume (mL) × 100', fmt( vol, 3 ) + ' mL × 100 = ' + fmt( vol * 100, 1 ) + ' units' ],
			],
			tooMuch: r.doses < 1 ? 'One dose needs more than a whole vial of ' + r.base.name + '. Double-check the dose unit.' : '',
			fix: 'add less water to each vial before combining',
			label: blendLabel( list, r.total ),
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

	// Text pieces with every second one bolded, as in setHint().
	function boldParts( node, parts ) {
		parts.forEach( function ( part, i ) {
			if ( i % 2 ) {
				var b = document.createElement( 'b' );
				b.textContent = part;
				node.appendChild( b );
			} else {
				node.appendChild( document.createTextNode( part ) );
			}
		} );
	}

	// Blend extras: what's in each dose, and the mixing steps.
	function showBlend( r ) {
		var line = out( 'blend-dose' );
		line.hidden = ! ( r && r.blend );
		line.textContent = r && r.blend ? r.blend : '';
		var box = out( 'recipe-box' );
		var list = out( 'recipe' );
		list.textContent = '';
		box.hidden = ! ( r && r.recipe );
		( r && r.recipe || [] ).forEach( function ( step ) {
			var li = document.createElement( 'li' );
			boldParts( li, step );
			list.appendChild( li );
		} );
	}

	function showWarning( text ) {
		var warn = out( 'warn' );
		warn.hidden = ! text;
		out( 'warn-text' ).textContent = text || '';
	}

	function update() {
		syncChips();

		var cap = parseInt( form.querySelector( 'input[name="syringe"]:checked' ).value, 10 );
		var r = MODES[ mode ].calc();
		var stats = out( 'stats' ).children;
		var steps = out( 'steps' ).children;
		var i;

		if ( ! r ) {
			out( 'units' ).textContent = '–';
			setHint( [ 'Fill in the amounts to see where to draw.' ] );
			for ( i = 0; i < 3; i++ ) {
				setValue( stats[ i ].querySelector( 'dd' ), '–' );
				stats[ i ].querySelector( '.yp-pcalc__sub' ).textContent = '';
				steps[ i ].querySelector( 'span' ).textContent = '';
			}
			showWarning( '' );
			showBlend( null );
			drawSyringe( NaN, cap, false );
			return;
		}

		var units = r.vol * 100;
		var overCap = units > cap + 1e-9;
		var step = cap === 100 ? 2 : 1;
		var mlNote = ' (' + fmt( r.vol, 3 ) + '\u00a0mL)';

		out( 'units' ).textContent = fmt( units, 1 );

		if ( overCap ) {
			setHint( [ 'More than this syringe holds' + mlNote + '.' ] );
		} else if ( Math.abs( units / step - Math.round( units / step ) ) < 0.05 ) {
			setHint( [ 'Pull the plunger to the ', fmt( Math.round( units / step ) * step, 0 ), ' mark' + mlNote + '.' ] );
		} else {
			var lo = Math.floor( units / step ) * step;
			setHint( [ 'Between the ', String( lo ), ' and ', String( lo + step ), ' marks' + mlNote + '.' ] );
		}

		for ( i = 0; i < 3; i++ ) {
			stats[ i ].querySelector( 'dt' ).textContent = r.stats[ i ][ 0 ];
			setValue( stats[ i ].querySelector( 'dd' ), r.stats[ i ][ 1 ], r.stats[ i ][ 2 ] );
			stats[ i ].querySelector( '.yp-pcalc__sub' ).textContent = r.stats[ i ][ 3 ];
			steps[ i ].querySelector( 'b' ).textContent = r.steps[ i ][ 0 ];
			steps[ i ].querySelector( 'code' ).textContent = r.steps[ i ][ 1 ];
			steps[ i ].querySelector( 'span' ).textContent = r.steps[ i ][ 2 ];
		}

		if ( r.tooMuch ) {
			showWarning( r.tooMuch );
		} else if ( overCap ) {
			var fits = [ 30, 50, 100 ].filter( function ( c ) {
				return c >= units;
			} )[ 0 ];
			showWarning( fits
				? 'This needs ' + fmt( units, 1 ) + ' units, which won’t fit a ' + SYRINGE_ML[ cap ] + ' syringe. Switch to the ' + SYRINGE_ML[ fits ] + ' syringe.'
				: 'This needs ' + fmt( r.vol, 2 ) + ' mL, more than a 1 mL insulin syringe holds. To fix it, ' + r.fix + '.' );
		} else if ( units < 2 ) {
			showWarning( 'Under 2 units is hard to measure accurately.' + ( mode === 'hormone' ? '' : ' Adding more water spreads the dose over more marks.' ) );
		} else {
			showWarning( '' );
		}

		showBlend( r );
		drawSyringe( units, cap, overCap );

		out( 'label-amt' ).textContent = r.label[ 0 ];
		out( 'label-tag' ).textContent = r.label[ 1 ];
		out( 'label-recon' ).textContent = r.label[ 2 ];
		out( 'label-line2' ).textContent = r.label[ 3 ];
	}

	function setMode( next, focusTab ) {
		mode = next;
		tabs.forEach( function ( tab ) {
			var on = tab.getAttribute( 'data-mode' ) === next;
			tab.setAttribute( 'aria-selected', String( on ) );
			tab.tabIndex = on ? 0 : -1;
			if ( on && focusTab ) {
				tab.focus();
			}
		} );
		root.querySelectorAll( '[data-yp-pcalc-group]' ).forEach( function ( group ) {
			group.hidden = group.getAttribute( 'data-yp-pcalc-group' ) !== next;
		} );
		out( 'mode-desc' ).textContent = MODES[ next ].desc;
		out( 'form-title' ).textContent = MODES[ next ].title;
		document.getElementById( 'yp-pcalc-syr-' + MODES[ next ].syringe ).checked = true;
		if ( window.history && history.replaceState ) {
			history.replaceState( null, '', '#' + next );
		}
		update();
	}

	tabs.forEach( function ( tab, i ) {
		tab.addEventListener( 'click', function () {
			setMode( tab.getAttribute( 'data-mode' ) );
		} );
		// Arrow keys move between tabs, per the ARIA tabs pattern.
		tab.addEventListener( 'keydown', function ( e ) {
			var d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
			if ( ! d ) {
				return;
			}
			e.preventDefault();
			setMode( tabs[ ( i + d + tabs.length ) % tabs.length ].getAttribute( 'data-mode' ), true );
		} );
	} );

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
			var dose = document.getElementById( 'yp-pcalc-dose' );
			var d = parseFloat( dose.value );
			if ( d > 0 ) {
				dose.value = String( Number( ( radio.value === 'mg' ? d / 1000 : d * 1000 ).toFixed( 4 ) ) );
			}
		} );
	} );

	form.querySelectorAll( 'input[name="blendKind"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', syncBlendKind );
	} );

	// Same as the peptide dose: switching mcg ↔ mg keeps each dose put.
	form.querySelectorAll( 'input[name="bDoseUnit"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			var dose = document.getElementById( 'yp-pcalc-b-dose' );
			var d = parseFloat( dose.value );
			if ( d > 0 ) {
				dose.value = String( Number( ( radio.value === 'mg' ? d / 1000 : d * 1000 ).toFixed( 4 ) ) );
			}
		} );
	} );
	form.querySelectorAll( 'input[name="bMixUnit"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			parts.forEach( function ( part ) {
				var d = parseFloat( part.dose );
				if ( d > 0 ) {
					part.dose = String( Number( ( radio.value === 'mg' ? d / 1000 : d * 1000 ).toFixed( 4 ) ) );
				}
			} );
			renderParts();
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

	var start = ( window.location.hash || '' ).slice( 1 );
	// #blend-mix (linked from the Dose Tracker) opens Blends on Mixing my own.
	if ( start === 'blend-mix' ) {
		form.querySelector( 'input[name="blendKind"][value="mix"]' ).checked = true;
		start = 'blend';
	}
	syncBlendKind();
	if ( MODES[ start ] ) {
		setMode( start );
	} else {
		update();
	}
} )();
