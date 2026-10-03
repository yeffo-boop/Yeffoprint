/**
 * The redesigned admin app's own screens ("YeffoDesign (new)",
 * class-admin-app.php). app.js builds this page's shell (top tabs, phone
 * tab bar, sub-tabs) and still runs every existing screen and the order
 * window; this file adds what is new:
 *
 * - Today's revenue/order tiles above the existing dashboard panels.
 * - Orders → Production board (`#/production`, `#/production/{id}`
 *   opens that order): every open order in a column by stage. Dragging
 *   a paid card onto In production (or its Send to printer button)
 *   uses the same send-to-printer endpoint as the dashboard.
 * - Catalog, Customers and Settings overview hubs, with the Settings
 *   hub's quick switches and this phone's install + alerts setup.
 * - A progress tracker and quick actions at the top of the order window.
 *
 * Data: /admin/next/* (class-admin-next-controller.php) plus the
 * endpoints the existing screens already use.
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
	YP.next = YP.next || {};

	function money( value, symbol ) {
		return ( symbol || '$' ) + Number( value || 0 ).toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	function ago( iso ) {
		if ( ! iso ) {
			return '';
		}
		var minutes = Math.max( 0, Math.round( ( Date.now() - new Date( iso ).getTime() ) / 60000 ) );
		if ( minutes < 60 ) {
			return minutes + 'm';
		}
		var hours = Math.round( minutes / 60 );
		return hours < 48 ? hours + 'h' : Math.round( hours / 24 ) + 'd';
	}

	function isStandalone() {
		return window.navigator.standalone === true || ( window.matchMedia && window.matchMedia( '(display-mode: standalone)' ).matches );
	}

	function isIos() {
		return /iphone|ipad|ipod/i.test( window.navigator.userAgent ) || ( 'MacIntel' === window.navigator.platform && window.navigator.maxTouchPoints > 1 );
	}

	// Lets the board refresh after the order window closes (a status,
	// payment or label may have changed in it).
	var closeDrawer = YP.closeDrawer;
	YP.closeDrawer = function ( drawerEl ) {
		closeDrawer( drawerEl );
		document.dispatchEvent( new CustomEvent( 'ypn:drawer-closed' ) );
	};

	// The service worker only shows push alerts (next/sw.js); registering
	// it early means alerts keep working without reopening Settings.
	if ( 'serviceWorker' in navigator && yeffoprintAdminApp.swUrl ) {
		navigator.serviceWorker.register( yeffoprintAdminApp.swUrl, { scope: yeffoprintAdminApp.swScope } ).catch( function () {} );
	}

	/* ---------- Today ---------- */

	YP.next.today = function ( el ) {
		el.innerHTML = '<div class="ypn-kpis"><div class="ypn-card ypn-kpi ypn-kpi--main"><span class="ypn-kpi__label">Revenue today</span><span class="ypn-kpi__value">&hellip;</span></div></div>';

		if ( ! isStandalone() && isIos() ) {
			el.insertAdjacentHTML( 'afterbegin',
				'<a class="ypn-banner" href="#/store">' +
					'<b>Put YeffoDesign on your Home Screen</b>' +
					'<span>Get an alert on your phone for every new order. Set it up in Settings.</span>' +
				'</a>'
			);
		}

		el.insertAdjacentHTML( 'beforeend',
			'<div class="ypn-today">' +
				'<section class="ypn-card ypn-queue"><h3 class="ypn-card__title">Your queue <span data-ypn-queue-count></span></h3><div data-ypn-queue><p class="yp-field__hint">Loading&hellip;</p></div></section>' +
				'<section class="ypn-card ypn-ship"><h3 class="ypn-card__title">Ship today <span data-ypn-ship-count></span></h3><div data-ypn-ship><p class="yp-field__hint">Loading&hellip;</p></div></section>' +
			'</div>'
		);
		todayEl = el;
		todayBoard = null;
		todayProblems = [];
		loadToday();

		YP.request( api( 'admin/next/stats' ) ).then( function ( stats ) {
			var symbol = stats.currency_symbol;
			var change = stats.revenue_last_week > 0
				? Math.round( ( stats.revenue_today - stats.revenue_last_week ) / stats.revenue_last_week * 100 )
				: null;
			var max = Math.max.apply( null, stats.days.map( function ( d ) { return d.revenue; } ).concat( [ 1 ] ) );
			var points = stats.days.map( function ( d, i ) {
				return ( i * ( 300 / ( stats.days.length - 1 ) ) ).toFixed( 1 ) + ',' + ( 56 - d.revenue / max * 48 ).toFixed( 1 );
			} ).join( ' ' );

			el.querySelector( '.ypn-kpis' ).innerHTML =
				'<div class="ypn-card ypn-kpi ypn-kpi--main">' +
					'<span class="ypn-kpi__label">Revenue today</span>' +
					'<span class="ypn-kpi__value">' + esc( money( stats.revenue_today, symbol ) ) + '</span>' +
					( null === change
						? '<span class="ypn-kpi__note">' + esc( money( stats.revenue_7_days, symbol ) ) + ' this week</span>'
						: '<span class="ypn-kpi__note ' + ( change >= 0 ? 'is-up' : 'is-down' ) + '">' + ( change >= 0 ? '▲ ' : '▼ ' ) + Math.abs( change ) + '% vs last ' + new Date().toLocaleDateString( undefined, { weekday: 'long' } ) + '</span>' ) +
					'<svg class="ypn-spark" viewBox="0 0 300 60" preserveAspectRatio="none" aria-hidden="true"><polygon points="0,60 ' + points + ' 300,60" /><polyline points="' + points + '" /></svg>' +
				'</div>' +
				'<div class="ypn-card ypn-kpi"><span class="ypn-kpi__label">Orders today</span><span class="ypn-kpi__value">' + esc( String( stats.orders_today ) ) + '</span><span class="ypn-kpi__note">paid or waiting</span></div>' +
				'<a class="ypn-card ypn-kpi" href="#/production"><span class="ypn-kpi__label">In production</span><span class="ypn-kpi__value">' + esc( String( stats.in_production ) ) + '</span><span class="ypn-kpi__note">open the board &rarr;</span></a>' +
				'<div class="ypn-card ypn-kpi"><span class="ypn-kpi__label">Last 7 days</span><span class="ypn-kpi__value">' + esc( money( stats.revenue_7_days, symbol ) ) + '</span><span class="ypn-kpi__note">paid orders</span></div>';
		} ).catch( function ( error ) {
			el.querySelector( '.ypn-kpis' ).innerHTML = '<p class="yp-form__error">Couldn’t load today’s numbers: ' + esc( error.message ) + '</p>';
		} );
	};

	/* ---------- Today: Your queue + Ship today ---------- */

	var todayEl = null;
	var todayBoard = null;
	var todayProblems = [];

	// Bar color and order of each kind of queue item, most urgent first.
	var QUEUE_KINDS = {
		problem: { rank: 0, color: '#dc2626' },
		proof: { rank: 1, color: 'var(--ypn-mag)' },
		ready: { rank: 2, color: '#7c3aed' },
		approval: { rank: 3, color: 'var(--ypn-yel)' },
		unpaid: { rank: 4, color: '#9a9aa0' }
	};

	function loadToday() {
		YP.request( api( 'admin/next/board' ) ).then( function ( data ) {
			todayBoard = data.orders;
			drawToday();
		} ).catch( function ( error ) {
			if ( todayEl && document.body.contains( todayEl ) ) {
				todayEl.querySelector( '[data-ypn-queue]' ).innerHTML = '<p class="yp-form__error">Couldn’t load your queue: ' + esc( error.message ) + '</p>';
				todayEl.querySelector( '[data-ypn-ship]' ).innerHTML = '';
			}
		} );
	}

	// The dashboard summary app.js already loads; lost or returned packages join the queue.
	YP.next.onDashboard = function ( summary ) {
		todayProblems = ( summary.shipped_packages || [] ).filter( function ( pkg ) {
			return 'FAILURE' === pkg.tracking_status || 'RETURNED' === pkg.tracking_status;
		} );
		drawToday();
	};

	function orderLabel( o ) {
		return ( /^\d+$/.test( String( o.number ) ) ? '#' : '' ) + o.number;
	}

	function queueItems() {
		var items = todayProblems.map( function ( pkg ) {
			return {
				kind: 'problem',
				id: pkg.id,
				title: pkg.order_label + ': ' + ( 'FAILURE' === pkg.tracking_status ? 'delivery failed' : 'returned to sender' ),
				meta: ( pkg.customer || '' ) + ' · ' + ( pkg.carrier_label || 'Carrier' ) + ' ' + pkg.tracking_number,
				action: 'Open'
			};
		} );

		var day = 86400000;
		( todayBoard || [] ).forEach( function ( o ) {
			var age = o.date ? Date.now() - new Date( o.date ).getTime() : 0;
			var base = { id: o.id, express: o.express, date: o.date, meta: ( o.customer || 'Guest' ) + ' · ' + o.items + ' · ' + ago( o.date ) };
			if ( 'proof' === o.column ) {
				items.push( Object.assign( base, { kind: 'proof', title: 'Make the proof for ' + orderLabel( o ), action: 'Open' } ) );
			} else if ( 'ready' === o.column ) {
				items.push( Object.assign( base, { kind: 'ready', title: 'Print ' + orderLabel( o ), action: 'Send to printer', print: true } ) );
			} else if ( 'approval' === o.column && ( o.express || age > 2 * day ) ) {
				items.push( Object.assign( base, { kind: 'approval', title: 'Nudge ' + ( o.customer || 'the customer' ) + ' about their proof', action: 'Open' } ) );
			} else if ( 'unpaid' === o.column && ( o.express || age > 2 * day ) ) {
				items.push( Object.assign( base, { kind: 'unpaid', title: 'Payment still due on ' + orderLabel( o ), action: 'Open' } ) );
			}
		} );

		items.sort( function ( a, b ) {
			if ( ( 'problem' === a.kind ) !== ( 'problem' === b.kind ) ) {
				return 'problem' === a.kind ? -1 : 1;
			}
			if ( !! a.express !== !! b.express ) {
				return a.express ? -1 : 1;
			}
			if ( QUEUE_KINDS[ a.kind ].rank !== QUEUE_KINDS[ b.kind ].rank ) {
				return QUEUE_KINDS[ a.kind ].rank - QUEUE_KINDS[ b.kind ].rank;
			}
			return String( a.date || '' ).localeCompare( String( b.date || '' ) );
		} );
		return items;
	}

	document.addEventListener( 'ypn:drawer-closed', function () {
		if ( todayEl && document.body.contains( todayEl ) ) {
			loadToday();
		}
	} );

	function drawToday() {
		if ( ! todayEl || ! document.body.contains( todayEl ) || ! todayBoard ) {
			return;
		}

		var items = queueItems();
		var shown = items.slice( 0, 7 );
		var queueEl = todayEl.querySelector( '[data-ypn-queue]' );
		todayEl.querySelector( '[data-ypn-queue-count]' ).textContent = items.length ? String( items.length ) : '';

		queueEl.innerHTML = items.length
			? shown.map( function ( item ) {
				return (
					'<div class="ypn-q' + ( item.express || 'problem' === item.kind ? ' is-hot' : '' ) + '">' +
						'<i style="background:' + QUEUE_KINDS[ item.kind ].color + '"></i>' +
						'<a class="ypn-q__text" href="#/order/' + item.id + '"><b>' + esc( item.title ) + ( item.express ? ' <span class="ypn-tag-express">EXPRESS</span>' : '' ) + '</b><span>' + esc( item.meta ) + '</span></a>' +
						( item.print
							? '<button type="button" class="ypn-btn" data-ypn-q-print="' + item.id + '">' + esc( item.action ) + '</button>'
							: '<a class="ypn-btn" href="#/order/' + item.id + '">' + esc( item.action ) + '</a>' ) +
					'</div>'
				);
			} ).join( '' ) + ( items.length > shown.length ? '<a class="ypn-more" href="#/production">' + ( items.length - shown.length ) + ' more on the board &rarr;</a>' : '' )
			: '<div class="ypn-empty"><b>All caught up</b><span>No proofs to make, nothing waiting to print.</span></div>';

		var ship = todayBoard.filter( function ( o ) { return 'printing' === o.column; } );
		todayEl.querySelector( '[data-ypn-ship-count]' ).textContent = ship.length ? String( ship.length ) : '';
		todayEl.querySelector( '[data-ypn-ship]' ).innerHTML = ship.length
			? ship.map( function ( o ) {
				return (
					'<div class="ypn-q">' +
						'<a class="ypn-q__text" href="#/order/' + o.id + '"><b class="ypn-mono">' + esc( orderLabel( o ) ) + ( o.express ? ' <span class="ypn-tag-express">EXPRESS</span>' : '' ) + '</b><span>' + esc( ( o.customer || 'Guest' ) + ( o.shipping ? ' · ' + o.shipping : '' ) ) + '</span></a>' +
						'<button type="button" class="ypn-btn" data-ypn-q-label="' + o.id + '">Label</button>' +
					'</div>'
				);
			} ).join( '' )
			: '<div class="ypn-empty"><span>Nothing on the printer right now. Orders you send to the printer show up here.</span></div>';

		if ( ! todayEl.getAttribute( 'data-ypn-bound' ) ) {
			todayEl.setAttribute( 'data-ypn-bound', '1' );
			todayEl.addEventListener( 'click', function ( event ) {
				var print = event.target.closest( '[data-ypn-q-print]' );
				if ( print ) {
					print.disabled = true;
					print.textContent = 'Sending…';
					sendOrderToPrinter( print.getAttribute( 'data-ypn-q-print' ) ).then( loadToday ).catch( function ( error ) {
						print.disabled = false;
						print.textContent = 'Send to printer';
						window.alert( 'Couldn’t send to printer: ' + error.message );
					} );
					return;
				}
				var label = event.target.closest( '[data-ypn-q-label]' );
				if ( label ) {
					YP.next.openDetails( parseInt( label.getAttribute( 'data-ypn-q-label' ), 10 ), 'label' );
				}
			} );
		}
	}

	/* ---------- Production board ---------- */

	var COLUMNS = [
		{ id: 'unpaid', label: 'Unpaid', hint: 'Waiting for payment' },
		{ id: 'proof', label: 'Needs proof', hint: 'Paid, proof not sent yet' },
		{ id: 'approval', label: 'Proof sent', hint: 'Waiting on the customer' },
		{ id: 'ready', label: 'Ready to print', hint: 'Paid and approved' },
		{ id: 'printing', label: 'In production', hint: 'On the printer' },
		{ id: 'shipped', label: 'Shipped', hint: 'Last 14 days' }
	];
	var PRINTABLE = [ 'proof', 'approval', 'ready' ];

	var boardState = { column: 'all', search: '', expressOnly: false };

	YP.views.production = function ( viewEl, subId ) {
		viewEl.innerHTML =
			'<div class="ypn-toolbar">' +
				'<input type="search" class="ypn-search" placeholder="Search name, order # or item" data-ypn-search value="' + escAttr( boardState.search ) + '">' +
				'<button type="button" class="ypn-chip' + ( boardState.expressOnly ? ' is-on' : '' ) + '" data-ypn-express>Express only</button>' +
				'<button type="button" class="ypn-chip" data-ypn-refresh>Refresh</button>' +
				'<a class="ypn-chip" href="#/order-history">List view</a>' +
			'</div>' +
			'<div class="ypn-seg" data-ypn-seg></div>' +
			'<div class="ypn-board" data-ypn-board><p class="yp-field__hint">Loading&hellip;</p></div>';

		var boardEl = viewEl.querySelector( '[data-ypn-board]' );
		var segEl   = viewEl.querySelector( '[data-ypn-seg]' );
		var orders  = [];

		function load() {
			YP.request( api( 'admin/next/board' ) ).then( function ( data ) {
				orders = data.orders;
				draw();
			} ).catch( function ( error ) {
				boardEl.innerHTML = '<p class="yp-form__error">Couldn’t load the board: ' + esc( error.message ) + '</p>';
			} );
		}

		function visible() {
			var q = boardState.search.trim().toLowerCase();
			return orders.filter( function ( o ) {
				if ( boardState.expressOnly && ! o.express ) {
					return false;
				}
				return ! q || ( o.number + ' ' + o.customer + ' ' + o.items ).toLowerCase().indexOf( q ) !== -1;
			} );
		}

		function cardHtml( o ) {
			var canPrint = PRINTABLE.indexOf( o.column ) !== -1;
			return (
				'<div class="ypn-order' + ( o.express ? ' is-express' : '' ) + '" data-ypn-order="' + o.id + '"' + ( canPrint ? ' draggable="true"' : '' ) + ' tabindex="0" role="button">' +
					'<div class="ypn-order__row"><span class="ypn-order__num">' + ( /^\d+$/.test( String( o.number ) ) ? '#' : '' ) + esc( String( o.number ) ) + '</span>' +
						( o.express ? '<span class="ypn-tag-express">EXPRESS</span>' : '<span class="ypn-order__age">' + esc( ago( o.date ) ) + '</span>' ) +
					'</div>' +
					'<div class="ypn-order__name">' + esc( o.customer || 'Guest' ) + '</div>' +
					'<div class="ypn-order__items">' + esc( o.items ) + '</div>' +
					'<div class="ypn-order__row"><span class="ypn-order__meta">' + esc( o.payment_method || o.status_label ) + '</span><b>' + esc( money( o.total ) ) + '</b></div>' +
					( 'ready' === o.column ? '<button type="button" class="ypn-order__action" data-ypn-print="' + o.id + '">Send to printer</button>' : '' ) +
				'</div>'
			);
		}

		function draw() {
			var list = visible();
			var byCol = {};
			COLUMNS.forEach( function ( c ) { byCol[ c.id ] = []; } );
			list.forEach( function ( o ) { ( byCol[ o.column ] || byCol.ready ).push( o ); } );

			segEl.innerHTML =
				'<button type="button" class="' + ( 'all' === boardState.column ? 'is-on' : '' ) + '" data-ypn-col="all">All <i>' + list.length + '</i></button>' +
				COLUMNS.map( function ( c ) {
					return '<button type="button" class="' + ( c.id === boardState.column ? 'is-on' : '' ) + '" data-ypn-col="' + c.id + '">' + esc( c.label ) + ' <i>' + byCol[ c.id ].length + '</i></button>';
				} ).join( '' );

			boardEl.setAttribute( 'data-column', boardState.column );
			boardEl.innerHTML = COLUMNS.map( function ( c ) {
				return (
					'<section class="ypn-col ypn-col--' + c.id + ( c.id === boardState.column ? ' is-picked' : '' ) + '" data-ypn-drop="' + c.id + '">' +
						'<header class="ypn-col__head"><i></i><span>' + esc( c.label ) + '</span><b>' + byCol[ c.id ].length + '</b></header>' +
						'<p class="ypn-col__hint">' + esc( c.hint ) + '</p>' +
						( byCol[ c.id ].length ? byCol[ c.id ].map( cardHtml ).join( '' ) : '<p class="ypn-col__empty">Nothing here</p>' ) +
					'</section>'
				);
			} ).join( '' );
		}

		function sendToPrinter( id, button ) {
			if ( button ) {
				button.disabled = true;
				button.textContent = 'Sending…';
			}
			return YP.request( api( 'admin/order/' + id + '/send-to-printer' ), { method: 'POST' } )
				.then( load )
				.catch( function ( error ) {
					window.alert( 'Couldn’t send to printer: ' + error.message );
					load();
				} );
		}

		viewEl.addEventListener( 'click', function ( event ) {
			var print = event.target.closest( '[data-ypn-print]' );
			if ( print ) {
				event.stopPropagation();
				sendToPrinter( print.getAttribute( 'data-ypn-print' ), print );
				return;
			}
			var col = event.target.closest( '[data-ypn-col]' );
			if ( col ) {
				boardState.column = col.getAttribute( 'data-ypn-col' );
				draw();
				return;
			}
			if ( event.target.closest( '[data-ypn-express]' ) ) {
				boardState.expressOnly = ! boardState.expressOnly;
				event.target.closest( '[data-ypn-express]' ).classList.toggle( 'is-on', boardState.expressOnly );
				draw();
				return;
			}
			if ( event.target.closest( '[data-ypn-refresh]' ) ) {
				load();
				return;
			}
			var card = event.target.closest( '[data-ypn-order]' );
			if ( card ) {
				window.location.hash = '#/order/' + card.getAttribute( 'data-ypn-order' );
			}
		} );

		viewEl.addEventListener( 'keydown', function ( event ) {
			var card = event.target.closest && event.target.closest( '[data-ypn-order]' );
			if ( card && ( 'Enter' === event.key || ' ' === event.key ) ) {
				event.preventDefault();
				window.location.hash = '#/order/' + card.getAttribute( 'data-ypn-order' );
			}
		} );

		viewEl.querySelector( '[data-ypn-search]' ).addEventListener( 'input', function ( event ) {
			boardState.search = event.target.value;
			draw();
		} );

		// Drag a paid card onto In production = Send to printer. Other
		// moves happen in the order window (payment, proof, shipping).
		viewEl.addEventListener( 'dragstart', function ( event ) {
			var card = event.target.closest( '[data-ypn-order]' );
			if ( card ) {
				event.dataTransfer.setData( 'text/plain', card.getAttribute( 'data-ypn-order' ) );
				boardEl.classList.add( 'is-dragging' );
			}
		} );
		viewEl.addEventListener( 'dragend', function () { boardEl.classList.remove( 'is-dragging' ); } );
		viewEl.addEventListener( 'dragover', function ( event ) {
			var drop = event.target.closest( '[data-ypn-drop="printing"]' );
			if ( drop ) {
				event.preventDefault();
				drop.classList.add( 'is-over' );
			}
		} );
		viewEl.addEventListener( 'dragleave', function ( event ) {
			var drop = event.target.closest( '[data-ypn-drop]' );
			if ( drop ) {
				drop.classList.remove( 'is-over' );
			}
		} );
		viewEl.addEventListener( 'drop', function ( event ) {
			var drop = event.target.closest( '[data-ypn-drop="printing"]' );
			if ( ! drop ) {
				return;
			}
			event.preventDefault();
			drop.classList.remove( 'is-over' );
			var id = event.dataTransfer.getData( 'text/plain' );
			if ( id ) {
				sendToPrinter( id );
			}
		} );

		function onDrawerClosed() {
			if ( ! document.body.contains( boardEl ) ) {
				document.removeEventListener( 'ypn:drawer-closed', onDrawerClosed );
				return;
			}
			load();
		}
		document.addEventListener( 'ypn:drawer-closed', onDrawerClosed );

		load();

		// Older phone alerts linked #/production/{id}; that order now has its own page.
		if ( subId && /^\d+$/.test( subId ) ) {
			window.location.replace( '#/order/' + subId );
		}
	};

	/* ---------- Progress tracker (order page + order window) ---------- */

	var STEPS = [ 'Placed', 'Paid', 'Printing', 'Shipped', 'Delivered' ];
	var STEP_FOR_STATUS = { 'checkout-draft': 0, pending: 1, 'on-hold': 1, processing: 2, 'in-production': 2, shipped: 3, completed: 5 };

	// Orders with a custom design go through the proof first.
	var PROOF_STEPS = [ 'Paid', 'Proof', 'Approved', 'Printing', 'Shipped' ];

	/**
	 * `proof` is '' (no custom design on the order, or every proof
	 * approved), 'needs_proof' or 'proof_sent', same as the board's.
	 * `hasDesign` switches to the proof steps.
	 */
	function progressHtml( order, proof, hasDesign ) {
		var steps = hasDesign ? PROOF_STEPS : STEPS;
		var current;

		if ( hasDesign ) {
			current = {
				pending: 0, 'on-hold': 0, 'checkout-draft': 0,
				processing: 'needs_proof' === proof ? 1 : ( 'proof_sent' === proof ? 2 : 3 ),
				'in-production': 3, shipped: 4, completed: 5
			}[ order.status ];
		} else {
			current = STEP_FOR_STATUS[ order.status ];
		}

		if ( undefined === current ) {
			return '<div class="ypn-progress ypn-progress--stopped">This order is ' + esc( order.status_label.toLowerCase() ) + '.</div>';
		}

		return '<ol class="ypn-progress">' + steps.map( function ( step, i ) {
			var state = i < current ? 'done' : ( i === current ? 'now' : '' );
			var label = step;
			if ( 'Printing' === step && 'processing' === order.status && i === current ) {
				label = 'Ready to print';
			}
			return '<li class="' + state + '"><i>' + ( 'done' === state ? '✓' : i + 1 ) + '</i><span>' + esc( label ) + '</span></li>';
		} ).join( '' ) + '</ol>';
	}

	function proofStageOf( customOrders ) {
		var stage = '';
		customOrders.forEach( function ( c ) {
			if ( 'design_in_progress' === c.status || 'proof_ready' === c.status ) {
				stage = 'needs_proof';
			} else if ( 'awaiting_approval' === c.status && 'needs_proof' !== stage ) {
				stage = 'proof_sent';
			}
		} );
		return stage;
	}

	function copyText( text, button ) {
		var done = function () { button.textContent = 'Copied'; };
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( text ).then( done, function () { window.prompt( 'Copy this', text ); } );
		} else {
			window.prompt( 'Copy this', text );
		}
	}

	function sendOrderToPrinter( id ) {
		return YP.request( api( 'admin/order/' + id + '/send-to-printer' ), { method: 'POST' } );
	}

	/**
	 * The order window (app.js) still holds everything the order page
	 * doesn't repeat: status, refunds, customer notes, Shippo, editing.
	 * `focus` jumps straight to one part of it once it has loaded:
	 * 'label' (the shipping label panel), 'edit' (Edit order), or a
	 * panel heading such as 'Status' or 'Record payment'.
	 */
	var pendingFocus = '';

	YP.next.openDetails = function ( id, focus ) {
		pendingFocus = focus || '';
		YP.openWcOrderDrawer( id );
	};

	function panelByHeading( bodyEl, text ) {
		var wanted = text.toLowerCase();
		var found = null;
		bodyEl.querySelectorAll( '.yp-panel' ).forEach( function ( panel ) {
			var h2 = panel.querySelector( '.yp-panel__head h2' );
			if ( ! found && h2 && h2.textContent.trim().toLowerCase().indexOf( wanted ) === 0 ) {
				found = panel;
			}
		} );
		return found;
	}

	function applyFocus( order, bodyEl ) {
		var focus = pendingFocus;
		pendingFocus = '';
		if ( ! focus ) {
			return;
		}

		var target = null;
		if ( 'edit' === focus ) {
			var edit = bodyEl.querySelector( '[data-yp-edit-order]' );
			if ( edit ) {
				edit.click();
			}
			target = bodyEl.querySelector( '[data-yp-items-panel]' );
		} else if ( 'label' === focus ) {
			if ( ! order.shippo_configured && order.shipping_label_available ) {
				var print = bodyEl.querySelector( '[data-yp-print-label]' );
				if ( print ) {
					print.click();
				}
				target = bodyEl.querySelector( '[data-yp-shipping-label-panel]' );
			} else {
				target = panelByHeading( bodyEl, 'Shippo' );
			}
		} else {
			target = panelByHeading( bodyEl, focus );
		}

		if ( target ) {
			target.classList.add( 'ypn-flash' );
			window.setTimeout( function () { target.scrollIntoView( { behavior: 'smooth', block: 'start' } ); }, 60 );
		}
	}

	/* ---------- Order window: progress + quick actions ---------- */

	YP.onOrderDetail = function ( order, drawer, bodyEl ) {
		var onPage = /^#\/order\//.test( window.location.hash );
		var actions = [];
		if ( ! onPage ) {
			actions.push( '<a class="ypn-btn" href="#/order/' + order.id + '" data-ypn-full-page>Open full page</a>' );
		}
		if ( 'processing' === order.status ) {
			actions.push( '<button type="button" class="ypn-btn ypn-btn--primary" data-ypn-detail-print>Send to printer</button>' );
		}
		if ( order.payment_url ) {
			actions.push( '<button type="button" class="ypn-btn" data-ypn-copy-pay>Copy pay link</button>' );
		}
		if ( order.customer_email ) {
			actions.push( '<a class="ypn-btn" href="mailto:' + escAttr( order.customer_email ) + '">Email customer</a>' );
		}

		var hasDesign = order.items.some( function ( item ) { return item.custom_order_id; } );

		bodyEl.insertAdjacentHTML( 'afterbegin',
			'<div class="ypn-detail-top">' +
				( onPage ? '' : progressHtml( order, '', hasDesign && 'processing' !== order.status ) ) +
				( actions.length ? '<div class="ypn-detail-actions">' + actions.join( '' ) + '</div>' : '' ) +
			'</div>'
		);

		var fullPage = bodyEl.querySelector( '[data-ypn-full-page]' );
		if ( fullPage ) {
			fullPage.addEventListener( 'click', function () { YP.closeDrawer( drawer ); } );
		}

		var printButton = bodyEl.querySelector( '[data-ypn-detail-print]' );
		if ( printButton ) {
			printButton.addEventListener( 'click', function () {
				printButton.disabled = true;
				printButton.textContent = 'Sending…';
				sendOrderToPrinter( order.id )
					.then( function () {
						YP.closeDrawer( drawer );
						YP.openWcOrderDrawer( order.id );
					} )
					.catch( function ( error ) {
						printButton.disabled = false;
						printButton.textContent = 'Send to printer';
						window.alert( 'Couldn’t send to printer: ' + error.message );
					} );
			} );
		}

		var copyButton = bodyEl.querySelector( '[data-ypn-copy-pay]' );
		if ( copyButton ) {
			copyButton.addEventListener( 'click', function () { copyText( order.payment_url, copyButton ); } );
		}

		applyFocus( order, bodyEl );
	};

	/* ---------- Order page (#/order/{id}) ---------- */

	var mediaUrl = yeffoprintAdminApp.restUrl.replace( /yeffoprint-core\/v1\/?$/, '' ) + 'wp/v2/media';

	function uploadProof( file, customOrderId ) {
		return YP.request( mediaUrl, {
			method: 'POST',
			headers: {
				'Content-Type': file.type || 'application/octet-stream',
				'Content-Disposition': 'attachment; filename="' + file.name.replace( /["\\\r\n]/g, '' ) + '"'
			},
			body: file
		} ).then( function ( media ) {
			return YP.request( api( 'admin/proofs' ), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { custom_order_id: customOrderId, file_id: media.id } )
			} );
		} );
	}

	function addressText( html ) {
		var div = document.createElement( 'div' );
		div.innerHTML = String( html || '' ).replace( /<br\s*\/?>/gi, '\n' );
		return div.textContent;
	}

	function kv( rows ) {
		return '<dl class="ypn-kv">' + rows.filter( Boolean ).map( function ( r ) {
			return '<dt>' + esc( r[ 0 ] ) + '</dt><dd>' + r[ 1 ] + '</dd>';
		} ).join( '' ) + '</dl>';
	}

	function initials( name ) {
		return String( name || '?' ).replace( /[^A-Za-z0-9 ]/g, ' ' ).trim().split( /\s+/ ).slice( 0, 2 ).map( function ( w ) { return w.charAt( 0 ); } ).join( '' ).toUpperCase() || '?';
	}

	function labelCardHtml( item ) {
		var preview = item.image_url
			? '<img src="' + escAttr( item.image_url ) + '" alt="" loading="lazy">'
			: '<span>' + esc( initials( item.name ) ) + '</span>';
		return (
			'<div class="ypn-lab">' +
				'<div class="ypn-lab__pv' + ( item.image_url ? ' has-img' : '' ) + '">' + preview + '</div>' +
				'<div class="ypn-lab__name">' + esc( item.name ) + '</div>' +
				'<div class="ypn-lab__meta">× ' + esc( String( item.quantity ) ) + ' · ' + esc( money( item.total ) ) + '</div>' +
				( item.meta.length
					// display_value is WooCommerce's own kses-filtered HTML (batch tables, color swatches), rendered the same way the order window does.
					? '<details class="ypn-lab__more"><summary>Details</summary><dl>' + item.meta.map( function ( m ) {
						return '<dt>' + esc( m.label ) + '</dt><dd>' + m.value + '</dd>';
					} ).join( '' ) + '</dl></details>'
					: '' ) +
			'</div>'
		);
	}

	function proofCardHtml( c ) {
		var latest = c.proofs && c.proofs.length ? c.proofs[ 0 ] : null;
		var needs = 'design_in_progress' === c.status || 'proof_ready' === c.status;
		var note = c.change_request_notes && 'design_in_progress' === c.status
			? '<div class="ypn-proof__changes"><b>Customer asked for changes</b>' + esc( c.change_request_notes ) + '</div>'
			: '';

		return (
			'<section class="ypn-card ypn-proof" data-ypn-proof="' + c.id + '">' +
				'<h3 class="ypn-card__title">Proof <span><span class="ypn-pill ypn-pill--' + ( needs ? 'mag' : ( 'awaiting_approval' === c.status ? 'yel' : 'grn' ) ) + '">' + esc( c.status_label || 'Not paid yet' ) + '</span></span></h3>' +
				'<p class="ypn-proof__title">' + esc( c.title ) + ( c.order_type_label ? ' · ' + esc( c.order_type_label ) : '' ) + '</p>' +
				note +
				( latest
					? '<p class="ypn-proof__latest">Last proof sent ' + esc( new Date( latest.date ).toLocaleDateString( undefined, { month: 'short', day: 'numeric' } ) ) +
						( latest.file_url ? ' · <a href="' + escAttr( latest.file_url ) + '" target="_blank" rel="noopener noreferrer">View file</a>' : '' ) +
						( c.approval_url ? ' · <button type="button" class="ypn-link" data-ypn-copy="' + escAttr( c.approval_url ) + '">Copy approval link</button>' : '' ) +
					'</p>'
					: '' ) +
				( c.paid
					? '<label class="ypn-drop' + ( needs ? ' is-wanted' : '' ) + '" data-ypn-drop-proof="' + c.id + '">' +
						'<input type="file" accept="image/*,application/pdf" hidden data-ypn-proof-file="' + c.id + '">' +
						'<b>' + ( latest ? 'Drop a new proof here' : 'Drop the proof PDF or PNG here' ) + '</b>' +
						'<span>or tap to choose a file. The customer gets an approval link right away.</span>' +
					'</label>'
					: '<p class="yp-field__hint">The proof can be sent once this order is paid.</p>' ) +
				'<p class="ypn-proof__foot"><a href="#/orders/' + c.id + '">Open in Custom Orders &rsaquo;</a></p>' +
			'</section>'
		);
	}

	YP.views.order = function ( viewEl, subId ) {
		var id = parseInt( subId, 10 );
		var titleEl = document.querySelector( '[data-yp-title]' );
		if ( ! id ) {
			window.location.hash = '#/production';
			return;
		}

		viewEl.innerHTML = '<p class="yp-field__hint">Loading&hellip;</p>';

		function load() {
			YP.request( api( 'admin/order/' + id ) ).then( function ( order ) {
				var ids = [];
				order.items.forEach( function ( item ) {
					if ( item.custom_order_id && ids.indexOf( item.custom_order_id ) === -1 ) {
						ids.push( item.custom_order_id );
					}
				} );
				return Promise.all( ids.map( function ( customId ) {
					return YP.request( api( 'admin/custom-order/' + customId ) ).catch( function () { return null; } );
				} ) ).then( function ( customOrders ) {
					draw( order, customOrders.filter( Boolean ) );
				} );
			} ).catch( function ( error ) {
				viewEl.innerHTML = '<p class="yp-form__error">Couldn’t load this order: ' + esc( error.message ) + '</p><a class="ypn-btn" href="#/production">Back to the board</a>';
			} );
		}

		function draw( order, customOrders ) {
			if ( ! document.body.contains( viewEl ) ) {
				return;
			}
			if ( titleEl ) {
				titleEl.textContent = 'Order ' + ( /^\d+$/.test( String( order.number ) ) ? '#' : '' ) + order.number;
			}

			var proof     = proofStageOf( customOrders );
			var paid      = !! order.date_paid;
			var unpaid    = [ 'pending', 'on-hold', 'checkout-draft', 'failed' ].indexOf( order.status ) !== -1;
			var shipStage = [ 'processing', 'in-production', 'shipped', 'completed' ].indexOf( order.status ) !== -1;
			var units     = order.items.reduce( function ( n, item ) { return n + Number( item.quantity || 0 ); }, 0 );
			var other     = Math.round( ( order.total - order.subtotal - order.shipping_total ) * 100 ) / 100;
			var needsProofFor = customOrders.filter( function ( c ) {
				return c.paid && ( 'design_in_progress' === c.status || 'proof_ready' === c.status );
			} )[ 0 ];

			var sub = [
				esc( order.customer_name || order.customer_email || 'Guest' ),
				esc( money( order.total ) ) + ( paid ? ' paid' : ' unpaid' ) + ( order.payment_method_title ? ( paid ? ' by ' : ' · ' ) + esc( order.payment_method_title ) : '' ),
				order.date ? esc( ago( order.date ) ) + ' ago' : ''
			].filter( Boolean ).join( ' · ' );

			// Actions, most likely next step first.
			var actions = [];
			if ( needsProofFor ) {
				actions.push( '<button type="button" class="ypn-act ypn-act--primary" data-ypn-act="proof">Upload proof <span>↑</span></button>' );
			} else if ( 'processing' === order.status ) {
				actions.push( '<button type="button" class="ypn-act ypn-act--primary" data-ypn-act="print">Send to printer <span>›</span></button>' );
			}
			if ( shipStage ) {
				actions.push( '<button type="button" class="ypn-act' + ( 'in-production' === order.status ? ' ypn-act--primary' : '' ) + '" data-ypn-act="label">' + ( ( order.shippo_labels || [] ).length ? 'Shipping label' : 'Buy shipping label' ) + ' <span>' + esc( order.shipping_method || '›' ) + '</span></button>' );
			}
			if ( order.payment_url ) {
				actions.push( '<button type="button" class="ypn-act" data-ypn-act="pay">Copy pay link <span>⧉</span></button>' );
			}
			if ( order.can_record_payment ) {
				actions.push( '<button type="button" class="ypn-act" data-ypn-act="record">Record a payment <span>›</span></button>' );
			}
			if ( order.editable ) {
				actions.push( '<button type="button" class="ypn-act" data-ypn-act="edit">Edit items <span>›</span></button>' );
				actions.push( '<a class="ypn-act" href="#/manual-order/' + order.id + '">Add items <span>+</span></a>' );
			}
			if ( order.customer_email ) {
				actions.push( '<a class="ypn-act" href="mailto:' + escAttr( order.customer_email ) + '?subject=' + encodeURIComponent( 'Your YeffoDesign order #' + order.number ) + '">Message customer <span>›</span></a>' );
			}
			actions.push( '<button type="button" class="ypn-act" data-ypn-act="status">Change status <span>' + esc( order.status_label ) + '</span></button>' );
			actions.push( '<button type="button" class="ypn-act" data-ypn-act="details">Notes, refunds &amp; all details <span>›</span></button>' );

			var shipTo = order.needs_customer_address
				? '<span class="ypn-pill ypn-pill--yel">Customer adds it when paying</span>'
				: ( order.shipping_address ? esc( addressText( order.shipping_address ) ) : '—' );

			var notes = order.customer_notes || [];
			var balance = Number( order.balance_due || 0 );

			viewEl.innerHTML =
				'<div class="ypn-op">' +
					'<div class="ypn-op__sub">' +
						( order.express ? '<span class="ypn-tag-express">EXPRESS</span>' : '' ) +
						'<span class="ypn-pill ypn-pill--' + ( unpaid ? 'yel' : ( 'shipped' === order.status || 'completed' === order.status ? 'grn' : 'ink' ) ) + '">' + esc( order.status_label ) + '</span>' +
						'<span>' + sub + '</span>' +
					'</div>' +
					'<div class="ypn-card ypn-op__track">' + progressHtml( order, proof, customOrders.length > 0 ) + '</div>' +
					'<div class="ypn-op__grid">' +
						'<div class="ypn-op__main">' +
							customOrders.map( proofCardHtml ).join( '' ) +
							'<section class="ypn-card">' +
								'<h3 class="ypn-card__title">Labels &amp; items <span>' + order.items.length + ( 1 === order.items.length ? ' line' : ' lines' ) + ' · ' + units + ' total</span></h3>' +
								( order.items.length ? '<div class="ypn-labs">' + order.items.map( labelCardHtml ).join( '' ) + '</div>' : '<p class="yp-field__hint">No items on this order.</p>' ) +
							'</section>' +
							( order.customer_note
								? '<section class="ypn-card"><h3 class="ypn-card__title">Note from the customer</h3><p class="ypn-op__note">' + esc( order.customer_note ) + '</p></section>'
								: '' ) +
							( notes.length
								? '<section class="ypn-card"><h3 class="ypn-card__title">Your notes on this customer <span>' + notes.length + '</span></h3>' +
									notes.slice( 0, 3 ).map( function ( n ) { return '<p class="ypn-op__note">' + esc( n.note ) + '</p>'; } ).join( '' ) +
								'</section>'
								: '' ) +
						'</div>' +
						'<aside class="ypn-op__side">' +
							'<section class="ypn-card ypn-acts"><h3 class="ypn-card__title">Actions</h3>' + actions.join( '' ) + '</section>' +
							'<section class="ypn-card"><h3 class="ypn-card__title">Ship to</h3>' + kv( [
								[ 'Name', esc( order.customer_name || '—' ) ],
								[ 'Address', '<span class="ypn-pre">' + shipTo + '</span>' ],
								[ 'Method', esc( order.shipping_method || ( order.customer_picks_shipping ? 'Customer picks when paying' : '—' ) ) ],
								order.customer_email ? [ 'Email', '<a href="mailto:' + escAttr( order.customer_email ) + '">' + esc( order.customer_email ) + '</a>' ] : null,
								order.customer_phone ? [ 'Phone', '<a href="tel:' + escAttr( order.customer_phone ) + '">' + esc( order.customer_phone ) + '</a>' ] : null
							] ) + '</section>' +
							'<section class="ypn-card"><h3 class="ypn-card__title">Payment</h3>' + kv( [
								[ 'Items', esc( money( order.subtotal ) ) ],
								[ 'Shipping', esc( money( order.shipping_total ) ) ],
								other > 0 ? [ 'Fees', esc( money( other ) ) ] : null,
								[ 'Total', '<b>' + esc( money( order.total ) ) + '</b>' ],
								paid
									? [ 'Paid', '<b class="ypn-good">' + esc( money( order.total ) ) + ( order.payment_method_title ? ' ' + esc( order.payment_method_title ) : '' ) + '</b>' ]
									: [ 'Paid', Number( order.amount_received || 0 ) > 0 ? esc( money( order.amount_received ) ) + ' so far' : 'Not yet' ],
								! paid && balance > 0 && Number( order.amount_received || 0 ) > 0 ? [ 'Still owed', '<b class="ypn-bad">' + esc( money( balance ) ) + '</b>' ] : null,
								Number( order.total_refunded || 0 ) > 0 ? [ 'Refunded', esc( money( order.total_refunded ) ) ] : null
							] ) + '</section>' +
							'<p class="ypn-op__woo"><a href="' + escAttr( order.edit_url ) + '" target="_blank" rel="noopener noreferrer">Open in WooCommerce &rarr;</a></p>' +
						'</aside>' +
					'</div>' +
					// Phone: the main step stays under your thumb.
					'<div class="ypn-op__sticky">' +
						( shipStage ? '<button type="button" class="ypn-btn" data-ypn-act="label">Label</button>' : '' ) +
						( needsProofFor
							? '<button type="button" class="ypn-btn ypn-btn--primary" data-ypn-act="proof">Upload proof</button>'
							: ( 'processing' === order.status
								? '<button type="button" class="ypn-btn ypn-btn--primary" data-ypn-act="print">Send to printer</button>'
								: '<button type="button" class="ypn-btn ypn-btn--primary" data-ypn-act="details">All details</button>' ) ) +
					'</div>' +
				'</div>';

			bind( order, needsProofFor );
		}

		function bind( order, needsProofFor ) {
			viewEl.querySelectorAll( '[data-ypn-act]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var act = button.getAttribute( 'data-ypn-act' );
					if ( 'proof' === act && needsProofFor ) {
						var input = viewEl.querySelector( '[data-ypn-proof-file="' + needsProofFor.id + '"]' );
						if ( input ) {
							input.click();
						}
					} else if ( 'print' === act ) {
						button.disabled = true;
						button.textContent = 'Sending…';
						sendOrderToPrinter( order.id ).then( load ).catch( function ( error ) {
							button.disabled = false;
							button.textContent = 'Send to printer';
							window.alert( 'Couldn’t send to printer: ' + error.message );
						} );
					} else if ( 'pay' === act ) {
						copyText( order.payment_url, button );
					} else if ( 'label' === act ) {
						YP.next.openDetails( order.id, 'label' );
					} else if ( 'record' === act ) {
						YP.next.openDetails( order.id, 'Record' );
					} else if ( 'edit' === act ) {
						YP.next.openDetails( order.id, 'edit' );
					} else if ( 'status' === act ) {
						YP.next.openDetails( order.id, 'Status' );
					} else {
						YP.next.openDetails( order.id );
					}
				} );
			} );

			viewEl.querySelectorAll( '[data-ypn-copy]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { copyText( button.getAttribute( 'data-ypn-copy' ), button ); } );
			} );

			viewEl.querySelectorAll( '[data-ypn-drop-proof]' ).forEach( function ( zone ) {
				var customId = parseInt( zone.getAttribute( 'data-ypn-drop-proof' ), 10 );
				var input = zone.querySelector( 'input[type="file"]' );

				function send( file ) {
					if ( ! file ) {
						return;
					}
					zone.classList.add( 'is-busy' );
					zone.querySelector( 'b' ).textContent = 'Sending ' + file.name + '…';
					uploadProof( file, customId ).then( load ).catch( function ( error ) {
						zone.classList.remove( 'is-busy' );
						zone.querySelector( 'b' ).textContent = 'Couldn’t send the proof: ' + error.message;
					} );
				}

				input.addEventListener( 'change', function () { send( input.files[ 0 ] ); } );
				zone.addEventListener( 'dragover', function ( event ) {
					event.preventDefault();
					zone.classList.add( 'is-over' );
				} );
				zone.addEventListener( 'dragleave', function () { zone.classList.remove( 'is-over' ); } );
				zone.addEventListener( 'drop', function ( event ) {
					event.preventDefault();
					zone.classList.remove( 'is-over' );
					send( event.dataTransfer.files[ 0 ] );
				} );
			} );
		}

		function onDrawerClosed() {
			if ( ! document.body.contains( viewEl ) || window.location.hash.indexOf( '#/order/' + id ) !== 0 ) {
				document.removeEventListener( 'ypn:drawer-closed', onDrawerClosed );
				return;
			}
			load();
		}
		document.addEventListener( 'ypn:drawer-closed', onDrawerClosed );

		load();
	};

	/* ---------- Hubs ---------- */

	function tilesHtml( tiles ) {
		return '<div class="ypn-tiles">' + tiles.map( function ( t ) {
			return (
				'<a class="ypn-tile" href="#/' + t[ 0 ] + '">' +
					'<span class="ypn-tile__icon" style="--tile:' + t[ 3 ] + '">' + esc( t[ 1 ].charAt( 0 ) ) + '</span>' +
					'<b>' + esc( t[ 1 ] ) + '</b>' +
					'<span class="ypn-tile__text">' + esc( t[ 2 ] ) + '</span>' +
					'<span class="ypn-tile__go"><span data-ypn-count="' + escAttr( t[ 0 ] ) + '"></span><span>Open &rsaquo;</span></span>' +
				'</a>'
			);
		} ).join( '' ) + '</div>';
	}

	var MAG = '#EC008C', CY = '#00AEEF', YEL = '#d4a300', VIO = '#7c3aed', GRN = '#16a34a', ORA = '#ea580c', INK = '#3a3a3c';

	YP.views.catalog = function ( viewEl ) {
		viewEl.innerHTML = '<div data-ypn-tiles></div><section class="ypn-card ypn-best" data-ypn-best><h3 class="ypn-card__title">Best sellers <span>last 30 days</span></h3><p class="yp-field__hint">Loading&hellip;</p></section>';
		viewEl.querySelector( '[data-ypn-tiles]' ).innerHTML = tilesHtml( [
			[ 'templates', 'Templates', 'Label designs, categories, descriptions and photos.', MAG ],
			[ 'sizes', 'Sizes', 'Label sizes, what they fit, and round lid stickers.', CY ],
			[ 'sticker-sizes', 'Sticker Sizes', 'Custom sticker sizes and prices.', CY ],
			[ 'materials', 'Materials', 'Paper and finish choices with swatches.', YEL ],
			[ 'label-fields', 'Label Fields', 'The fields every template asks for.', VIO ],
			[ 'label-colors', 'Label Colors', 'Background and text color choices.', VIO ],
			[ 'compound-list', 'Compound List', '“Did you mean” spelling checks at checkout.', GRN ],
			[ 'prints', '3D Prints', 'Can holders, vial boxes, sizes and add-ons.', ORA ],
			[ 'filament-colors', 'Filaments', 'Brand colors for the 3D print picker.', ORA ],
			[ 'pricing', 'Pricing Rules', 'Quantity tiers and price rules.', INK ]
		] );

		var COUNT_WORDS = {
			templates: [ 'live', 'drafts' ], prints: [ 'live', 'drafts' ],
			sizes: [ 'sizes' ], 'sticker-sizes': [ 'sizes' ], materials: [ 'materials' ],
			'label-colors': [ 'colors' ], 'compound-list': [ 'names' ], 'filament-colors': [ 'filaments' ], pricing: [ 'rules' ]
		};

		YP.request( api( 'admin/next/catalog' ) ).then( function ( data ) {
			Object.keys( data.counts ).forEach( function ( key ) {
				var el = viewEl.querySelector( '[data-ypn-count="' + key + '"]' );
				var c = data.counts[ key ];
				var words = COUNT_WORDS[ key ] || [ '' ];
				if ( ! el ) {
					return;
				}
				el.className = 'ypn-tile__count';
				el.textContent = c.live + ' ' + words[ 0 ] + ( c.draft && words[ 1 ] ? ' · ' + c.draft + ' ' + words[ 1 ] : '' );
			} );
			var labelFields = viewEl.querySelector( '[data-ypn-count="label-fields"]' );
			if ( labelFields ) {
				labelFields.className = 'ypn-tile__count';
				labelFields.textContent = 'Shared set';
			}

			var best = data.best_sellers;
			var max = best.reduce( function ( m, r ) { return Math.max( m, r.revenue ); }, 0 ) || 1;
			viewEl.querySelector( '[data-ypn-best]' ).innerHTML =
				'<h3 class="ypn-card__title">Best sellers <span>last ' + data.days + ' days, paid orders</span></h3>' +
				( best.length
					? '<table class="ypn-table"><thead><tr><th>Item</th><th class="ypn-hide-phone">Kind</th><th class="ypn-hide-phone">Units</th><th>Orders</th><th>Revenue</th></tr></thead><tbody>' +
						best.map( function ( r ) {
							return (
								'<tr>' +
									'<td><a class="ypn-best__name" href="#/' + escAttr( r.section ) + '">' +
										( r.image ? '<img src="' + escAttr( r.image ) + '" alt="" loading="lazy">' : '<span class="ypn-best__ph">' + esc( initials( r.name ) ) + '</span>' ) +
										'<b>' + esc( r.name ) + '</b></a></td>' +
									'<td class="ypn-hide-phone ypn-muted">' + esc( r.kind ) + '</td>' +
									'<td class="ypn-hide-phone">' + esc( String( r.units ) ) + '</td>' +
									'<td>' + esc( String( r.orders ) ) + '</td>' +
									'<td class="ypn-best__rev"><i style="width:' + Math.max( 4, Math.round( r.revenue / max * 90 ) ) + 'px"></i>' + esc( money( r.revenue ) ) + '</td>' +
								'</tr>'
							);
						} ).join( '' ) + '</tbody></table>'
					: '<p class="yp-field__hint">No paid orders in the last ' + data.days + ' days yet.</p>' );
		} ).catch( function ( error ) {
			viewEl.querySelector( '[data-ypn-best]' ).innerHTML = '<p class="yp-form__error">Couldn’t load catalog numbers: ' + esc( error.message ) + '</p>';
		} );
	};

	YP.views.people = function ( viewEl ) {
		viewEl.innerHTML = tilesHtml( [
			[ 'customers', 'Customers', 'Everyone who has ordered, with their history.', CY ],
			[ 'reviews', 'Reviews', 'Approve new reviews and photos.', YEL ],
			[ 'tracker-feedback', 'Tracker Feedback', 'Help requests and ideas from the Dose Tracker.', GRN ],
			[ 'rewards', 'Rewards', 'Points and reward settings.', MAG ],
			[ 'coupons', 'Coupons', 'Discount codes.', MAG ],
			[ 'maintenance', 'Maintenance Subscribers', 'Web design maintenance plans.', VIO ]
		] );
	};

	var SWITCHES = [
		[ 'away_mode', 'Away mode', 'Shows a banner and pauses express orders.' ],
		[ 'express', 'Express orders', 'The rush checkbox at checkout.' ],
		[ 'local_pickup', 'Local pickup', 'Pickup option for ticked items.' ],
		[ 'promo', 'Homepage promo', 'The rotating homepage banner.' ]
	];

	YP.views.store = function ( viewEl ) {
		viewEl.innerHTML =
			'<div class="ypn-card ypn-switches">' +
				'<div class="ypn-switches__intro"><b>Quick switches</b><span>The things you flip most. Each one saves right away.</span></div>' +
				'<div class="ypn-switches__list" data-ypn-switches>' + SWITCHES.map( function ( s ) {
					return '<label class="ypn-switch" title="' + escAttr( s[ 2 ] ) + '"><span>' + esc( s[ 1 ] ) + '</span><input type="checkbox" role="switch" data-ypn-switch="' + s[ 0 ] + '" disabled><i></i></label>';
				} ).join( '' ) + '</div>' +
			'</div>' +
			'<div class="ypn-card ypn-phone" data-ypn-phone></div>' +
			tilesHtml( [
				[ 'settings/general', 'General', 'Away mode, express fee, dashboard and contact form.', INK ],
				[ 'settings/storefront', 'Storefront', 'Announcement bar, homepage promo, splash and search.', MAG ],
				[ 'settings/shipping', 'Shipping', 'Shippo, shipping options and local pickup.', CY ],
				[ 'settings/integrations', 'Integrations', 'Telegram, social logins and payment keys.', VIO ],
				[ 'surcharge', 'Card Surcharge', 'Extra fee on card payments.', YEL ],
				[ 'web-design-packages', 'Web Design Packages', 'Packages customers can pick.', GRN ],
				[ 'web-design-addons', 'Web Design Add-ons', 'Extras for web design orders.', GRN ]
			] );

		YP.request( api( 'admin/next/switches' ) ).then( function ( values ) {
			viewEl.querySelectorAll( '[data-ypn-switch]' ).forEach( function ( input ) {
				input.checked = !! values[ input.getAttribute( 'data-ypn-switch' ) ];
				input.disabled = false;
				input.addEventListener( 'change', function () {
					input.disabled = true;
					YP.request( api( 'admin/next/switches' ), {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( { key: input.getAttribute( 'data-ypn-switch' ), value: input.checked } )
					} ).then( function ( saved ) {
						input.checked = !! saved[ input.getAttribute( 'data-ypn-switch' ) ];
					} ).catch( function ( error ) {
						input.checked = ! input.checked;
						window.alert( 'Couldn’t save: ' + error.message );
					} ).then( function () { input.disabled = false; } );
				} );
			} );
		} ).catch( function () {} );

		renderPhoneCard( viewEl.querySelector( '[data-ypn-phone]' ) );
	};

	/* ---------- Phone app + alerts ---------- */

	function urlBase64ToUint8Array( base64 ) {
		var padding = '='.repeat( ( 4 - base64.length % 4 ) % 4 );
		var raw = window.atob( ( base64 + padding ).replace( /-/g, '+' ).replace( /_/g, '/' ) );
		var out = new Uint8Array( raw.length );
		for ( var i = 0; i < raw.length; i++ ) {
			out[ i ] = raw.charCodeAt( i );
		}
		return out;
	}

	function deviceName() {
		var ua = window.navigator.userAgent;
		if ( /iphone/i.test( ua ) ) { return 'iPhone'; }
		if ( isIos() ) { return 'iPad'; }
		if ( /android/i.test( ua ) ) { return 'Android phone'; }
		if ( /mac/i.test( ua ) ) { return 'Mac'; }
		if ( /windows/i.test( ua ) ) { return 'Windows PC'; }
		return 'This device';
	}

	function currentSubscription() {
		if ( ! ( 'serviceWorker' in navigator ) || ! ( 'PushManager' in window ) ) {
			return Promise.resolve( null );
		}
		return navigator.serviceWorker.getRegistration( yeffoprintAdminApp.swScope ).then( function ( reg ) {
			return reg ? reg.pushManager.getSubscription() : null;
		} ).catch( function () { return null; } );
	}

	function renderPhoneCard( el ) {
		var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
		var steps =
			'<ol class="ypn-steps">' +
				'<li>Open this page in <b>Safari</b> on your iPhone.</li>' +
				'<li>Tap the <b>Share</b> button, then <b>Add to Home Screen</b>.</li>' +
				'<li>Open <b>YeffoDesign</b> from your Home Screen, come back here and tap <b>Turn on alerts</b>.</li>' +
			'</ol>';

		el.innerHTML =
			'<div class="ypn-phone__head"><b>Phone app &amp; alerts</b><span>Alerts for paid orders, new reviews, contact messages and Dose Tracker feedback.</span></div>' +
			'<div data-ypn-phone-body><p class="yp-field__hint">Checking this device&hellip;</p></div>';

		var body = el.querySelector( '[data-ypn-phone-body]' );

		if ( isIos() && ! isStandalone() ) {
			body.innerHTML = steps + '<p class="yp-field__hint">iPhone only allows alerts from apps added to the Home Screen (iOS 16.4 or newer).</p>';
			return;
		}
		if ( ! supported ) {
			body.innerHTML = '<p class="yp-field__hint">This browser can’t show alerts. On iPhone, add YeffoDesign to your Home Screen first:</p>' + steps;
			return;
		}

		Promise.all( [ YP.request( api( 'admin/next/push' ) ), currentSubscription() ] ).then( function ( results ) {
			var info = results[ 0 ];
			var sub  = results[ 1 ];
			var on   = !! sub && info.devices.some( function ( d ) { return d.endpoint === sub.endpoint; } );

			if ( ! info.available ) {
				body.innerHTML = '<p class="yp-form__error">Alerts aren’t available on this site yet (the push key couldn’t be created).</p>';
				return;
			}

			body.innerHTML =
				'<div class="ypn-phone__row">' +
					'<span class="ypn-phone__state' + ( on ? ' is-on' : '' ) + '">' + ( on ? 'Alerts are on for this ' + esc( deviceName() ) : 'Alerts are off on this ' + esc( deviceName() ) ) + '</span>' +
					( on
						? '<button type="button" class="ypn-btn" data-ypn-push-test>Send a test</button><button type="button" class="ypn-btn" data-ypn-push-off>Turn off</button>'
						: '<button type="button" class="ypn-btn ypn-btn--primary" data-ypn-push-on>Turn on alerts</button>' ) +
				'</div>' +
				( info.devices.length ? '<p class="yp-field__hint">Devices getting alerts: ' + info.devices.map( function ( d ) { return esc( d.device || 'Device' ); } ).join( ', ' ) + '</p>' : '' ) +
				( 'denied' === Notification.permission ? '<p class="yp-form__error">Alerts are blocked for this app. Allow them in your phone’s Settings › Notifications › YeffoDesign.</p>' : '' );

			var onBtn = body.querySelector( '[data-ypn-push-on]' );
			if ( onBtn ) {
				onBtn.addEventListener( 'click', function () {
					onBtn.disabled = true;
					onBtn.textContent = 'Turning on…';
					Notification.requestPermission().then( function ( permission ) {
						if ( 'granted' !== permission ) {
							throw new Error( 'Alerts weren’t allowed.' );
						}
						return navigator.serviceWorker.register( yeffoprintAdminApp.swUrl, { scope: yeffoprintAdminApp.swScope } );
					} ).then( function () {
						return navigator.serviceWorker.ready;
					} ).then( function ( reg ) {
						return reg.pushManager.getSubscription().then( function ( existing ) {
							return existing || reg.pushManager.subscribe( { userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array( info.public_key ) } );
						} );
					} ).then( function ( subscription ) {
						return YP.request( api( 'admin/next/push' ), {
							method: 'POST',
							headers: { 'Content-Type': 'application/json' },
							body: JSON.stringify( { subscription: subscription.toJSON(), device: deviceName() } )
						} );
					} ).then( function () {
						return YP.request( api( 'admin/next/push/test' ), { method: 'POST' } );
					} ).then( function () {
						renderPhoneCard( el );
					} ).catch( function ( error ) {
						onBtn.disabled = false;
						onBtn.textContent = 'Turn on alerts';
						window.alert( 'Couldn’t turn on alerts: ' + error.message );
					} );
				} );
			}

			var testBtn = body.querySelector( '[data-ypn-push-test]' );
			if ( testBtn ) {
				testBtn.addEventListener( 'click', function () {
					testBtn.disabled = true;
					YP.request( api( 'admin/next/push/test' ), { method: 'POST' } ).then( function ( r ) {
						testBtn.textContent = r.delivered ? 'Sent' : 'Not delivered';
					} ).catch( function () { testBtn.textContent = 'Not delivered'; } );
				} );
			}

			var offBtn = body.querySelector( '[data-ypn-push-off]' );
			if ( offBtn ) {
				offBtn.addEventListener( 'click', function () {
					offBtn.disabled = true;
					YP.request( api( 'admin/next/push' ), {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( { off: true, endpoint: sub.endpoint } )
					} ).then( function () {
						return sub.unsubscribe();
					} ).then( function () { renderPhoneCard( el ); } ).catch( function () { renderPhoneCard( el ); } );
				} );
			}
		} ).catch( function ( error ) {
			body.innerHTML = '<p class="yp-form__error">Couldn’t check alerts: ' + esc( error.message ) + '</p>';
		} );
	}
}() );
