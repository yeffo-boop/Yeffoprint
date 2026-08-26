/**
 * Maintenance Subscribers — read-only, unlike every other Phase 2/3
 * screen. These records are written entirely by the Stripe webhook
 * (class-stripe-webhook-controller.php), never by an admin — there's
 * no classic editor for this CPT either (class-maintenance-sub-meta.php's
 * own docblock). So this screen only ever lists and shows detail, via
 * WP core's own `/wp/v2/yp_maintenance_sub` REST route (GET only).
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var META = {
		subscriptionId: '_yp_stripe_subscription_id',
		customerId: '_yp_stripe_customer_id',
		email: '_yp_customer_email',
		userId: '_yp_customer_user_id',
		plan: '_yp_plan_label',
		status: '_yp_status',
		periodEnd: '_yp_current_period_end'
	};

	var STATUS_LABELS = { active: 'Active', past_due: 'Past Due', canceled: 'Canceled' };
	var STATUS_PILLS = { active: 'yp-pill--good', past_due: 'yp-pill--warn', canceled: 'yp-pill--neutral' };

	function endpoint( path ) {
		return yeffoprintAdminApp.wpApiUrl + 'yp_maintenance_sub' + ( path || '' );
	}

	function formatDate( timestamp ) {
		timestamp = parseInt( timestamp, 10 ) || 0;
		if ( ! timestamp ) {
			return '&mdash;';
		}
		return new Date( timestamp * 1000 ).toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: 'numeric' } );
	}

	YP.views.maintenance = function ( viewEl ) {
		var allSubs = [];

		viewEl.innerHTML =
			'<p class="yp-app__intro">Website maintenance &amp; monitoring subscribers, kept in sync from Stripe by webhook — read-only here; manage the subscription itself in the Stripe Dashboard.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search by email&hellip;" />' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Customer</th><th>Plan</th><th>Status</th><th>Renews</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="5">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl = viewEl.querySelector( '[data-yp-search]' );

		function load() {
			rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">Loading&hellip;</td></tr>';
			YP.request( endpoint( '?context=edit&status=publish&per_page=100&orderby=date&order=desc' ) )
				.then( function ( subs ) {
					allSubs = subs || [];
					renderRows( allSubs );
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">Couldn’t load subscribers: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows( subs ) {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? subs.filter( function ( s ) { return s.title.raw.toLowerCase().indexOf( query ) !== -1; } )
				: subs;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">' + ( subs.length ? 'No subscribers match your search.' : 'No maintenance subscribers yet.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( sub ) {
				var status = sub.meta ? ( sub.meta[ META.status ] || '' ) : '';
				var plan = sub.meta ? ( sub.meta[ META.plan ] || '&mdash;' ) : '&mdash;';

				return (
					'<tr data-id="' + sub.id + '">' +
						'<td><div class="yp-record-name">' + YP.escapeHtml( sub.title.raw ) + '</div></td>' +
						'<td>' + YP.escapeHtml( plan ) + '</td>' +
						'<td><span class="yp-pill ' + ( STATUS_PILLS[ status ] || 'yp-pill--neutral' ) + '">' + YP.escapeHtml( STATUS_LABELS[ status ] || status || 'Unknown' ) + '</span></td>' +
						'<td><span class="yp-chip">' + formatDate( sub.meta ? sub.meta[ META.periodEnd ] : 0 ) + '</span></td>' +
						'<td class="yp-row-actions"><button type="button" class="yp-row-action" data-yp-view="' + sub.id + '">View</button></td>' +
					'</tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-view]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { openDetail( findById( button.getAttribute( 'data-yp-view' ) ) ); } );
			} );
		}

		function findById( id ) {
			id = parseInt( id, 10 );
			for ( var i = 0; i < allSubs.length; i++ ) {
				if ( allSubs[ i ].id === id ) {
					return allSubs[ i ];
				}
			}
			return null;
		}

		function openDetail( sub ) {
			if ( ! sub ) {
				return;
			}
			var meta = sub.meta || {};
			var status = meta[ META.status ] || '';
			var userId = parseInt( meta[ META.userId ], 10 ) || 0;

			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--center';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="Subscriber detail">' +
					'<div class="yp-drawer__header"><span>' + YP.escapeHtml( sub.title.raw ) + '</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<div class="yp-form">' +
							'<div class="yp-field"><label>Plan</label><p>' + YP.escapeHtml( meta[ META.plan ] || '&mdash;' ) + '</p></div>' +
							'<div class="yp-field"><label>Status</label><p><span class="yp-pill ' + ( STATUS_PILLS[ status ] || 'yp-pill--neutral' ) + '">' + YP.escapeHtml( STATUS_LABELS[ status ] || status || 'Unknown' ) + '</span></p></div>' +
							'<div class="yp-field"><label>Renews / renewed</label><p>' + formatDate( meta[ META.periodEnd ] ) + '</p></div>' +
							'<div class="yp-field"><label>WordPress account</label><p>' + ( userId ? '<a href="' + YP.escapeAttr( yeffoprintAdminApp.exitUrl + 'user-edit.php?user_id=' + userId ) + '">View account &rarr;</a>' : 'No matching account' ) + '</p></div>' +
							'<div class="yp-field"><label>Stripe subscription ID</label><p><span class="yp-chip">' + YP.escapeHtml( meta[ META.subscriptionId ] || '&mdash;' ) + '</span></p></div>' +
							'<div class="yp-field"><label>Stripe customer ID</label><p><span class="yp-chip">' + YP.escapeHtml( meta[ META.customerId ] || '&mdash;' ) + '</span></p></div>' +
							'<div class="yp-form__actions">' +
								'<button type="button" class="wp-block-button__link is-style-outline" data-yp-drawer-close>Close</button>' +
							'</div>' +
						'</div>' +
					'</div>' +
				'</div>';

			document.body.appendChild( drawer );
			YP.initDrawer( drawer );
			YP.openDrawer( drawer );
		}

		searchEl.addEventListener( 'input', function () { renderRows( allSubs ); } );

		load();
	};
} )();
