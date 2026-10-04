/**
 * Sales (`#/sales`, under Today) — direct request: run the business from
 * the dashboard instead of the WooCommerce app, whose Stats tab showed
 * month, year and top sellers. Paid orders by the day they were paid,
 * net of refunds (class-admin-sales-controller.php), for one range at a
 * time, compared with the period just before it.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var esc = YP.escapeHtml;
	var escAttr = YP.escapeAttr;
	var range = '30d';

	try {
		range = window.localStorage.getItem( 'ypSalesRange' ) || range;
	} catch ( e ) {}

	function money( value, symbol ) {
		return ( symbol || '$' ) + Number( value || 0 ).toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	function change( now, before ) {
		if ( ! before ) {
			return '<span class="ypn-kpi__note">nothing the period before</span>';
		}
		var pct = Math.round( ( now - before ) / before * 100 );
		return '<span class="ypn-kpi__note ' + ( pct >= 0 ? 'is-up' : 'is-down' ) + '">' + ( pct >= 0 ? '▲ ' : '▼ ' ) + Math.abs( pct ) + '% vs the period before</span>';
	}

	function bucketLabel( key, byMonth, long ) {
		var parts = key.split( '-' ).map( Number );
		var d = new Date( parts[ 0 ], parts[ 1 ] - 1, parts[ 2 ] || 1 );
		return byMonth
			? d.toLocaleDateString( undefined, { month: long ? 'long' : 'short', year: long ? 'numeric' : undefined } )
			: d.toLocaleDateString( undefined, long ? { weekday: 'short', month: 'short', day: 'numeric' } : { month: 'short', day: 'numeric' } );
	}

	// One series (revenue), bars from a zero baseline. Hovering or tapping
	// a bar shows that day's revenue and orders above the chart.
	function chartHtml( data ) {
		var series = data.series;
		var max = Math.max.apply( null, series.map( function ( b ) { return b.revenue; } ).concat( [ 1 ] ) );
		var w = 600, h = 180, gap = 2;
		var bw = Math.max( 1, w / series.length - gap );
		var labelEvery = Math.ceil( series.length / 6 );

		var bars = series.map( function ( b, i ) {
			var bh = b.revenue > 0 ? Math.max( 2, b.revenue / max * ( h - 8 ) ) : 0;
			var x = i * ( w / series.length );
			return '<g data-ypn-bar="' + i + '">' +
				'<rect class="ypn-sales__hit" x="' + x.toFixed( 1 ) + '" y="0" width="' + ( w / series.length ).toFixed( 1 ) + '" height="' + h + '"></rect>' +
				( bh ? '<rect class="ypn-sales__bar" x="' + ( x + gap / 2 ).toFixed( 1 ) + '" y="' + ( h - bh ).toFixed( 1 ) + '" width="' + bw.toFixed( 1 ) + '" height="' + bh.toFixed( 1 ) + '" rx="' + Math.min( 4, bw / 2 ).toFixed( 1 ) + '"></rect>' : '' ) +
			'</g>';
		} ).join( '' );

		var ticks = series.map( function ( b, i ) {
			return i % labelEvery === 0 ? '<span style="left:' + ( ( i + 0.5 ) / series.length * 100 ).toFixed( 2 ) + '%">' + esc( bucketLabel( b.date, data.by_month ) ) + '</span>' : '';
		} ).join( '' );

		return '<div class="ypn-sales__readout" data-ypn-readout>Highest: ' + esc( money( max > 1 ? max : 0, data.currency_symbol ) ) + ( data.by_month ? ' in a month' : ' in a day' ) + '</div>' +
			'<svg class="ypn-sales__chart" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" role="img" aria-label="Revenue per ' + ( data.by_month ? 'month' : 'day' ) + '">' +
				'<line class="ypn-sales__base" x1="0" x2="' + w + '" y1="' + ( h - 0.5 ) + '" y2="' + ( h - 0.5 ) + '"></line>' + bars +
			'</svg>' +
			'<div class="ypn-sales__ticks">' + ticks + '</div>';
	}

	function barTable( rows, symbol, nameFn ) {
		var max = rows.reduce( function ( m, r ) { return Math.max( m, r.revenue ); }, 0 ) || 1;
		return rows.map( function ( r ) {
			return '<tr><td>' + nameFn( r ) + '</td>' +
				'<td class="ypn-hide-phone">' + esc( String( r.orders ) ) + '</td>' +
				'<td class="ypn-best__rev"><i style="width:' + Math.max( 4, Math.round( r.revenue / max * 90 ) ) + 'px"></i>' + esc( money( r.revenue, symbol ) ) + '</td></tr>';
		} ).join( '' );
	}

	YP.views.sales = function ( viewEl ) {
		function load() {
			viewEl.innerHTML = '<p class="yp-field__hint">Loading&hellip;</p>';
			YP.request( yeffoprintAdminApp.restUrl + 'admin/next/sales?range=' + encodeURIComponent( range ) ).then( draw ).catch( function ( error ) {
				viewEl.innerHTML = '<p class="yp-form__error">Couldn’t load sales: ' + esc( error.message ) + '</p>';
			} );
		}

		function draw( data ) {
			if ( ! document.body.contains( viewEl ) ) {
				return;
			}
			var t = data.totals;
			var p = data.previous;
			var sym = data.currency_symbol;

			viewEl.innerHTML =
				'<div class="ypn-sales__ranges" role="tablist">' + Object.keys( data.ranges ).map( function ( key ) {
					return '<button type="button" class="ypn-chip' + ( key === data.range ? ' is-on' : '' ) + '" data-ypn-range="' + escAttr( key ) + '" role="tab" aria-selected="' + ( key === data.range ? 'true' : 'false' ) + '">' + esc( data.ranges[ key ] ) + '</button>';
				} ).join( '' ) + '</div>' +
				'<div class="ypn-kpis">' +
					'<div class="ypn-card ypn-kpi ypn-kpi--main"><span class="ypn-kpi__label">Revenue</span><span class="ypn-kpi__value">' + esc( money( t.revenue, sym ) ) + '</span>' + change( t.revenue, p.revenue ) + '</div>' +
					'<div class="ypn-card ypn-kpi"><span class="ypn-kpi__label">Paid orders</span><span class="ypn-kpi__value">' + esc( String( t.orders ) ) + '</span>' + change( t.orders, p.orders ) + '</div>' +
					'<div class="ypn-card ypn-kpi"><span class="ypn-kpi__label">Average order</span><span class="ypn-kpi__value">' + esc( money( t.average, sym ) ) + '</span>' + change( t.average, p.average ) + '</div>' +
					'<div class="ypn-card ypn-kpi"><span class="ypn-kpi__label">Refunded</span><span class="ypn-kpi__value">' + esc( money( t.refunds, sym ) ) + '</span><span class="ypn-kpi__note">' + esc( String( t.units ) ) + ' items sold · ' + esc( money( t.shipping, sym ) ) + ' shipping</span></div>' +
				'</div>' +
				'<section class="ypn-card ypn-sales"><h3 class="ypn-card__title">Revenue per ' + ( data.by_month ? 'month' : 'day' ) + ' <span>' + esc( data.label ) + ', after refunds</span></h3>' + chartHtml( data ) + '</section>' +
				'<div class="ypn-sales__grid">' +
					'<section class="ypn-card ypn-best"><h3 class="ypn-card__title">Top sellers <span>' + esc( data.label ) + '</span></h3>' +
						( data.top_products.length
							? '<table class="ypn-table"><thead><tr><th>Item</th><th class="ypn-hide-phone">Orders</th><th>Revenue</th></tr></thead><tbody>' +
								barTable( data.top_products, sym, function ( r ) {
									return '<a class="ypn-best__name" href="#/' + escAttr( r.section ) + '">' +
										( r.image ? '<img src="' + escAttr( r.image ) + '" alt="" loading="lazy">' : '' ) +
										'<b>' + esc( r.name ) + '</b></a>';
								} ) + '</tbody></table>'
							: '<p class="yp-field__hint">No paid orders in this range.</p>' ) +
					'</section>' +
					'<section class="ypn-card ypn-best"><h3 class="ypn-card__title">How customers paid</h3>' +
						( data.payment_methods.length
							? '<table class="ypn-table"><thead><tr><th>Method</th><th class="ypn-hide-phone">Orders</th><th>Revenue</th></tr></thead><tbody>' +
								barTable( data.payment_methods, sym, function ( r ) { return '<b>' + esc( r.label ) + '</b>'; } ) + '</tbody></table>'
							: '<p class="yp-field__hint">No paid orders in this range.</p>' ) +
					'</section>' +
				'</div>';

			viewEl.querySelectorAll( '[data-ypn-range]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					range = button.getAttribute( 'data-ypn-range' );
					try {
						window.localStorage.setItem( 'ypSalesRange', range );
					} catch ( e ) {}
					load();
				} );
			} );

			var readout = viewEl.querySelector( '[data-ypn-readout]' );
			var resting = readout.innerHTML;
			viewEl.querySelectorAll( '[data-ypn-bar]' ).forEach( function ( g ) {
				function show() {
					var b = data.series[ parseInt( g.getAttribute( 'data-ypn-bar' ), 10 ) ];
					viewEl.querySelectorAll( '[data-ypn-bar].is-hover' ).forEach( function ( o ) { o.classList.remove( 'is-hover' ); } );
					g.classList.add( 'is-hover' );
					readout.innerHTML = '<b>' + esc( bucketLabel( b.date, data.by_month, true ) ) + '</b> · ' + esc( money( b.revenue, sym ) ) + ' · ' + b.orders + ( 1 === b.orders ? ' order' : ' orders' );
				}
				g.addEventListener( 'mouseenter', show );
				g.addEventListener( 'click', show );
			} );
			viewEl.querySelector( '.ypn-sales__chart' ).addEventListener( 'mouseleave', function () {
				viewEl.querySelectorAll( '[data-ypn-bar].is-hover' ).forEach( function ( o ) { o.classList.remove( 'is-hover' ); } );
				readout.innerHTML = resting;
			} );
		}

		load();
	};
} )();
