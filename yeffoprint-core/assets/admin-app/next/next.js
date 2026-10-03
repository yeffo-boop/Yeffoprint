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
					'<div class="ypn-order__row"><span class="ypn-order__num">#' + esc( String( o.number ) ) + '</span>' +
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
				YP.openWcOrderDrawer( parseInt( card.getAttribute( 'data-ypn-order' ), 10 ) );
			}
		} );

		viewEl.addEventListener( 'keydown', function ( event ) {
			var card = event.target.closest && event.target.closest( '[data-ypn-order]' );
			if ( card && ( 'Enter' === event.key || ' ' === event.key ) ) {
				event.preventDefault();
				YP.openWcOrderDrawer( parseInt( card.getAttribute( 'data-ypn-order' ), 10 ) );
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

		if ( subId && /^\d+$/.test( subId ) ) {
			YP.openWcOrderDrawer( parseInt( subId, 10 ) );
			history.replaceState( null, '', '#/production' );
		}
	};

	/* ---------- Order window: progress + quick actions ---------- */

	var STEPS = [ 'Placed', 'Paid', 'Printing', 'Shipped', 'Delivered' ];
	var STEP_FOR_STATUS = { 'checkout-draft': 0, pending: 1, 'on-hold': 1, processing: 2, 'in-production': 2, shipped: 3, completed: 5 };

	YP.onOrderDetail = function ( order, drawer, bodyEl ) {
		var current = STEP_FOR_STATUS[ order.status ];
		var html = '';

		if ( undefined === current ) {
			html = '<div class="ypn-progress ypn-progress--stopped">This order is ' + esc( order.status_label.toLowerCase() ) + '.</div>';
		} else {
			html = '<ol class="ypn-progress">' + STEPS.map( function ( step, i ) {
				var state = i < current ? 'done' : ( i === current ? 'now' : '' );
				var label = step;
				if ( 2 === i && 'processing' === order.status ) {
					label = 'Ready to print';
				}
				return '<li class="' + state + '"><i>' + ( 'done' === state ? '✓' : i + 1 ) + '</i><span>' + esc( label ) + '</span></li>';
			} ).join( '' ) + '</ol>';
		}

		var actions = [];
		if ( 'processing' === order.status ) {
			actions.push( '<button type="button" class="ypn-btn ypn-btn--primary" data-ypn-detail-print>Send to printer</button>' );
		}
		if ( order.payment_url ) {
			actions.push( '<button type="button" class="ypn-btn" data-ypn-copy-pay>Copy pay link</button>' );
		}
		if ( order.customer_email ) {
			actions.push( '<a class="ypn-btn" href="mailto:' + escAttr( order.customer_email ) + '">Email customer</a>' );
		}

		bodyEl.insertAdjacentHTML( 'afterbegin',
			'<div class="ypn-detail-top">' + html +
				( actions.length ? '<div class="ypn-detail-actions">' + actions.join( '' ) + '</div>' : '' ) +
			'</div>'
		);

		var printButton = bodyEl.querySelector( '[data-ypn-detail-print]' );
		if ( printButton ) {
			printButton.addEventListener( 'click', function () {
				printButton.disabled = true;
				printButton.textContent = 'Sending…';
				YP.request( api( 'admin/order/' + order.id + '/send-to-printer' ), { method: 'POST' } )
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
			copyButton.addEventListener( 'click', function () {
				var done = function () { copyButton.textContent = 'Copied'; };
				if ( navigator.clipboard ) {
					navigator.clipboard.writeText( order.payment_url ).then( done, function () { window.prompt( 'Pay link', order.payment_url ); } );
				} else {
					window.prompt( 'Pay link', order.payment_url );
				}
			} );
		}
	};

	/* ---------- Hubs ---------- */

	function tilesHtml( tiles ) {
		return '<div class="ypn-tiles">' + tiles.map( function ( t ) {
			return (
				'<a class="ypn-tile" href="#/' + t[ 0 ] + '">' +
					'<span class="ypn-tile__icon" style="--tile:' + t[ 3 ] + '">' + esc( t[ 1 ].charAt( 0 ) ) + '</span>' +
					'<b>' + esc( t[ 1 ] ) + '</b>' +
					'<span class="ypn-tile__text">' + esc( t[ 2 ] ) + '</span>' +
					'<span class="ypn-tile__go">Open &rsaquo;</span>' +
				'</a>'
			);
		} ).join( '' ) + '</div>';
	}

	var MAG = '#EC008C', CY = '#00AEEF', YEL = '#d4a300', VIO = '#7c3aed', GRN = '#16a34a', ORA = '#ea580c', INK = '#3a3a3c';

	YP.views.catalog = function ( viewEl ) {
		viewEl.innerHTML = tilesHtml( [
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
