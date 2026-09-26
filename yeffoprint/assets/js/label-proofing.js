/**
 * Label proofing helpers shared by the Template page's configurator,
 * the custom label form and the Label Designer:
 *
 *  - Compound spell-check (direct request): compares what the customer
 *    typed against the admin's Compound List (Catalog → Compound List,
 *    YeffoPrint_Compound_List) and asks "Did you mean Semaglutide?"
 *    for a likely misspelling, or "Did you mean hGH?" for a case or
 *    punctuation slip. It only ever asks: Use swaps the text in, Keep
 *    leaves it as typed and stops asking about that spelling.
 *
 *  - Confirmation checkbox (direct request): a required "I've
 *    double-checked my label details" box with a live "We'll print"
 *    recap, the last thing before Add to Cart / Continue to Payment.
 *
 * Exposed as window.YPLabelProofing; the list arrives in
 * yeffoprintLabelProofing (functions.php).
 */
( function () {
	'use strict';

	var data = window.yeffoprintLabelProofing || { enabled: false, compounds: [] };

	/* ---------- Compound matching ---------- */

	function normalize( value ) {
		return String( value || '' ).toLowerCase().replace( /[^a-z0-9]/g, '' );
	}

	// normalized spelling → { name, alias }. A correct spelling wins over
	// someone else's "also typed as" if both normalize the same.
	var exactIndex = {};
	var names = [];

	( data.compounds || [] ).forEach( function ( row ) {
		var name = row[ 0 ];
		names.push( name );
		( row[ 1 ] || [] ).forEach( function ( alias ) {
			var key = normalize( alias );
			if ( key && ! exactIndex[ key ] ) {
				exactIndex[ key ] = { name: name, alias: true };
			}
		} );
	} );
	// Lowercased exact spelling → name, so "NAD+" and "NAD" (both "nad"
	// once normalized) each still match themselves.
	var byLowerName = {};
	names.forEach( function ( name ) {
		byLowerName[ name.toLowerCase() ] = { name: name, alias: false };
		var key = normalize( name );
		if ( key ) {
			exactIndex[ key ] = { name: name, alias: false };
		}
	} );

	var fuzzyKeys = Object.keys( exactIndex ).filter( function ( key ) {
		return key.length >= 5 && /[a-z].*[a-z].*[a-z]/.test( key );
	} );

	/** Optimal string alignment distance, giving up past `max`. */
	function distance( a, b, max ) {
		if ( Math.abs( a.length - b.length ) > max ) {
			return max + 1;
		}
		var rows = [];
		for ( var i = 0; i <= a.length; i++ ) {
			rows[ i ] = [ i ];
		}
		for ( var j = 1; j <= b.length; j++ ) {
			rows[ 0 ][ j ] = j;
		}
		for ( i = 1; i <= a.length; i++ ) {
			var rowMin = Infinity;
			for ( j = 1; j <= b.length; j++ ) {
				var cost = a[ i - 1 ] === b[ j - 1 ] ? 0 : 1;
				var value = Math.min( rows[ i - 1 ][ j ] + 1, rows[ i ][ j - 1 ] + 1, rows[ i - 1 ][ j - 1 ] + cost );
				if ( i > 1 && j > 1 && a[ i - 1 ] === b[ j - 2 ] && a[ i - 2 ] === b[ j - 1 ] ) {
					value = Math.min( value, rows[ i - 2 ][ j - 2 ] + 1 );
				}
				rows[ i ][ j ] = value;
				rowMin = Math.min( rowMin, value );
			}
			if ( rowMin > max ) {
				return max + 1;
			}
		}
		return rows[ a.length ][ b.length ];
	}

	function allowedTypos( length ) {
		if ( length < 5 ) {
			return 0;
		}
		return length < 8 ? 1 : 2;
	}

	function closest( key ) {
		var max = allowedTypos( key.length );
		var best = null;
		var bestDistance = max + 1;
		if ( ! max ) {
			return null;
		}
		fuzzyKeys.forEach( function ( candidate ) {
			// Same first letter, so "Tesamorelin" never gets "corrected" to
			// "Sermorelin"-style neighbors that differ up front.
			if ( candidate[ 0 ] !== key[ 0 ] ) {
				return;
			}
			var d = distance( key, candidate, max );
			if ( d < bestDistance ) {
				bestDistance = d;
				best = candidate;
			}
		} );
		return best && bestDistance > 0 ? exactIndex[ best ] : null;
	}

	/** Words with their positions, split on spaces and list punctuation. */
	function tokenize( text ) {
		var tokens = [];
		var re = /[^\s,;\/|&()]+/g;
		var match;
		while ( ( match = re.exec( text ) ) ) {
			// A lone "+" between names ("Ipamorelin + CJC-1295") is a
			// separator; "NAD+" keeps its plus.
			if ( ! /[a-z0-9]/i.test( match[ 0 ] ) ) {
				continue;
			}
			tokens.push( { start: match.index, end: match.index + match[ 0 ].length } );
		}
		return tokens;
	}

	function looksLikeAmount( word ) {
		return /^\d/.test( word ) || /^(mg|mcg|ug|iu|ml|g|x|vial|vials|kit|pack)$/i.test( word );
	}

	/**
	 * Names whose capitals carry meaning: a lowercase letter next to
	 * capitals inside a word (hGH, hCG, GHK-Cu, MOTS-c, CagriSema).
	 */
	function hasMeaningfulCase( name ) {
		return /[a-z][A-Z]|[A-Z]{2}[a-z]|[A-Z]-?[a-z]\b/.test( name ) && /[A-Z]/.test( name.replace( /\b[A-Z]/g, '' ) );
	}

	/**
	 * Why `typed` (which matched `name` in the list) should be asked
	 * about, or '' when it's fine as is. Plain capitalization is the
	 * label's style ("RETATRUTIDE", "semaglutide") and isn't flagged;
	 * only names like hGH whose capitals mean something are.
	 */
	function issueKind( typed, name ) {
		if ( typed === name ) {
			return '';
		}
		if ( normalize( typed ) !== normalize( name ) ) {
			return 'spelling';
		}
		if ( typed.toLowerCase() !== name.toLowerCase() ) {
			return 'format';
		}
		return hasMeaningfulCase( name ) ? 'case' : '';
	}

	/**
	 * Returns { issues: [ { start, end, typed, suggestion, kind } ],
	 * known: bool }. kind is 'case' (only capitals differ, on a name like hGH),
	 * 'format' (only dashes/spaces differ) or 'spelling'.
	 */
	function check( text ) {
		var result = { issues: [], known: false };
		if ( ! data.enabled || ! text ) {
			return result;
		}

		var tokens = tokenize( text );
		var used = [];
		var i;
		var n;

		function free( from, to ) {
			for ( var k = from; k < to; k++ ) {
				if ( used[ k ] ) {
					return false;
				}
			}
			return true;
		}

		function take( from, to ) {
			for ( var k = from; k < to; k++ ) {
				used[ k ] = true;
			}
		}

		// Exact matches first, longest phrase first, so "hGH Fragment
		// 176-191" is one name rather than hGH plus leftovers.
		for ( n = 4; n >= 1; n-- ) {
			for ( i = 0; i + n <= tokens.length; i++ ) {
				if ( ! free( i, i + n ) ) {
					continue;
				}
				var start = tokens[ i ].start;
				var end = tokens[ i + n - 1 ].end;
				var typed = text.slice( start, end );
				var hit = byLowerName[ typed.toLowerCase() ] || exactIndex[ normalize( typed ) ];
				if ( ! hit ) {
					continue;
				}
				take( i, i + n );
				result.known = true;
				var kind = issueKind( typed, hit.name );
				if ( kind ) {
					result.issues.push( { start: start, end: end, typed: typed, suggestion: hit.name, kind: kind } );
				}
			}
		}

		// Then likely misspellings: two-word phrases before single words.
		for ( n = 2; n >= 1; n-- ) {
			for ( i = 0; i + n <= tokens.length; i++ ) {
				if ( ! free( i, i + n ) ) {
					continue;
				}
				var s = tokens[ i ].start;
				var e = tokens[ i + n - 1 ].end;
				var words = text.slice( s, e );
				if ( looksLikeAmount( text.slice( tokens[ i ].start, tokens[ i ].end ) ) || looksLikeAmount( text.slice( tokens[ i + n - 1 ].start, e ) ) ) {
					continue;
				}
				var guess = closest( normalize( words ) );
				if ( guess ) {
					take( i, i + n );
					result.issues.push( { start: s, end: e, typed: words, suggestion: guess.name, kind: 'spelling' } );
				}
			}
		}

		result.issues.sort( function ( a, b ) {
			return a.start - b.start;
		} );
		return result;
	}

	/* ---------- Spell-check UI ---------- */

	var kept = {};

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}

	function reason( issue ) {
		if ( 'spelling' === issue.kind ) {
			return 'That’s close to a name in our compound list.';
		}
		if ( /^[a-z]/.test( issue.suggestion ) && issue.suggestion.toLowerCase() !== issue.suggestion ) {
			return issue.suggestion + ' is usually written with a lowercase ' + issue.suggestion[ 0 ] + '.';
		}
		return 'That’s how ' + issue.suggestion + ' is usually written.';
	}

	/**
	 * Watches one text input. `options.anchor` is where the suggestion
	 * box goes (appended to it; defaults to the input's parent).
	 * Returns { refresh() } for callers that set input.value in code
	 * (e.g. switching batch labels), which fires no input event.
	 */
	function attachSpellCheck( input, options ) {
		options = options || {};
		if ( ! data.enabled || ! input ) {
			return { refresh: function () {} };
		}

		var anchor = options.anchor || input.parentNode;
		var box = document.createElement( 'div' );
		box.className = 'yp-compound-check';
		box.setAttribute( 'aria-live', 'polite' );
		box.hidden = true;
		anchor.appendChild( box );

		var timer = null;
		var lastKept = '';

		function render() {
			var value = input.value;
			var result = check( value );
			var issue = result.issues.filter( function ( item ) {
				return ! kept[ item.typed + '→' + item.suggestion ];
			} )[ 0 ];

			input.classList.toggle( 'yp-compound-check__input--ask', !! issue );

			if ( issue ) {
				box.hidden = false;
				box.className = 'yp-compound-check is-asking';
				box.innerHTML =
					'<div class="yp-compound-check__q"><span class="yp-compound-check__icon" aria-hidden="true">?</span>' +
						'<div>Did you mean <strong>' + escapeHtml( issue.suggestion ) + '</strong>?' +
						'<span class="yp-compound-check__why">' + escapeHtml( reason( issue ) ) + '</span></div></div>' +
					'<div class="yp-compound-check__actions">' +
						'<button type="button" class="yp-compound-check__use" data-use>Use ' + escapeHtml( issue.suggestion ) + '</button>' +
						'<button type="button" class="yp-compound-check__keep" data-keep>Keep “' + escapeHtml( issue.typed ) + '”</button>' +
					'</div>';

				box.querySelector( '[data-use]' ).addEventListener( 'click', function () {
					input.value = value.slice( 0, issue.start ) + issue.suggestion + value.slice( issue.end );
					input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					render();
					input.focus();
				} );
				box.querySelector( '[data-keep]' ).addEventListener( 'click', function () {
					kept[ issue.typed + '→' + issue.suggestion ] = true;
					lastKept = value;
					render();
				} );
				return;
			}

			if ( result.known && ! result.issues.length ) {
				box.hidden = false;
				box.className = 'yp-compound-check is-good';
				box.textContent = '✓ Matches our compound list';
			} else if ( value && value === lastKept ) {
				box.hidden = false;
				box.className = 'yp-compound-check is-kept';
				box.textContent = 'Got it. We’ll print it exactly as you typed it.';
			} else {
				box.hidden = true;
				box.className = 'yp-compound-check';
				box.innerHTML = '';
			}
		}

		// Checked when the customer leaves the field or pauses typing, not
		// on every keystroke, so a half-typed name isn't flagged.
		input.addEventListener( 'blur', function () {
			clearTimeout( timer );
			render();
		} );
		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			if ( ! box.hidden && ! box.classList.contains( 'is-asking' ) ) {
				box.hidden = true;
			}
			timer = setTimeout( render, 1200 );
		} );

		if ( input.value ) {
			render();
		}

		return { refresh: render };
	}

	/* ---------- Confirmation checkbox ---------- */

	/**
	 * Renders the required confirmation into `container`.
	 *
	 * options: text (the fine print), getRecap() → array of lines (plain
	 * text) for the "We'll print" box, watch (element whose input/click
	 * events refresh the recap), buttons (NodeList/array of submit
	 * buttons, dimmed until ticked), actionLabel (for the error line).
	 *
	 * Returns { isConfirmed(), require() → bool (shows the error and
	 * scrolls to the box when not ticked), refresh(), reset() }.
	 */
	function mountConfirm( container, options ) {
		var id = 'yp-proof-confirm-' + Math.random().toString( 36 ).slice( 2, 8 );
		container.classList.add( 'yp-proof-confirm' );
		container.innerHTML =
			'<div class="yp-proof-confirm__recap" data-recap hidden>' +
				'<span class="yp-proof-confirm__recap-label">We’ll print</span>' +
				'<div class="yp-proof-confirm__recap-lines" data-recap-lines></div>' +
			'</div>' +
			'<label class="yp-proof-confirm__check" for="' + id + '">' +
				'<input type="checkbox" id="' + id + '" />' +
				'<span><strong>I’ve double-checked my label details</strong>' +
				'<span class="yp-proof-confirm__text">' + escapeHtml( options.text ) + '</span></span>' +
			'</label>' +
			'<p class="yp-proof-confirm__error" id="' + id + '-error" role="alert" hidden>Please check this box to confirm your details before ' + escapeHtml( options.actionLabel || 'continuing' ) + '.</p>';

		var checkbox = container.querySelector( 'input[type="checkbox"]' );
		var errorEl = container.querySelector( '.yp-proof-confirm__error' );
		var recapEl = container.querySelector( '[data-recap]' );
		var recapLinesEl = container.querySelector( '[data-recap-lines]' );
		var buttons = Array.prototype.slice.call( options.buttons || [] );
		var pending = false;

		function syncButtons() {
			buttons.forEach( function ( button ) {
				button.classList.toggle( 'yp-needs-confirm', ! checkbox.checked );
			} );
		}

		function refresh() {
			pending = false;
			var lines = options.getRecap ? options.getRecap() : [];
			lines = ( lines || [] ).filter( function ( line ) {
				return line && String( line ).trim();
			} );
			recapEl.hidden = ! lines.length;
			recapLinesEl.innerHTML = lines.map( function ( line ) {
				return '<div>' + escapeHtml( line ) + '</div>';
			} ).join( '' );
		}

		function scheduleRefresh() {
			if ( ! pending ) {
				pending = true;
				window.requestAnimationFrame( refresh );
			}
		}

		checkbox.addEventListener( 'change', function () {
			container.classList.toggle( 'is-checked', checkbox.checked );
			if ( checkbox.checked ) {
				container.classList.remove( 'is-error' );
				errorEl.hidden = true;
			}
			syncButtons();
		} );

		if ( options.watch ) {
			options.watch.addEventListener( 'input', scheduleRefresh );
			options.watch.addEventListener( 'click', scheduleRefresh );
			options.watch.addEventListener( 'change', scheduleRefresh );
		}

		syncButtons();
		refresh();

		return {
			isConfirmed: function () {
				return checkbox.checked;
			},
			require: function () {
				if ( checkbox.checked ) {
					return true;
				}
				refresh();
				container.classList.add( 'is-error' );
				errorEl.hidden = false;
				container.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				checkbox.focus( { preventScroll: true } );
				return false;
			},
			refresh: refresh,
			reset: function () {
				checkbox.checked = false;
				container.classList.remove( 'is-checked', 'is-error' );
				errorEl.hidden = true;
				syncButtons();
			}
		};
	}

	window.YPLabelProofing = {
		check: check,
		attachSpellCheck: attachSpellCheck,
		mountConfirm: mountConfirm,
		TEMPLATE_TEXT: 'I confirm the compound name, strength, batch number, dates and all other details are correct and spelled exactly as I want them printed. I understand YeffoDesign reviews every order and does its best to catch mistakes like misspellings, but my label prints exactly as I entered it.',
		CUSTOM_TEXT: 'I confirm the brand name, product details and everything else I entered are correct and spelled exactly as I want them printed. I understand YeffoDesign reviews every order and does its best to catch mistakes like misspellings, but the final wording is my responsibility.'
	};
} )();
