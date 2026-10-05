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
			'</div>' +
			'<section class="ypn-card ypn-payouts" data-ypn-payouts><h3 class="ypn-card__title">Payouts</h3><p class="yp-field__hint">Loading&hellip;</p></section>'
		);
		loadPayouts( el.querySelector( '[data-ypn-payouts]' ), false );
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

	/* ---------- Today: Payouts (WooPayments balance + payouts) ---------- */

	var PAYOUT_STATUS = {
		pending: [ 'Scheduled', 'ypn-pill--yel' ],
		in_transit: [ 'On the way', 'ypn-pill--cy' ],
		paid: [ 'Paid', 'ypn-pill--grn' ],
		failed: [ 'Failed', 'ypn-pill--red' ],
		canceled: [ 'Canceled', '' ]
	};

	function payoutDay( iso ) {
		var d = iso ? new Date( iso ) : null;
		return d && ! isNaN( d.getTime() ) ? d.toLocaleDateString( undefined, { weekday: 'short', month: 'short', day: 'numeric' } ) : '—';
	}

	function payoutTile( label, value, note, extra ) {
		return '<div class="ypn-payouts__tile' + ( extra || '' ) + '">' +
			'<span class="ypn-payouts__label">' + esc( label ) + '</span>' +
			'<span class="ypn-payouts__value">' + esc( value ) + '</span>' +
			'<span class="ypn-payouts__note">' + esc( note ) + '</span>' +
		'</div>';
	}

	function loadPayouts( card, refresh ) {
		YP.request( api( 'admin/next/payouts' + ( refresh ? '?refresh=1' : '' ) ) ).then( function ( data ) {
			if ( document.body.contains( card ) ) {
				drawPayouts( card, data );
			}
		} ).catch( function ( error ) {
			if ( document.body.contains( card ) ) {
				card.innerHTML = '<h3 class="ypn-card__title">Payouts</h3><p class="yp-form__error">Couldn’t load payouts: ' + esc( error.message ) + '</p>';
			}
		} );
	}

	function drawPayouts( card, data ) {
		var wc = data.woopayments || {};
		var symbol = data.currency_symbol;
		var head = '<h3 class="ypn-card__title">Payouts' +
			( wc.manage_url ? '<a class="ypn-more" href="' + escAttr( wc.manage_url ) + '">Open in WooPayments &rarr;</a>' : '' ) +
		'</h3>';
		var body;

		if ( ! wc.connected ) {
			body = '<div class="ypn-empty"><b>Payouts aren’t available</b>' + esc( wc.error || 'WooPayments didn’t answer.' ) + '</div>';
		} else {
			var next = wc.next;
			var last = wc.last_paid;
			var nextTile = next
				? payoutTile( 'Next payout', money( next.amount, symbol ), ( 'in_transit' === next.status ? 'On the way, ' : 'Scheduled for ' ) + payoutDay( next.date ) + ( wc.bank ? ' · ' + wc.bank : '' ), ' is-next' )
				: payoutTile( 'Next payout', money( wc.available, symbol ), wc.available > 0 ? 'Goes out on the next payout' + ( wc.bank ? ' · ' + wc.bank : '' ) : 'Nothing ready to pay out yet', ' is-next' );

			var rows = ( wc.payouts || [] ).map( function ( p ) {
				var st = PAYOUT_STATUS[ p.status ] || [ p.status, '' ];
				var label = 'withdrawal' === p.type ? ( 'paid' === p.status ? 'Deducted' : 'Withdrawal' ) : st[ 0 ];
				return '<tr>' +
					'<td>' + ( p.url ? '<a href="' + escAttr( p.url ) + '">' + esc( payoutDay( p.date ) ) + '</a>' : esc( payoutDay( p.date ) ) ) + '</td>' +
					'<td><span class="ypn-pill ' + st[ 1 ] + '">' + esc( label ) + '</span></td>' +
					'<td class="ypn-payouts__amt">' + ( 'withdrawal' === p.type ? '−' : '' ) + esc( money( p.amount, symbol ) ) + '</td>' +
				'</tr>';
			} ).join( '' );

			body =
				'<p class="ypn-payouts__sub">Card, Klarna, Afterpay and Affirm money from WooPayments' + ( wc.schedule ? ' · ' + esc( wc.schedule ) : '' ) + '. ' +
					'<button type="button" class="ypn-link" data-ypn-payouts-refresh>Refresh</button></p>' +
				'<div class="ypn-payouts__tiles">' +
					nextTile +
					payoutTile( 'Available', money( wc.available, symbol ), 'Ready for the next payout' ) +
					payoutTile( 'Pending', money( wc.pending, symbol ), 'Still clearing from recent sales' ) +
					payoutTile( 'Last payout', last ? money( last.amount, symbol ) : '—', last ? 'Paid ' + payoutDay( last.date ) : 'No payouts yet' ) +
				'</div>';
		}

		var other = ( data.other || [] );
		var otherRows = other.map( function ( o ) {
			return '<tr><td><span class="ypn-payouts__dot is-' + escAttr( o.id ) + '"></span>' + esc( o.label ) + ( o.orders ? ' <span class="ypn-muted">· ' + o.orders + ( 1 === o.orders ? ' order' : ' orders' ) + '</span>' : '' ) + '</td><td class="ypn-payouts__amt">' + esc( money( o.total, symbol ) ) + '</td></tr>';
		} ).join( '' );

		card.innerHTML = head + body +
			'<div class="ypn-payouts__grid">' +
				( wc.connected
					? '<div><h4 class="ypn-payouts__h">Recent payouts</h4>' +
						( rows ? '<table class="ypn-payouts__table">' + rows + '</table>' : '<p class="ypn-muted">No payouts yet.</p>' ) +
					'</div>'
					: '' ) +
				'<div><h4 class="ypn-payouts__h">Other money in, last ' + esc( String( data.other_days || 7 ) ) + ' days</h4>' +
					'<table class="ypn-payouts__table">' + otherRows + '</table>' +
					'<p class="ypn-payouts__foot">These go straight to you, so there’s no payout to track.</p>' +
				'</div>' +
			'</div>';

		var refresh = card.querySelector( '[data-ypn-payouts-refresh]' );
		if ( refresh ) {
			refresh.addEventListener( 'click', function () {
				refresh.disabled = true;
				refresh.textContent = 'Refreshing…';
				loadPayouts( card, true );
			} );
		}
	}

	/* ---------- Today: Your queue + Ship today ---------- */

	var todayEl = null;
	var todayBoard = null;
	var todayProblems = [];

	// Bar color and order of each kind of queue item, most urgent first.
	var QUEUE_KINDS = {
		problem: { rank: 0, color: '#dc2626' },
		dispute: { rank: 0, color: '#dc2626' },
		proof: { rank: 1, color: 'var(--ypn-mag)' },
		ready: { rank: 2, color: '#7c3aed' },
		approval: { rank: 3, color: 'var(--ypn-yel)' },
		unpaid: { rank: 4, color: '#9a9aa0' },
		message: { rank: 5, color: 'var(--ypn-cy, #00AEEF)' }
	};

	var todayDisputes = [];
	var todayMessages = 0;

	// Disputes waiting on an answer and unread website messages join the
	// queue. Both are optional extras: a failure just leaves them out.
	function loadTodayExtras() {
		YP.request( api( 'admin/disputes' ) ).then( function ( data ) {
			todayDisputes = ( data.disputes || [] ).filter( function ( d ) { return d.needs_response; } );
			drawToday();
		} ).catch( function () {} );
		YP.request( api( 'admin/messages?filter=new' ) ).then( function ( data ) {
			todayMessages = data.new_count || 0;
			drawToday();
		} ).catch( function () {} );
	}

	function loadToday() {
		loadTodayExtras();
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

		todayDisputes.forEach( function ( d ) {
			items.push( {
				kind: 'dispute',
				href: '#/disputes/' + d.id,
				title: 'Answer the card dispute on ' + ( d.order_number ? 'order ' + d.order_number : money( d.amount ) ),
				meta: ( d.customer_name || 'Customer' ) + ' · ' + money( d.amount ) + ( d.due_by ? ' · respond by ' + new Date( d.due_by ).toLocaleDateString( undefined, { month: 'short', day: 'numeric' } ) : '' ),
				action: 'Respond',
				date: d.due_by
			} );
		} );

		if ( todayMessages ) {
			items.push( {
				kind: 'message',
				href: '#/messages',
				title: todayMessages + ( 1 === todayMessages ? ' new message' : ' new messages' ) + ' from the website',
				meta: 'Contact form and web design quote requests',
				action: 'Read'
			} );
		}

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
			var aHot = 'problem' === a.kind || 'dispute' === a.kind;
			var bHot = 'problem' === b.kind || 'dispute' === b.kind;
			if ( aHot !== bHot ) {
				return aHot ? -1 : 1;
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
				var href = item.href || '#/order/' + item.id;
				return (
					'<div class="ypn-q' + ( item.express || 'problem' === item.kind || 'dispute' === item.kind ? ' is-hot' : '' ) + '">' +
						'<i style="background:' + QUEUE_KINDS[ item.kind ].color + '"></i>' +
						'<a class="ypn-q__text" href="' + escAttr( href ) + '"><b>' + esc( item.title ) + ( item.express ? ' <span class="ypn-tag-express">EXPRESS</span>' : '' ) + '</b><span>' + esc( item.meta ) + '</span></a>' +
						( item.print
							? '<button type="button" class="ypn-btn" data-ypn-q-print="' + item.id + '">' + esc( item.action ) + '</button>'
							: '<a class="ypn-btn" href="' + escAttr( href ) + '">' + esc( item.action ) + '</a>' ) +
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
					window.location.hash = '#/ship/' + parseInt( label.getAttribute( 'data-ypn-q-label' ), 10 );
				}
			} );
		}
	}

	/* ---------- Production board ---------- */

	var COLUMNS = [
		{ id: 'unpaid', label: 'Unpaid', hint: 'Waiting for payment' },
		{ id: 'design', label: 'In design', hint: 'Websites being built' },
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

	// Websites are built, then go live; nothing to print or ship.
	var WEB_STEPS = [ 'Placed', 'Paid', 'In design', 'Live' ];
	var WEB_STEP_FOR_STATUS = { 'checkout-draft': 0, pending: 1, 'on-hold': 1, processing: 2, 'in-design': 2, completed: 4 };

	/**
	 * `proof` is '' (no custom design on the order, or every proof
	 * approved), 'needs_proof' or 'proof_sent', same as the board's.
	 * `hasDesign` switches to the proof steps.
	 */
	function progressHtml( order, proof, hasDesign ) {
		var steps = order.web_design ? WEB_STEPS : ( hasDesign ? PROOF_STEPS : STEPS );
		var current;

		if ( order.web_design ) {
			current = WEB_STEP_FOR_STATUS[ order.status ];
		} else if ( hasDesign ) {
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
			if ( 'Printing' === step && 'processing' === order.status && i === current && ! order.web_design ) {
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
		if ( 'processing' === order.status && ! order.web_design ) {
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
				( item.brands && item.brands.length ? '<div class="ypn-lab__brand">Brand: ' + esc( item.brands.join( ', ' ) ) + '</div>' : '' ) +
				'<div class="ypn-lab__meta">× ' + esc( String( item.quantity ) ) + ' · ' + esc( money( item.total ) ) + '</div>' +
				( item.meta.length
					// display_value is WooCommerce's own kses-filtered HTML (batch tables, color swatches), rendered the same way the order window does.
					// Each label/value pair is its own grid cell so Details spreads across the card;
					// a batch label's field boxes (.yp-order-fields) or a table get a full row.
					? '<details class="ypn-lab__more"><summary>Details</summary><dl>' + item.meta.map( function ( m ) {
						var wide = /yp-order-fields|<table/.test( String( m.value ) );
						return '<div class="ypn-lab__f' + ( wide ? ' is-wide' : '' ) + '"><dt>' + esc( m.label ) + '</dt><dd>' + m.value + '</dd></div>';
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

	/* Order notes: WooCommerce's own notes (status changes, payments,
	   staff notes), newest first. A note can be private or sent to the
	   customer, who gets it by email. */
	function orderNotesHtml( notes ) {
		return '<div class="ypn-notes" data-ypn-notes-list>' + ( notes.length ? notes.map( function ( n ) {
			return '<div class="ypn-note' + ( n.customer ? ' is-customer' : ( n.by ? '' : ' is-system' ) ) + '">' +
				'<div class="ypn-note__text">' + n.content + '</div>' +
				'<div class="ypn-note__meta">' +
					( n.customer ? '<span class="ypn-pill ypn-pill--cy">Sent to customer</span>' : '' ) +
					'<span>' + esc( n.by || 'System' ) + ( n.date ? ' · ' + esc( new Date( n.date ).toLocaleString( undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' } ) ) : '' ) + '</span>' +
					'<button type="button" class="ypn-link" data-ypn-note-del="' + n.id + '">Delete</button>' +
				'</div>' +
			'</div>';
		} ).join( '' ) : '<p class="yp-field__hint">No notes yet.</p>' ) + '</div>';
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
			var website   = !! order.web_design;
			var printable = 'processing' === order.status && ! website;
			var shipStage = ! website && [ 'processing', 'in-production', 'shipped', 'completed' ].indexOf( order.status ) !== -1;
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
			} else if ( printable ) {
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
			actions.push( '<button type="button" class="ypn-act" data-ypn-act="details">Refunds &amp; all details <span>›</span></button>' );

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
							// Agreement, milestones, staging, go-live, showcase and progress reports (app.js).
							( website ? '<div class="ypn-wd" data-ypn-wd></div>' : '' ) +
							customOrders.map( proofCardHtml ).join( '' ) +
							'<section class="ypn-card">' +
								'<h3 class="ypn-card__title">' + ( website ? 'Items' : 'Labels &amp; items' ) + ' <span>' + order.items.length + ( 1 === order.items.length ? ' line' : ' lines' ) + ' · ' + units + ' total</span></h3>' +
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
							'<section class="ypn-card ypn-ordernotes"><h3 class="ypn-card__title">Order notes <span>' + ( order.order_notes || [] ).length + '</span></h3>' +
								'<div class="ypn-note-add">' +
									'<textarea rows="2" placeholder="Add a note&hellip;" data-ypn-note-text></textarea>' +
									'<div class="ypn-note-add__row">' +
										'<label class="ypn-check"><input type="checkbox" data-ypn-note-customer> Email it to the customer</label>' +
										'<button type="button" class="ypn-btn" data-ypn-note-add>Add note</button>' +
									'</div>' +
									'<div data-ypn-note-error></div>' +
								'</div>' +
								orderNotesHtml( order.order_notes || [] ) +
							'</section>' +
						'</div>' +
						'<aside class="ypn-op__side">' +
							'<section class="ypn-card ypn-acts"><h3 class="ypn-card__title">Actions</h3>' + actions.join( '' ) + '</section>' +
							'<section class="ypn-card" data-ypn-shipto><h3 class="ypn-card__title">Ship to <button type="button" class="ypn-more" data-ypn-edit-details>Edit</button></h3>' + kv( [
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
							: ( printable
								? '<button type="button" class="ypn-btn ypn-btn--primary" data-ypn-act="print">Send to printer</button>'
								: '<button type="button" class="ypn-btn ypn-btn--primary" data-ypn-act="details">All details</button>' ) ) +
					'</div>' +
				'</div>';

			bind( order, needsProofFor );
		}

		function bind( order, needsProofFor ) {
			bindNotes( order );
			var wd = viewEl.querySelector( '[data-ypn-wd]' );
			if ( wd && YP.loadWebDesignPanel ) {
				YP.loadWebDesignPanel( order, wd );
			}
			viewEl.querySelector( '[data-ypn-edit-details]' ).addEventListener( 'click', function () { editDetails( order ); } );

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
						window.location.hash = '#/ship/' + order.id;
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

		function bindNotes( order ) {
			var card = viewEl.querySelector( '.ypn-ordernotes' );
			var text = card.querySelector( '[data-ypn-note-text]' );
			var add = card.querySelector( '[data-ypn-note-add]' );
			var errorEl = card.querySelector( '[data-ypn-note-error]' );

			function redraw( notes ) {
				order.order_notes = notes;
				card.querySelector( '[data-ypn-notes-list]' ).outerHTML = orderNotesHtml( notes );
				card.querySelector( '.ypn-card__title span' ).textContent = String( notes.length );
			}

			add.addEventListener( 'click', function () {
				var note = text.value.trim();
				var customerBox = card.querySelector( '[data-ypn-note-customer]' );
				if ( ! note ) {
					return;
				}
				add.disabled = true;
				errorEl.innerHTML = '';
				YP.request( api( 'admin/order/' + order.id + '/notes' ), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { note: note, customer: customerBox.checked } )
				} ).then( function ( notes ) {
					text.value = '';
					customerBox.checked = false;
					redraw( notes );
				} ).catch( function ( error ) {
					errorEl.innerHTML = '<p class="yp-form__error">' + esc( error.message ) + '</p>';
				} ).then( function () { add.disabled = false; } );
			} );

			card.addEventListener( 'click', function ( event ) {
				var del = event.target.closest( '[data-ypn-note-del]' );
				if ( ! del ) {
					return;
				}
				YP.confirmModal( {
					title: 'Delete this note?',
					message: 'It’s removed from the order. A note already emailed to the customer stays in their inbox.',
					confirmLabel: 'Delete note',
					danger: true,
					onConfirm: function () {
						YP.request( api( 'admin/order/' + order.id + '/notes/' + del.getAttribute( 'data-ypn-note-del' ) ), { method: 'DELETE' } )
							.then( redraw )
							.catch( function ( error ) { window.alert( 'Couldn’t delete the note: ' + error.message ); } );
					}
				} );
			} );
		}

		/* Edit the customer's contact details and shipping address on any
		   order, paid or not, in place of the Ship to card. */
		function editDetails( order ) {
			var card = viewEl.querySelector( '[data-ypn-shipto]' );
			var hasShipping = !! ( order.shipping && order.shipping.address_1 );
			card.innerHTML =
				'<h3 class="ypn-card__title">Customer details</h3>' +
				'<h4 class="ypn-form__h">Contact &amp; billing</h4>' + YP.addressFieldsHtml( 'billing', order.billing ) +
				'<h4 class="ypn-form__h">Ship to</h4>' +
				'<label class="ypn-check"><input type="checkbox" data-ypn-same-address' + ( hasShipping ? '' : ' checked' ) + '> Same as billing</label>' +
				'<div data-ypn-ship-fields' + ( hasShipping ? '' : ' hidden' ) + '>' + YP.addressFieldsHtml( 'shipping', order.shipping ) + '</div>' +
				'<div data-ypn-details-error></div>' +
				'<div class="ypn-form__acts"><button type="button" class="ypn-btn ypn-btn--primary" data-ypn-details-save>Save</button><button type="button" class="ypn-btn" data-ypn-details-cancel>Cancel</button></div>' +
				'<p class="ypn-muted">Changes this order only. A shipping label already bought keeps the old address.</p>';

			var same = card.querySelector( '[data-ypn-same-address]' );
			same.addEventListener( 'change', function () {
				card.querySelector( '[data-ypn-ship-fields]' ).hidden = same.checked;
			} );
			card.querySelector( '[data-ypn-details-cancel]' ).addEventListener( 'click', load );
			card.querySelector( '[data-ypn-details-save]' ).addEventListener( 'click', function ( event ) {
				var button = event.currentTarget;
				var billing = YP.readAddressFields( card, 'billing' );
				var shipping = YP.readAddressFields( card, 'shipping' );
				if ( same.checked ) {
					shipping = {};
					[ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ].forEach( function ( key ) {
						shipping[ key ] = billing[ key ] || '';
					} );
				}
				button.disabled = true;
				button.textContent = 'Saving…';
				YP.request( api( 'admin/order/' + order.id + '/details' ), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { billing: billing, shipping: shipping } )
				} ).then( load ).catch( function ( error ) {
					button.disabled = false;
					button.textContent = 'Save';
					card.querySelector( '[data-ypn-details-error]' ).innerHTML = '<p class="yp-form__error">' + esc( error.message ) + '</p>';
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

	/* ---------- Shipping label (#/ship/{id}) ----------
	   Buy and print a Shippo label without the order window: labels
	   already bought (print here, send to the label printer, void), then
	   package size → rates → buy. Same endpoints the order window's
	   Shippo panel uses (class-admin-shippo-controller.php). */

	function queueLabel( orderId, tracking, kind ) {
		return YP.request( api( 'admin/next/print-queue' ), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { order_id: orderId, tracking_number: tracking, kind: kind || 'label' } )
		} );
	}

	function stationStatus() {
		return YP.request( api( 'admin/next/print-queue' ) ).catch( function () { return { station_online: false, jobs: [] }; } );
	}

	YP.views.ship = function ( viewEl, subId ) {
		var id = parseInt( subId, 10 );
		var titleEl = document.querySelector( '[data-yp-title]' );
		if ( ! id ) {
			window.location.hash = '#/production';
			return;
		}

		var order = null;
		var station = { station_online: false };
		var rates = [];
		var carrier = '';
		var picked = '';
		var bestMatch = null;

		viewEl.innerHTML = '<p class="yp-field__hint">Loading&hellip;</p>';

		function load() {
			Promise.all( [ YP.request( api( 'admin/order/' + id ) ), stationStatus() ] ).then( function ( results ) {
				order = results[ 0 ];
				station = results[ 1 ];
				draw();
			} ).catch( function ( error ) {
				viewEl.innerHTML = '<p class="yp-form__error">Couldn’t load this order: ' + esc( error.message ) + '</p>';
			} );
		}

		function labelRowHtml( label ) {
			var invoice = label.customs && label.customs.commercial_invoice_url && ! label.customs.sent_electronically;
			return (
				'<div class="ypn-shiplabel' + ( label.voided ? ' is-voided' : '' ) + '">' +
					'<div class="ypn-shiplabel__text"><b>' + esc( label.carrier_label ) + '</b><span class="ypn-mono">' + esc( label.tracking_number ) + '</span>' +
						( label.voided ? '<span class="ypn-pill">Voided</span>' : '' ) +
						( label.customs && label.customs.sent_electronically ? '<span class="ypn-pill ypn-pill--grn">Customs sent electronically</span>' : '' ) +
					'</div>' +
					( label.voided ? '' :
						'<div class="ypn-shiplabel__acts">' +
							'<button type="button" class="ypn-btn' + ( station.station_online ? ' ypn-btn--primary' : '' ) + '" data-ypn-queue="' + escAttr( label.tracking_number ) + '">Send to label printer</button>' +
							'<button type="button" class="ypn-btn' + ( station.station_online ? '' : ' ypn-btn--primary' ) + '" data-ypn-print-here="' + escAttr( label.label_url ) + '">Print here</button>' +
							( invoice ? '<button type="button" class="ypn-btn" data-ypn-queue-invoice="' + escAttr( label.tracking_number ) + '">Send customs invoice</button>' : '' ) +
							'<button type="button" class="ypn-link ypn-bad" data-ypn-void="' + escAttr( label.tracking_number ) + '" data-carrier="' + escAttr( label.carrier_label ) + '">Void</button>' +
						'</div>' ) +
				'</div>'
			);
		}

		function stationLine() {
			return station.station_online
				? '<p class="ypn-station is-on"><i></i>Label printer is ready. <a href="#/print-station">Print station</a></p>'
				: '<p class="ypn-station"><i></i>Label printer isn’t connected. Open the <a href="#/print-station">Print station</a> on the computer it’s plugged into, or use Print here.</p>';
		}

		function draw() {
			if ( ! document.body.contains( viewEl ) ) {
				return;
			}
			if ( titleEl ) {
				titleEl.textContent = 'Ship ' + ( /^\d+$/.test( String( order.number ) ) ? '#' : '' ) + order.number;
			}

			var labels = order.shippo_labels || [];
			var active = labels.filter( function ( l ) { return ! l.voided; } );
			var pkg = order.shippo_default_package || {};
			var customs = order.shippo_customs || {};
			var addr = order.needs_customer_address
				? '<span class="ypn-pill ypn-pill--yel">Customer hasn’t added an address yet</span>'
				: '<span class="ypn-pre">' + esc( addressText( order.shipping_address ) ) + '</span>';

			var buy;
			if ( ! order.shippo_configured ) {
				buy = '<p class="yp-field__hint">Shippo isn’t set up yet. Add your API token under <a href="#/settings/shipping">Settings › Shipping</a> to buy labels here.</p>' +
					( order.shipping_label_available ? '<button type="button" class="ypn-btn" data-ypn-wcs>Use WooCommerce Shipping instead</button>' : '' );
			} else {
				buy =
					'<div class="ypn-dims">' +
						[ [ 'weight_oz', 'Weight', 'oz' ], [ 'length_in', 'Length', 'in' ], [ 'width_in', 'Width', 'in' ], [ 'height_in', 'Height', 'in' ] ].map( function ( f ) {
							return '<label><span>' + f[ 1 ] + '</span><span class="ypn-dims__in"><input type="number" inputmode="decimal" min="0.1" step="0.1" data-ypn-dim="' + f[ 0 ] + '" value="' + escAttr( pkg[ f[ 0 ] ] ) + '"><i>' + f[ 2 ] + '</i></span></label>';
						} ).join( '' ) +
					'</div>' +
					( customs.international
						? '<div class="ypn-dims ypn-dims--two">' +
							'<label><span>Customs contents</span><input type="text" data-ypn-customs-desc value="' + escAttr( customs.description ) + '"></label>' +
							'<label><span>Value (' + esc( customs.currency || 'USD' ) + ')</span><input type="number" inputmode="decimal" min="0.01" step="0.01" data-ypn-customs-value value="' + escAttr( customs.value ) + '"></label>' +
						'</div>'
						: '' ) +
					'<button type="button" class="ypn-btn ypn-ship__rates-btn" data-ypn-rates>' + ( rates.length ? 'Refresh rates' : 'Get rates' ) + '</button>' +
					'<p class="ypn-ship__hint">Comparing rates is free. Buying a label charges your Shippo account.</p>' +
					'<div data-ypn-rate-list>' + ratesHtml() + '</div>' +
					'<div data-ypn-ship-error></div>';
			}

			viewEl.innerHTML =
				'<div class="ypn-ship">' +
					'<div class="ypn-op__sub">' +
						( order.express ? '<span class="ypn-tag-express">EXPRESS</span>' : '' ) +
						'<span>' + esc( order.customer_name || 'Guest' ) + ( order.shipping_method ? ' picked ' + esc( order.shipping_method ) : '' ) + '</span>' +
						'<a class="ypn-link" href="#/order/' + order.id + '">Open order</a>' +
					'</div>' +
					'<div class="ypn-op__grid">' +
						'<div class="ypn-op__main">' +
							( labels.length
								? '<section class="ypn-card"><h3 class="ypn-card__title">Labels on this order <span>' + active.length + ' active</span></h3>' + stationLine() + labels.map( labelRowHtml ).join( '' ) + '</section>'
								: '' ) +
							'<section class="ypn-card"><h3 class="ypn-card__title">' + ( active.length ? 'Buy another label' : 'Buy a label' ) + '</h3>' + buy + '</section>' +
						'</div>' +
						'<aside class="ypn-op__side">' +
							'<section class="ypn-card"><h3 class="ypn-card__title">Ship to</h3>' + kv( [
								[ 'Name', esc( order.customer_name || '—' ) ],
								[ 'Address', addr ],
								order.customer_phone ? [ 'Phone', esc( order.customer_phone ) ] : null,
								[ 'Picked', esc( order.shipping_method || '—' ) ]
							] ) + '</section>' +
							( labels.length ? '' : '<section class="ypn-card">' + stationLine() + '</section>' ) +
						'</aside>' +
					'</div>' +
					( rates.length ? '<div class="ypn-op__sticky ypn-ship__sticky"><button type="button" class="ypn-btn ypn-btn--primary" data-ypn-buy>' + buyLabel() + '</button></div>' : '' ) +
				'</div>';

			bind();
		}

		function visibleRates() {
			return carrier ? rates.filter( function ( r ) { return r.carrier_label === carrier; } ) : rates;
		}

		function pickedRate() {
			return rates.filter( function ( r ) { return r.id === picked; } )[ 0 ] || null;
		}

		function buyLabel() {
			var rate = pickedRate();
			return rate ? 'Buy ' + esc( rate.carrier_label ) + ' label · ' + esc( money( rate.amount ) ) : 'Pick a rate';
		}

		function ratesHtml() {
			if ( ! rates.length ) {
				return '';
			}
			var carriers = [];
			rates.forEach( function ( r ) {
				if ( carriers.indexOf( r.carrier_label ) === -1 ) {
					carriers.push( r.carrier_label );
				}
			} );
			var list = visibleRates();
			if ( ! list.some( function ( r ) { return r.id === picked; } ) ) {
				picked = list.length ? ( bestMatch && list.some( function ( r ) { return r.id === bestMatch; } ) ? bestMatch : list[ 0 ].id ) : '';
			}
			return (
				( carriers.length > 1
					? '<div class="ypn-seg ypn-seg--show">' +
						'<button type="button" class="' + ( carrier ? '' : 'is-on' ) + '" data-ypn-carrier="">All</button>' +
						carriers.map( function ( c ) {
							return '<button type="button" class="' + ( c === carrier ? 'is-on' : '' ) + '" data-ypn-carrier="' + escAttr( c ) + '">' + esc( c ) + '</button>';
						} ).join( '' ) +
					'</div>'
					: '' ) +
				'<div class="ypn-rates">' + list.map( function ( r ) {
					return (
						'<label class="ypn-rate' + ( r.id === picked ? ' is-on' : '' ) + '">' +
							'<input type="radio" name="ypn-rate" value="' + escAttr( r.id ) + '"' + ( r.id === picked ? ' checked' : '' ) + '>' +
							'<span class="ypn-rate__text"><b>' + esc( r.carrier_label ) + ' ' + esc( r.service ) + '</b>' +
								'<span>' + ( r.days ? r.days + ( 1 === r.days ? ' day' : ' days' ) : 'Delivery time not given' ) + ( r.id === bestMatch ? ' · <em>Customer’s choice</em>' : '' ) + '</span></span>' +
							'<span class="ypn-rate__price">' + esc( money( r.amount ) ) + '</span>' +
						'</label>'
					);
				} ).join( '' ) + '</div>' +
				'<button type="button" class="ypn-btn ypn-btn--primary ypn-ship__buy" data-ypn-buy>' + buyLabel() + '</button>'
			);
		}

		function redrawRates() {
			var el = viewEl.querySelector( '[data-ypn-rate-list]' );
			if ( el ) {
				el.innerHTML = ratesHtml();
			}
			var sticky = viewEl.querySelector( '.ypn-ship__sticky [data-ypn-buy]' );
			if ( sticky ) {
				sticky.innerHTML = buyLabel();
			}
		}

		function getRates( button ) {
			var defaults = order.shippo_default_package || {};
			var parcel = {};
			viewEl.querySelectorAll( '[data-ypn-dim]' ).forEach( function ( input ) {
				var key = input.getAttribute( 'data-ypn-dim' );
				parcel[ key ] = parseFloat( input.value ) || defaults[ key ];
			} );
			var desc = viewEl.querySelector( '[data-ypn-customs-desc]' );
			if ( desc ) {
				parcel.customs_description = desc.value;
				parcel.customs_value = viewEl.querySelector( '[data-ypn-customs-value]' ).value;
			}
			order.shippo_default_package = parcel;

			button.disabled = true;
			button.textContent = 'Getting rates…';
			viewEl.querySelector( '[data-ypn-ship-error]' ).innerHTML = '';

			YP.request( api( 'admin/order/' + id + '/shippo/rates' ), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( parcel )
			} ).then( function ( response ) {
				rates = response.rates || [];
				carrier = '';
				picked = '';
				bestMatch = YP.findBestMatchingRateId ? YP.findBestMatchingRateId( rates, order.shipping_method ) : null;
				draw();
				if ( ! rates.length ) {
					viewEl.querySelector( '[data-ypn-ship-error]' ).innerHTML = '<p class="yp-field__hint">No rates came back for this address and package.</p>';
				}
			} ).catch( function ( error ) {
				button.disabled = false;
				button.textContent = 'Get rates';
				viewEl.querySelector( '[data-ypn-ship-error]' ).innerHTML = '<p class="yp-form__error">' + esc( error.message ) + '</p>';
			} );
		}

		function buy() {
			var rate = pickedRate();
			if ( ! rate ) {
				return;
			}
			YP.confirmModal( {
				title: 'Buy this label?',
				message: rate.carrier_label + ' ' + rate.service + ' for ' + money( rate.amount ) + '. This charges your Shippo account right away.' +
					( station.station_online ? ' It will print on your label printer.' : '' ),
				confirmLabel: 'Buy label',
				onConfirm: function () {
					// Opened now, inside the tap, so the browser allows it; filled in once the label exists.
					var printWindow = station.station_online ? null : window.open( '', '_blank' );
					viewEl.querySelectorAll( '[data-ypn-buy]' ).forEach( function ( b ) { b.disabled = true; b.textContent = 'Buying…'; } );

					YP.request( api( 'admin/order/' + id + '/shippo/purchase' ), {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( { rate_id: rate.id, carrier_id: rate.carrier_id, carrier_label: rate.carrier_label } )
					} ).then( function ( response ) {
						var label = response.label || {};
						rates = [];
						if ( station.station_online && label.tracking_number ) {
							return queueLabel( id, label.tracking_number ).catch( function () {} ).then( load );
						}
						if ( printWindow && label.label_url ) {
							printWindow.location.href = label.label_url;
						} else if ( printWindow ) {
							printWindow.close();
						}
						load();
					} ).catch( function ( error ) {
						if ( printWindow ) {
							printWindow.close();
						}
						viewEl.querySelectorAll( '[data-ypn-buy]' ).forEach( function ( b ) { b.disabled = false; b.innerHTML = buyLabel(); } );
						viewEl.querySelector( '[data-ypn-ship-error]' ).innerHTML = '<p class="yp-form__error">Couldn’t buy the label: ' + esc( error.message ) + '</p>';
					} );
				}
			} );
		}

		function sendToPrinter( button, tracking, kind ) {
			button.disabled = true;
			button.textContent = 'Sending…';
			queueLabel( id, tracking, kind ).then( function ( r ) {
				button.textContent = r.station_online ? 'Sent to printer ✓' : 'Queued, prints when the station is open';
			} ).catch( function ( error ) {
				button.disabled = false;
				button.textContent = 'Send to label printer';
				window.alert( 'Couldn’t send it: ' + error.message );
			} );
		}

		function bind() {
			var ratesBtn = viewEl.querySelector( '[data-ypn-rates]' );
			if ( ratesBtn ) {
				ratesBtn.addEventListener( 'click', function () { getRates( ratesBtn ); } );
			}
			var wcs = viewEl.querySelector( '[data-ypn-wcs]' );
			if ( wcs ) {
				wcs.addEventListener( 'click', function () { YP.openWcOrderDrawer( id, true ); } );
			}
		}

		viewEl.addEventListener( 'click', function ( event ) {
			var t = event.target;
			var el;
			if ( ( el = t.closest( '[data-ypn-carrier]' ) ) ) {
				carrier = el.getAttribute( 'data-ypn-carrier' );
				redrawRates();
			} else if ( ( el = t.closest( '.ypn-rate' ) ) ) {
				var input = el.querySelector( 'input' );
				if ( input && input.value !== picked ) {
					picked = input.value;
					redrawRates();
				}
			} else if ( ( el = t.closest( '[data-ypn-buy]' ) ) ) {
				buy();
			} else if ( ( el = t.closest( '[data-ypn-print-here]' ) ) ) {
				YP.printLabelUrl( el.getAttribute( 'data-ypn-print-here' ) );
			} else if ( ( el = t.closest( '[data-ypn-queue]' ) ) ) {
				sendToPrinter( el, el.getAttribute( 'data-ypn-queue' ), 'label' );
			} else if ( ( el = t.closest( '[data-ypn-queue-invoice]' ) ) ) {
				sendToPrinter( el, el.getAttribute( 'data-ypn-queue-invoice' ), 'invoice' );
			} else if ( ( el = t.closest( '[data-ypn-void]' ) ) ) {
				var tracking = el.getAttribute( 'data-ypn-void' );
				YP.confirmModal( {
					title: 'Void this label?',
					message: 'Void the ' + el.getAttribute( 'data-carrier' ) + ' label ' + tracking + '? Shippo refunds unused labels, but the carrier decides.',
					confirmLabel: 'Void label',
					danger: true,
					onConfirm: function () {
						YP.request( api( 'admin/order/' + id + '/shippo/void' ), {
							method: 'POST',
							headers: { 'Content-Type': 'application/json' },
							body: JSON.stringify( { tracking_number: tracking } )
						} ).then( load ).catch( function ( error ) { window.alert( 'Couldn’t void it: ' + error.message ); } );
					}
				} );
			}
		} );

		load();
	};

	/* ---------- Print station (#/print-station) ----------
	   Left open on the computer the label printer is plugged into. It
	   checks the print queue every few seconds and prints whatever a
	   phone sent ("Send to label printer"). Chrome started with
	   --kiosk-printing prints straight to the default printer. */

	var stationTimer = null;

	YP.views[ 'print-station' ] = function ( viewEl ) {
		var running = false;
		var printed = {};
		var wakeLock = null;

		viewEl.innerHTML =
			'<div class="ypn-op__grid">' +
				'<div class="ypn-op__main">' +
					'<section class="ypn-card ypn-station-card">' +
						'<h3 class="ypn-card__title">Print station <span data-ypn-station-state>Off</span></h3>' +
						'<p>Use this on the computer your label printer is plugged into. While it’s on, labels you send from your phone print here automatically.</p>' +
						'<button type="button" class="ypn-btn ypn-btn--primary" data-ypn-station-toggle>Start printing here</button>' +
						'<button type="button" class="ypn-btn" data-ypn-station-test hidden>Print the last label again</button>' +
					'</section>' +
					'<section class="ypn-card"><h3 class="ypn-card__title">Recent print jobs</h3><div data-ypn-jobs><p class="yp-field__hint">Loading&hellip;</p></div></section>' +
				'</div>' +
				'<aside class="ypn-op__side">' +
					'<section class="ypn-card"><h3 class="ypn-card__title">One-time setup</h3>' +
						'<ol class="ypn-steps">' +
							'<li>Make your label printer the <b>default printer</b> on this computer, with 4×6 paper.</li>' +
							'<li>Make a Chrome shortcut that starts with <b>--kiosk-printing</b> so labels print without a dialog (details below).</li>' +
							'<li>Open YeffoDesign (new) › Settings › <b>Print station</b> in that Chrome and tap <b>Start printing here</b>. Leave the window open.</li>' +
						'</ol>' +
						'<details class="ypn-howto"><summary>Mac</summary><p>Open Terminal once and run:</p><code>open -na "Google Chrome" --args --kiosk-printing</code></details>' +
						'<details class="ypn-howto"><summary>Windows</summary><p>Right-click your Chrome shortcut › Properties. At the end of <b>Target</b>, after the quotes, add a space and <code>--kiosk-printing</code>. Close all Chrome windows, then open Chrome from that shortcut.</p></details>' +
						'<p class="yp-field__hint">Without the shortcut it still works, you just click Print on each label.</p>' +
					'</section>' +
				'</aside>' +
			'</div>';

		var stateEl  = viewEl.querySelector( '[data-ypn-station-state]' );
		var toggle   = viewEl.querySelector( '[data-ypn-station-toggle]' );
		var testBtn  = viewEl.querySelector( '[data-ypn-station-test]' );
		var jobsEl   = viewEl.querySelector( '[data-ypn-jobs]' );

		/* Loads the job's PDF with the nonce header (refreshed if the
		   page's own has expired) and resolves to a local blob URL, or
		   rejects if the site sent anything but a PDF. The station only
		   ever prints that blob, so an error message can never reach
		   the printer. */
		function loadPdf( job, isRetry ) {
			return fetch( api( 'admin/next/print-queue/' + job.id + '/file' ), {
				credentials: 'same-origin',
				cache: 'no-store',
				headers: { 'X-WP-Nonce': yeffoprintAdminApp.nonce }
			} ).then( function ( response ) {
				if ( 403 === response.status && ! isRetry ) {
					return YP.refreshNonce().then( function () { return loadPdf( job, true ); } );
				}
				var type = response.headers.get( 'Content-Type' ) || '';
				if ( ! response.ok || type.indexOf( 'pdf' ) === -1 ) {
					throw new Error( 'not a pdf' );
				}
				return response.blob().then( function ( blob ) {
					return URL.createObjectURL( new Blob( [ blob ], { type: 'application/pdf' } ) );
				} );
			} );
		}

		function setJob( job, status ) {
			return YP.request( api( 'admin/next/print-queue/' + job.id ), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { status: status } )
			} ).catch( function () {} );
		}

		function printJob( job ) {
			return loadPdf( job ).then( function ( pdfUrl ) {
				return new Promise( function ( resolve ) {
					var frame = document.createElement( 'iframe' );
					frame.className = 'ypn-print-frame';
					frame.src = pdfUrl;
					var done = false;
					function finish( status ) {
						if ( done ) {
							return;
						}
						done = true;
						setJob( job, status ).then( resolve );
						window.setTimeout( function () { frame.remove(); URL.revokeObjectURL( pdfUrl ); }, 60000 );
					}
					frame.addEventListener( 'load', function () {
						window.setTimeout( function () {
							try {
								frame.contentWindow.focus();
								frame.contentWindow.print();
								finish( 'printed' );
							} catch ( e ) {
								finish( 'failed' );
							}
						}, 700 );
					} );
					window.setTimeout( function () { finish( 'failed' ); }, 30000 );
					document.body.appendChild( frame );
				} );
			}, function () {
				return setJob( job, 'failed' );
			} );
		}

		function jobsHtml( jobs ) {
			if ( ! jobs.length ) {
				return '<div class="ypn-empty"><span>Nothing sent yet. Tap “Send to label printer” on an order’s shipping label screen.</span></div>';
			}
			var LABELS = { pending: [ 'Waiting', 'yel' ], printed: [ 'Printed', 'grn' ], failed: [ 'Didn’t print', 'mag' ], cancelled: [ 'Cancelled', '' ] };
			return jobs.slice( 0, 12 ).map( function ( job ) {
				var s = LABELS[ job.status ] || [ job.status, '' ];
				return (
					'<div class="ypn-q">' +
						'<a class="ypn-q__text" href="#/ship/' + job.order_id + '"><b>' + ( 'invoice' === job.kind ? 'Customs invoice' : 'Label' ) + ' for ' + esc( ( /^\d+$/.test( job.number ) ? '#' : '' ) + job.number ) + '</b><span>' + esc( ( job.customer || '' ) + ' · ' + ago( job.created ) + ' ago' ) + '</span></a>' +
						'<span class="ypn-pill ypn-pill--' + s[ 1 ] + '">' + esc( s[ 0 ] ) + '</span>' +
						( 'pending' !== job.status ? '<button type="button" class="ypn-btn" data-ypn-reprint="' + escAttr( job.id ) + '">Again</button>' : '' ) +
					'</div>'
				);
			} ).join( '' );
		}

		var lastJobs = [];

		function refreshList() {
			return YP.request( api( 'admin/next/print-queue' ) ).then( function ( data ) {
				lastJobs = data.jobs;
				jobsEl.innerHTML = jobsHtml( data.jobs );
				testBtn.hidden = ! running || ! data.jobs.some( function ( j ) { return 'label' === j.kind; } );
			} ).catch( function ( error ) {
				jobsEl.innerHTML = '<p class="yp-form__error">' + esc( error.message ) + '</p>';
			} );
		}

		function tick() {
			if ( ! running || ! document.body.contains( viewEl ) ) {
				stop();
				return;
			}
			YP.request( api( 'admin/next/print-queue?station=1' ) ).then( function ( data ) {
				var queue = data.jobs.filter( function ( j ) { return ! printed[ j.id ]; } ).reverse();
				var chain = Promise.resolve();
				queue.forEach( function ( job ) {
					printed[ job.id ] = true;
					chain = chain.then( function () { return printJob( job ); } );
				} );
				return chain.then( refreshList );
			} ).catch( function () {
				stateEl.textContent = 'Can’t reach the site, retrying';
			} ).then( function () {
				if ( running ) {
					stateEl.textContent = 'On, waiting for labels';
					stationTimer = window.setTimeout( tick, 4000 );
				}
			} );
		}

		function start() {
			running = true;
			toggle.textContent = 'Stop printing here';
			toggle.classList.remove( 'ypn-btn--primary' );
			stateEl.textContent = 'On, waiting for labels';
			viewEl.querySelector( '.ypn-station-card' ).classList.add( 'is-on' );
			if ( navigator.wakeLock ) {
				navigator.wakeLock.request( 'screen' ).then( function ( lock ) { wakeLock = lock; } ).catch( function () {} );
			}
			tick();
		}

		function stop() {
			running = false;
			window.clearTimeout( stationTimer );
			if ( wakeLock ) {
				wakeLock.release().catch( function () {} );
				wakeLock = null;
			}
			if ( document.body.contains( viewEl ) ) {
				toggle.textContent = 'Start printing here';
				toggle.classList.add( 'ypn-btn--primary' );
				stateEl.textContent = 'Off';
				viewEl.querySelector( '.ypn-station-card' ).classList.remove( 'is-on' );
			}
		}

		window.clearTimeout( stationTimer );

		toggle.addEventListener( 'click', function () {
			if ( running ) {
				stop();
			} else {
				start();
			}
		} );

		viewEl.addEventListener( 'click', function ( event ) {
			var again = event.target.closest( '[data-ypn-reprint]' );
			var job = again ? lastJobs.filter( function ( j ) { return j.id === again.getAttribute( 'data-ypn-reprint' ); } )[ 0 ] : null;
			if ( event.target.closest( '[data-ypn-station-test]' ) ) {
				job = lastJobs.filter( function ( j ) { return 'label' === j.kind; } )[ 0 ];
			}
			if ( job ) {
				printJob( job ).then( refreshList );
			}
		} );

		refreshList();
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
			[ 'messages', 'Messages', 'Contact form messages and web design quote requests.', ORA ],
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
				[ 'payments', 'Payments', 'Turn Venmo, Zelle, crypto and cards on or off.', GRN ],
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
