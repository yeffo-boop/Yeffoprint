/**
 * Production view (`#/design-sheet/{id}`): one order's labels to keep
 * open beside the design software (direct request: on a 35-line order
 * the order page made Jeff open every label's Details one by one).
 *
 * Labels that share a template/line name, size and material are one
 * batch. Whatever is the same on every label in a batch is listed once
 * under "Set up once"; what changes from label to label (usually
 * compound and strength) becomes the short list beside it, and a value
 * only one or two labels have ("Exp Mar 2029", a design note) shows on
 * those labels' rows. Click any value to copy it.
 *
 * Each label's "designed" check is saved on the order
 * (/admin/order/{id}/designed, class-order-design-sheet.php), so it
 * survives closing the window and shows on any computer or phone.
 *
 * The order page's "Production view" button opens this in its own
 * narrow window; app.js hides the app header here (`.ypn-bare`).
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP || typeof yeffoprintAdminApp === 'undefined' || 'next' !== yeffoprintAdminApp.shell ) {
		return;
	}

	var api = function ( path ) { return yeffoprintAdminApp.restUrl + path; };
	var esc = YP.escapeHtml;
	var escAttr = YP.escapeAttr;

	var HIDE_KEY = 'ypnSheetHideDone';
	var NOTE_RE = /note|instruction|direction|comment|request/i;

	function storedHide() {
		try {
			var v = window.localStorage.getItem( HIDE_KEY );
			if ( null !== v ) {
				return '1' === v;
			}
		} catch ( e ) {}
		return window.innerWidth < 700;
	}

	function storeHide( on ) {
		try { window.localStorage.setItem( HIDE_KEY, on ? '1' : '0' ); } catch ( e ) {}
	}

	/** Opens the production view in its own narrow window (falls back to this tab). */
	YP.openDesignSheet = function ( orderId ) {
		var hash = '#/design-sheet/' + parseInt( orderId, 10 );
		if ( window.innerWidth >= 900 ) {
			var url = window.location.href.split( '#' )[ 0 ] + hash;
			var height = Math.min( 1000, ( window.screen && window.screen.availHeight ) || 900 );
			var win = window.open( url, 'ypn-sheet-' + orderId, 'popup,width=560,height=' + height );
			if ( win ) {
				win.focus();
				return;
			}
		}
		window.location.hash = hash;
	};

	/* ---------- Batching ---------- */

	function groupLabels( labels ) {
		var groups = [];
		var byKey = {};
		labels.forEach( function ( label, i ) {
			label.n = i + 1;
			var key = [ label.name, label.size, label.material ].join( '|' );
			if ( ! byKey[ key ] ) {
				byKey[ key ] = { key: key, name: label.name, size: label.size, material: label.material, labels: [] };
				groups.push( byKey[ key ] );
			}
			byKey[ key ].labels.push( label );
		} );
		groups.forEach( analyze );
		return groups;
	}

	function fieldOf( label, name ) {
		for ( var i = 0; i < label.fields.length; i++ ) {
			if ( label.fields[ i ].label === name ) {
				return label.fields[ i ];
			}
		}
		return null;
	}

	/**
	 * Sorts a batch's fields into setup (same on every label, or on all
	 * but a few), columns (change from label to label) and notes.
	 */
	function analyze( group ) {
		var names = [];
		var types = {};
		group.labels.forEach( function ( label ) {
			// The line's brand counts as a field unless the template already has one.
			if ( label.brand && ! label.fields.some( function ( f ) { return /brand/i.test( f.label ); } ) ) {
				label.fields = [ { label: 'Brand', type: 'text', value: '', display: label.brand } ].concat( label.fields );
			}
			label.fields.forEach( function ( f ) {
				if ( names.indexOf( f.label ) === -1 ) {
					names.push( f.label );
					types[ f.label ] = f.type;
				}
			} );
			label.exceptions = [];
		} );

		var n = group.labels.length;
		group.setup = [];
		group.columns = [];

		names.forEach( function ( name ) {
			var values = group.labels.map( function ( label ) {
				var f = fieldOf( label, name );
				return f ? f.display : '';
			} );
			var counts = {};
			var mode = '';
			var best = 0;
			values.forEach( function ( v ) {
				counts[ v ] = ( counts[ v ] || 0 ) + 1;
				if ( counts[ v ] > best ) {
					best = counts[ v ];
					mode = v;
				}
			} );
			var sample = fieldOf( group.labels[ values.indexOf( mode ) ], name ) || {};

			if ( best === n ) {
				if ( '' !== mode ) {
					group.setup.push( { label: name, display: mode, value: sample.value || '' } );
				}
				return;
			}

			var isNote = 'textarea' === types[ name ] || NOTE_RE.test( name );
			var fewDiffer = best >= 2 && ( n - best ) <= Math.max( 1, Math.floor( n / 4 ) );
			if ( isNote || fewDiffer ) {
				if ( '' !== mode && ! isNote ) {
					group.setup.push( { label: name, display: mode, value: sample.value || '' } );
				}
				group.labels.forEach( function ( label, i ) {
					if ( values[ i ] === mode && ! ( isNote && '' !== mode ) ) {
						return;
					}
					var f = fieldOf( label, name ) || {};
					label.exceptions.push( {
						note: isNote,
						label: name,
						display: values[ i ] || 'none',
						value: f.value || ''
					} );
				} );
				return;
			}

			group.columns.push( { label: name, type: types[ name ] } );
		} );

		var qty = group.labels.map( function ( l ) { return l.quantity; } );
		group.sameQty = qty.every( function ( q ) { return q === qty[ 0 ]; } ) ? qty[ 0 ] : null;
		group.pieces = qty.reduce( function ( a, b ) { return a + b; }, 0 );
		group.image = group.labels[ 0 ].image_url;
	}

	/* ---------- Drawing ---------- */

	function swatch( hex ) {
		return /^#[0-9a-f]{3,8}$/i.test( hex || '' ) ? '<i class="ypn-ds__sw" style="background:' + escAttr( hex ) + '"></i>' : '';
	}

	function copyable( text, extraClass ) {
		if ( '' === text || null === text || undefined === text ) {
			return '<span class="ypn-ds__none">—</span>';
		}
		return '<button type="button" class="ypn-ds__cp' + ( extraClass ? ' ' + extraClass : '' ) + '" data-copy="' + escAttr( text ) + '" title="Copy">' + esc( text ) + '</button>';
	}

	/** A small label drawn from the batch's colors, when the line has no picture. */
	function previewHtml( group ) {
		if ( group.image ) {
			return '<img src="' + escAttr( group.image ) + '" alt="" loading="lazy">';
		}
		var bg = '';
		var fg = '';
		group.setup.forEach( function ( s ) {
			if ( /^#/.test( s.value ) && /background|bg/i.test( s.label ) ) {
				bg = s.value;
			} else if ( /^#/.test( s.value ) && /text|font|ink/i.test( s.label ) ) {
				fg = s.value;
			}
		} );
		if ( ! bg && ! fg ) {
			return '';
		}
		var first = group.labels[ 0 ];
		var text = group.columns.slice( 0, 2 ).map( function ( c ) {
			var f = fieldOf( first, c.label );
			return f ? f.display : '';
		} ).filter( Boolean );
		var round = /circle|round/i.test( group.size + ' ' + group.name );
		return '<div class="ypn-ds__mini' + ( round ? ' is-round' : '' ) + '" style="background:' + escAttr( bg || '#fff' ) + ';color:' + escAttr( fg || '#141414' ) + '">' +
			( text[ 0 ] ? '<b>' + esc( text[ 0 ] ) + '</b>' : '' ) + ( text[ 1 ] ? '<span>' + esc( text[ 1 ] ) + '</span>' : '' ) +
		'</div>';
	}

	function rowHtml( group, label, state ) {
		var done = !! state.designed[ label.key ];
		var cls = 'ypn-ds__row' + ( done ? ' is-done' : '' ) + ( label.key === state.nextKey ? ' is-next' : '' );
		var cells = group.columns.map( function ( c ) {
			var f = fieldOf( label, c.label ) || {};
			return '<span class="ypn-ds__cell" data-col="' + escAttr( c.label ) + '">' + swatch( f.value ) + copyable( f.display ) + '</span>';
		} ).join( '' );
		if ( ! group.columns.length ) {
			cells = '<span class="ypn-ds__cell">Label ' + ( group.labels.indexOf( label ) + 1 ) + '</span>';
		}
		var ex = label.exceptions.map( function ( e ) {
			return e.note
				? '<span class="ypn-ds__note"><b>' + esc( e.label ) + ':</b> ' + esc( e.display ) + '</span>'
				: '<span class="ypn-ds__diff">' + swatch( e.value ) + esc( e.label ) + ': ' + esc( e.display ) + '</span>';
		} ).join( '' );
		return '<div class="' + cls + '" data-key="' + escAttr( label.key ) + '">' +
			'<button type="button" class="ypn-ds__ck" role="checkbox" aria-checked="' + ( done ? 'true' : 'false' ) + '" aria-label="Designed" data-ypn-ds-check="' + escAttr( label.key ) + '"></button>' +
			'<span class="ypn-ds__n">' + label.n + '</span>' +
			'<span class="ypn-ds__cells">' + cells + '</span>' +
			'<span class="ypn-ds__q">' + Number( label.quantity ).toLocaleString() + '</span>' +
			( ex ? '<span class="ypn-ds__ex">' + ex + '</span>' : '' ) +
		'</div>';
	}

	function groupHtml( group, index, state ) {
		var done = group.labels.filter( function ( l ) { return state.designed[ l.key ]; } ).length;
		var all = done === group.labels.length;
		var open = state.open[ group.key ];
		var collapsed = all && ! open;
		var hiddenDone = state.hideDone && ! all ? done : 0;
		var rows = group.labels.filter( function ( l ) { return ! ( state.hideDone && ! all && state.designed[ l.key ] ); } );
		var preview = previewHtml( group );
		var cols = group.columns.length ? group.columns.map( function ( c ) { return '<span>' + esc( c.label ) + '</span>'; } ).join( '' ) : '<span>Label</span>';

		return '<section class="ypn-ds__batch' + ( all ? ' is-done' : '' ) + '" data-group="' + escAttr( group.key ) + '">' +
			'<header class="ypn-ds__bh">' +
				'<div class="ypn-ds__bt"><b>' + ( index + 1 ) + '. ' + esc( group.name ) + '</b>' +
					'<span>' + group.labels.length + ( 1 === group.labels.length ? ' label' : ' labels' ) + ' · ' + group.pieces.toLocaleString() + ' pcs</span>' +
					'<span class="ypn-ds__mbar"><i style="width:' + ( done / group.labels.length * 100 ) + '%"></i></span><span>' + done + '/' + group.labels.length + '</span>' +
				'</div>' +
				'<div class="ypn-ds__btools">' +
					( collapsed
						? '<button type="button" class="ypn-btn" data-ypn-ds-open>All designed ✓ · Show</button>'
						: '<button type="button" class="ypn-btn" data-ypn-ds-copylist>Copy list</button>' +
							'<button type="button" class="ypn-btn ypn-ds__wide" data-ypn-ds-csv>CSV</button>' +
							( all ? '' : '<button type="button" class="ypn-btn ypn-ds__wide" data-ypn-ds-all>Check all</button>' ) ) +
				'</div>' +
			'</header>' +
			( collapsed ? '' :
				'<div class="ypn-ds__bb">' +
					'<div class="ypn-ds__setup"><h4>Set up once</h4>' +
						( preview ? '<div class="ypn-ds__pv">' + preview + '</div>' : '' ) +
						'<dl>' +
							( group.size ? '<dt>Size</dt><dd>' + copyable( group.size ) + '</dd>' : '' ) +
							( group.material ? '<dt>Material</dt><dd>' + copyable( group.material ) + '</dd>' : '' ) +
							group.setup.map( function ( s ) {
								return '<dt>' + esc( s.label ) + '</dt><dd>' + swatch( s.value ) + copyable( s.display ) + '</dd>';
							} ).join( '' ) +
							( null !== group.sameQty ? '<dt>Qty each</dt><dd>' + Number( group.sameQty ).toLocaleString() + '</dd>' : '' ) +
						'</dl>' +
					'</div>' +
					'<div class="ypn-ds__rows" style="--cols:' + Math.max( 1, group.columns.length ) + '">' +
						'<div class="ypn-ds__row ypn-ds__head"><span></span><span>#</span><span class="ypn-ds__cells">' + cols + '</span><span class="ypn-ds__q">Qty</span></div>' +
						( hiddenDone ? '<button type="button" class="ypn-ds__donebar" data-ypn-ds-showdone><i class="ypn-ds__ck" aria-hidden="true"></i>' + hiddenDone + ' designed · show</button>' : '' ) +
						rows.map( function ( l ) { return rowHtml( group, l, state ); } ).join( '' ) +
					'</div>' +
				'</div>' ) +
		'</section>';
	}

	function otherItemsHtml( items ) {
		if ( ! items.length ) {
			return '';
		}
		return '<section class="ypn-ds__batch ypn-ds__other"><header class="ypn-ds__bh"><div class="ypn-ds__bt"><b>Other items on this order</b></div></header>' +
			'<div class="ypn-ds__olist">' + items.map( function ( item ) {
				return '<div class="ypn-ds__oi"><b>' + esc( item.name ) + '</b> <span>× ' + Number( item.quantity ).toLocaleString() + '</span>' +
					( item.details.length ? '<dl>' + item.details.map( function ( d ) {
						return '<dt>' + esc( d.label ) + '</dt><dd>' + copyable( d.value ) + '</dd>';
					} ).join( '' ) + '</dl>' : '' ) +
				'</div>';
			} ).join( '' ) + '</div></section>';
	}

	/* ---------- View ---------- */

	YP.views[ 'design-sheet' ] = function ( viewEl, subId ) {
		var id = parseInt( subId, 10 );
		if ( ! id ) {
			window.location.hash = '#/production';
			return;
		}

		var state = { order: null, groups: [], other: [], designed: {}, hideDone: storedHide(), open: {}, nextKey: null };
		var saving = Promise.resolve();
		var toastTimer;
		var lastChecked = [];

		viewEl.innerHTML = '<div class="ypn-ds"><p class="yp-field__hint" style="padding:20px">Loading labels&hellip;</p></div>';
		document.title = 'Production view';

		function load( quiet ) {
			return Promise.all( [
				YP.request( api( 'admin/order/' + id ) ),
				YP.request( api( 'admin/order/' + id + '/design-sheet' ) )
			] ).then( function ( results ) {
				state.order = results[ 0 ];
				state.groups = groupLabels( results[ 1 ].labels || [] );
				state.other = results[ 1 ].other_items || [];
				state.designed = results[ 1 ].designed || {};
				draw();
			} ).catch( function ( error ) {
				if ( quiet ) {
					return;
				}
				viewEl.innerHTML = '<div class="ypn-ds"><p class="yp-form__error" style="margin:20px">Couldn’t load this order’s labels: ' + esc( error.message ) + '</p></div>';
			} );
		}

		function allLabels() {
			return state.groups.reduce( function ( a, g ) { return a.concat( g.labels ); }, [] );
		}

		function draw() {
			if ( ! document.body.contains( viewEl ) ) {
				return;
			}
			var order = state.order;
			var labels = allLabels();
			var done = labels.filter( function ( l ) { return state.designed[ l.key ]; } ).length;
			var next = labels.filter( function ( l ) { return ! state.designed[ l.key ]; } )[ 0 ];
			state.nextKey = next ? next.key : null;
			var number = ( /^\d+$/.test( String( order.number ) ) ? '#' : '' ) + order.number;
			document.title = number + ' · ' + done + '/' + labels.length + ' designed';

			var promise = order.promise && order.promise.ship_label ? 'Ship ' + order.promise.ship_label : '';
			var scrollY = window.scrollY;

			viewEl.innerHTML =
				'<div class="ypn-ds">' +
					'<div class="ypn-ds__top">' +
						'<div class="ypn-ds__id">' +
							'<a href="#/order/' + order.id + '" data-ypn-ds-order>' + esc( number ) + '</a>' +
							( order.express ? '<span class="ypn-tag-express">EXPRESS</span>' : '' ) +
							'<span class="ypn-ds__who">' + esc( order.customer_name || order.customer_email || 'Guest' ) + '</span>' +
							'<span class="ypn-ds__meta">' + [ order.label_summary, promise ].filter( Boolean ).map( esc ).join( ' · ' ) + '</span>' +
						'</div>' +
						'<div class="ypn-ds__prog">' +
							'<span><b>' + done + ' of ' + labels.length + '</b> designed</span>' +
							'<span class="ypn-ds__bar"><i style="width:' + ( labels.length ? done / labels.length * 100 : 0 ) + '%"></i></span>' +
							'<label class="ypn-ds__hide"><input type="checkbox" data-ypn-ds-hide' + ( state.hideDone ? ' checked' : '' ) + '> Hide designed</label>' +
						'</div>' +
					'</div>' +
					'<div class="ypn-ds__body">' +
						( labels.length
							? ( labels.length === done ? '<div class="ypn-ds__alldone">All ' + labels.length + ' labels designed. Nice work.</div>' : '' ) +
								state.groups.map( function ( g, i ) { return groupHtml( g, i, state ); } ).join( '' )
							: '<p class="yp-field__hint">This order has no label text to design. Open the order for its artwork and details.</p>' ) +
						otherItemsHtml( state.other ) +
					'</div>' +
				'</div>' +
				'<div class="ypn-ds__toast" data-ypn-ds-toast hidden></div>';
			window.scrollTo( 0, scrollY );
		}

		function toast( html, ms ) {
			var el = viewEl.querySelector( '[data-ypn-ds-toast]' );
			if ( ! el ) {
				return;
			}
			el.innerHTML = html;
			el.hidden = false;
			clearTimeout( toastTimer );
			toastTimer = setTimeout( function () { el.hidden = true; }, ms || 1800 );
		}

		function copy( text ) {
			var done = function () { toast( 'Copied <b>' + esc( text.length > 40 ? text.slice( 0, 40 ) + '…' : text ) + '</b>' ); };
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done, function () { fallbackCopy( text ); done(); } );
			} else {
				fallbackCopy( text );
				done();
			}
		}

		function fallbackCopy( text ) {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			try { document.execCommand( 'copy' ); } catch ( e ) {}
			document.body.removeChild( ta );
		}

		// Saves one at a time, in click order, so quick clicks can't overwrite each other.
		function setDesigned( keys, done ) {
			var before = {};
			keys.forEach( function ( k ) {
				before[ k ] = state.designed[ k ];
				if ( done ) {
					state.designed[ k ] = state.designed[ k ] || Math.floor( Date.now() / 1000 );
				} else {
					delete state.designed[ k ];
				}
			} );
			draw();
			saving = saving.then( function () {
				return YP.request( api( 'admin/order/' + id + '/designed' ), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { keys: keys, done: done } )
				} );
			} ).then( function ( res ) {
				if ( res && res.designed ) {
					state.designed = res.designed;
				}
			} ).catch( function ( error ) {
				keys.forEach( function ( k ) {
					if ( before[ k ] ) {
						state.designed[ k ] = before[ k ];
					} else {
						delete state.designed[ k ];
					}
				} );
				draw();
				toast( 'Couldn’t save: ' + esc( error.message ) );
			} );
		}

		function groupOf( el ) {
			var box = el.closest( '[data-group]' );
			var key = box && box.getAttribute( 'data-group' );
			return state.groups.filter( function ( g ) { return g.key === key; } )[ 0 ];
		}

		function groupRows( group ) {
			return group.labels.map( function ( label ) {
				var cols = group.columns.length ? group.columns.map( function ( c ) {
					var f = fieldOf( label, c.label );
					return f ? f.display : '';
				} ) : [ 'Label ' + ( group.labels.indexOf( label ) + 1 ) ];
				return cols;
			} );
		}

		function downloadCsv( group ) {
			var names = [];
			group.labels.forEach( function ( l ) {
				l.fields.forEach( function ( f ) {
					if ( names.indexOf( f.label ) === -1 ) {
						names.push( f.label );
					}
				} );
			} );
			var cell = function ( v ) {
				v = String( null === v || undefined === v ? '' : v );
				return /[",\n]/.test( v ) ? '"' + v.replace( /"/g, '""' ) + '"' : v;
			};
			var lines = [ [ '#', 'Size', 'Material' ].concat( names, [ 'Quantity', 'Designed' ] ).map( cell ).join( ',' ) ];
			group.labels.forEach( function ( l ) {
				lines.push( [ l.n, l.size, l.material ].concat( names.map( function ( name ) {
					var f = fieldOf( l, name );
					return f ? f.display : '';
				} ), [ l.quantity, state.designed[ l.key ] ? 'yes' : '' ] ).map( cell ).join( ',' ) );
			} );
			var blob = new Blob( [ '﻿' + lines.join( '\r\n' ) ], { type: 'text/csv' } );
			var a = document.createElement( 'a' );
			a.href = URL.createObjectURL( blob );
			a.download = 'order-' + state.order.number + '-' + group.name.replace( /[^a-z0-9]+/gi, '-' ).toLowerCase() + '.csv';
			document.body.appendChild( a );
			a.click();
			setTimeout( function () { URL.revokeObjectURL( a.href ); a.remove(); }, 500 );
		}

		viewEl.addEventListener( 'click', function ( e ) {
			var t = e.target;
			var cp = t.closest( '[data-copy]' );
			if ( cp ) {
				copy( cp.getAttribute( 'data-copy' ) );
				return;
			}
			var ck = t.closest( '[data-ypn-ds-check]' );
			if ( ck ) {
				var key = ck.getAttribute( 'data-ypn-ds-check' );
				var checking = ! state.designed[ key ];
				setDesigned( [ key ], checking );
				if ( checking && state.hideDone ) {
					// The row hides once checked; a slip is one tap to undo.
					lastChecked = [ key ];
					toast( 'Marked designed <button type="button" class="ypn-ds__undo" data-ypn-ds-undo>Undo</button>', 5000 );
				}
				return;
			}
			if ( t.closest( '[data-ypn-ds-undo]' ) ) {
				if ( lastChecked.length ) {
					setDesigned( lastChecked, false );
					lastChecked = [];
				}
				var toastEl = viewEl.querySelector( '[data-ypn-ds-toast]' );
				if ( toastEl ) {
					toastEl.hidden = true;
				}
				return;
			}
			var group;
			if ( t.closest( '[data-ypn-ds-all]' ) && ( group = groupOf( t ) ) ) {
				setDesigned( group.labels.map( function ( l ) { return l.key; } ).filter( function ( k ) { return ! state.designed[ k ]; } ), true );
				return;
			}
			if ( t.closest( '[data-ypn-ds-copylist]' ) && ( group = groupOf( t ) ) ) {
				copy( groupRows( group ).map( function ( r ) { return r.join( '\t' ); } ).join( '\n' ) );
				return;
			}
			if ( t.closest( '[data-ypn-ds-csv]' ) && ( group = groupOf( t ) ) ) {
				downloadCsv( group );
				return;
			}
			if ( t.closest( '[data-ypn-ds-open]' ) && ( group = groupOf( t ) ) ) {
				state.open[ group.key ] = true;
				draw();
				return;
			}
			if ( t.closest( '[data-ypn-ds-showdone]' ) ) {
				state.hideDone = false;
				storeHide( false );
				draw();
				return;
			}
			var orderLink = t.closest( '[data-ypn-ds-order]' );
			if ( orderLink && window.opener && ! window.opener.closed ) {
				// Opened from the order page: show the order there, keep this window as it is.
				try {
					window.opener.location.hash = '#/order/' + id;
					window.opener.focus();
					e.preventDefault();
				} catch ( err ) {}
			}
		} );

		viewEl.addEventListener( 'change', function ( e ) {
			if ( e.target.matches( '[data-ypn-ds-hide]' ) ) {
				state.hideDone = e.target.checked;
				storeHide( state.hideDone );
				draw();
			}
		} );

		// Back from the design app: pick up checks made on another device.
		var lastLoad = Date.now();
		function onFocus() {
			if ( ! document.body.contains( viewEl ) ) {
				window.removeEventListener( 'focus', onFocus );
				return;
			}
			if ( Date.now() - lastLoad < 15000 ) {
				return;
			}
			lastLoad = Date.now();
			saving.then( function () { load( true ); } );
		}
		window.addEventListener( 'focus', onFocus );

		load();
	};
}() );
