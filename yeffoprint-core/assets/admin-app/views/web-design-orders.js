/**
 * Web Design Orders — direct request: "a new link on the admin panel to
 * show me all active web design orders so I can access these easily,
 * especially if I have more than one web design project going at a
 * time." Backed by the new `/admin/web-design-orders` list endpoint
 * (class-admin-web-design-controller.php's list_orders()), which already
 * sorts active (not-yet-live) projects ahead of live ones. Clicking a
 * row opens the exact same order drawer every other order list in this
 * app already uses (YP.openWcOrderDrawer()) — the Web Design Project
 * panel (Agreement/Staging Site/Go-Live) lives there, not on this
 * screen, same reasoning as Order History right next to it.
 *
 * No pagination/search, unlike Order History — this is a boutique
 * service's own project list, realistically never large enough to need
 * either; the one REST call already returns everything.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var STAGE_LABELS = {
		agreement_pending: 'Awaiting agreement',
		staging_in_progress: 'Staging in progress',
		client_reviewing: 'Client reviewing',
		golive_pending: 'Ready for go-live access',
		golive_received: 'Go-live access received',
		live: 'Live'
	};

	var STAGE_PILLS = {
		agreement_pending: 'warn',
		staging_in_progress: 'neutral',
		client_reviewing: 'neutral',
		golive_pending: 'warn',
		golive_received: 'good',
		live: 'good'
	};

	function formatDate( value ) {
		return value ? new Date( value + 'T00:00:00' ).toLocaleDateString() : '—';
	}

	YP.views[ 'web-design-orders' ] = function ( viewEl ) {
		viewEl.innerHTML =
			'<p class="yp-app__intro">Every order with a Web Design Package on it — active projects first, live ones at the bottom. Click a row to open its Agreement/Staging Site/Go-Live panel.</p>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Order</th><th>Package</th><th>Customer</th><th>Stage</th><th>Kickoff</th><th>Staging due</th><th>Go-live due</th><th>Total</th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="8">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl = viewEl.querySelector( '[data-yp-rows]' );

		function renderRows( orders ) {
			if ( ! orders.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="8">No Web Design orders yet.</td></tr>';
				return;
			}

			rowsEl.innerHTML = orders.map( function ( order ) {
				var pillClass = STAGE_PILLS[ order.stage ] || 'neutral';
				var stageLabel = STAGE_LABELS[ order.stage ] || order.stage;
				return (
					'<tr class="yp-row-clickable" data-yp-open-order="' + order.id + '">' +
						'<td>#' + YP.escapeHtml( String( order.number ) ) + '</td>' +
						'<td>' + YP.escapeHtml( order.package_name ) + '</td>' +
						'<td>' +
							'<div>' + YP.escapeHtml( order.customer_name || '—' ) + '</div>' +
							'<div class="yp-field__hint" style="margin:0;">' + YP.escapeHtml( order.customer_email || '' ) + '</div>' +
						'</td>' +
						'<td><span class="yp-pill yp-pill--' + pillClass + '">' + YP.escapeHtml( stageLabel ) + '</span></td>' +
						'<td>' + formatDate( order.kickoff_date ) + '</td>' +
						'<td>' + formatDate( order.staging_due ) + '</td>' +
						'<td>' + formatDate( order.golive_due ) + '</td>' +
						'<td>$' + order.total.toFixed( 2 ) + '</td>' +
					'</tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-open-order]' ).forEach( function ( row ) {
				row.addEventListener( 'click', function () {
					YP.openWcOrderDrawer( parseInt( row.getAttribute( 'data-yp-open-order' ), 10 ) );
				} );
			} );
		}

		YP.request( yeffoprintAdminApp.restUrl + 'admin/web-design-orders' )
			.then( function ( response ) { renderRows( response.orders || [] ); } )
			.catch( function ( error ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="8">Couldn’t load orders: ' + YP.escapeHtml( error.message ) + '</td></tr>';
			} );
	};
} )();
