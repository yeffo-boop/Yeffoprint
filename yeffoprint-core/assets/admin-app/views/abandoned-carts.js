/**
 * Abandoned Carts — people who reached checkout and typed an email but
 * didn't pay, the reminders they've been sent, and what came back
 * (class-abandoned-carts.php, via class-admin-abandoned-cart-
 * controller.php). The recovery settings sit below the list on the
 * same screen, so the switch that sends customer emails lives right
 * next to the carts it affects.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	function endpoint( path ) {
		return yeffoprintAdminApp.restUrl + 'admin/abandoned-carts' + ( path ? '/' + path : '' );
	}

	function money( amount ) {
		return '$' + Number( amount || 0 ).toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	function ago( unixSeconds ) {
		if ( ! unixSeconds ) {
			return '';
		}
		var minutes = Math.max( 0, Math.round( ( Date.now() / 1000 - unixSeconds ) / 60 ) );
		if ( minutes < 60 ) {
			return minutes + ( 1 === minutes ? ' min ago' : ' mins ago' );
		}
		var hours = Math.round( minutes / 60 );
		if ( hours < 48 ) {
			return hours + ( 1 === hours ? ' hr ago' : ' hrs ago' );
		}
		return Math.round( hours / 24 ) + ' days ago';
	}

	function statusPill( cart ) {
		var pill = function ( tone, text ) {
			return '<span class="yp-pill yp-pill--' + tone + '">' + text + '</span>';
		};

		if ( 'recovered' === cart.status ) {
			return pill( 'good', 'Recovered' );
		}
		if ( 'opted_out' === cart.status ) {
			return pill( 'neutral', 'Opted out' );
		}
		if ( 'ordered' === cart.status ) {
			return pill( 'good', 'Ordered' );
		}
		if ( 'stopped' === cart.status ) {
			return pill( 'neutral', 'Stopped' );
		}
		if ( 2 === cart.stage ) {
			if ( Date.now() / 1000 - cart.email2_at > 3 * 86400 ) {
				return pill( 'neutral', 'Done, no reply' );
			}
			return pill( cart.coupon_code ? 'crit' : 'warn', cart.coupon_code ? 'Email 2 + code' : 'Email 2 sent' );
		}
		if ( 1 === cart.stage ) {
			return pill( 'warn', 'Email 1 sent' );
		}
		return pill( 'neutral', 'Waiting' );
	}

	function cartSummary( cart ) {
		var first = cart.lines[ 0 ];
		if ( ! first ) {
			return '—';
		}
		var more = cart.lines.length > 1 ? '<br><span class="yp-field__hint">+ ' + ( cart.lines.length - 1 ) + ' more</span>' : '';
		return YP.escapeHtml( first.name ) + ' · ' + YP.escapeHtml( first.quantity ) + more;
	}

	function detailHtml( cart ) {
		var lines = cart.lines.map( function ( line ) {
			return '<li><strong>' + YP.escapeHtml( line.name ) + '</strong> · ' + YP.escapeHtml( line.quantity ) +
				( line.detail ? ' · ' + YP.escapeHtml( line.detail ) : '' ) + ' · ' + money( line.total ) + '</li>';
		} ).join( '' );

		var timeline = [
			cart.email1_at ? 'Email 1 sent ' + ago( cart.email1_at ) : '',
			cart.telegram_at ? 'Telegram nudge sent ' + ago( cart.telegram_at ) : '',
			cart.email2_at ? 'Email 2 sent ' + ago( cart.email2_at ) + ( cart.coupon_code ? ' with code ' + YP.escapeHtml( cart.coupon_code ) : '' ) : '',
			cart.clicked_at ? 'Opened the recovery link ' + ago( cart.clicked_at ) : ''
		].filter( Boolean );

		return (
			'<ul style="margin:0 0 8px 1.1em;">' + lines + '</ul>' +
			( timeline.length ? '<p class="yp-field__hint">' + timeline.join( ' · ' ) + '</p>' : '' ) +
			'<p class="yp-field__hint">Recovery link (opens their cart at checkout): ' +
				'<input type="text" readonly value="' + YP.escapeAttr( cart.recover_url ) + '" style="width:100%;max-width:520px;font-size:12px;padding:4px 8px;" onclick="this.select()" /></p>'
		);
	}

	YP.views[ 'abandoned-carts' ] = function ( viewEl ) {
		viewEl.innerHTML =
			'<p class="yp-app__intro">People who reached checkout and typed their email but didn’t pay. Last 30 days.</p>' +
			'<div data-yp-ac-away></div>' +
			'<div data-yp-ac-stats></div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Customer</th><th>Cart</th><th>Value</th><th>Left</th><th>Status</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="6">Loading&hellip;</td></tr></tbody></table></div>' +
			'<div data-yp-ac-settings></div>';

		var rowsEl = viewEl.querySelector( '[data-yp-rows]' );

		function load() {
			YP.request( endpoint() )
				.then( render )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">Couldn’t load carts: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function render( data ) {
			var stats = data.stats;

			viewEl.querySelector( '[data-yp-ac-away]' ).innerHTML = data.away_mode && data.settings.enabled
				? '<p class="yp-panel__hint"><span class="yp-pill yp-pill--warn">Paused</span> Away Mode is on, so no reminders go out until it ends.</p>'
				: '';

			viewEl.querySelector( '[data-yp-ac-stats]' ).innerHTML =
				'<div class="yp-stat-tiles">' +
					[
						[ String( stats.carts_left ), 'Carts left' ],
						[ stats.recovered + ( stats.recovered ? ' <span style="font-size:0.9rem;color:#16875a;">' + stats.recovered_rate + '%</span>' : '' ), 'Recovered' ],
						[ money( stats.recovered_sales ), 'Recovered sales' ],
						[ money( stats.still_open ), 'Still open' ]
					].map( function ( tile ) {
						return '<div class="yp-stat-tile"><span class="yp-stat-tile__count">' + tile[ 0 ] + '</span><span class="yp-stat-tile__label">' + tile[ 1 ] + '</span></div>';
					} ).join( '' ) +
				'</div>';

			renderRows( data.carts );
			renderSettings( data.settings );
		}

		function renderRows( carts ) {
			if ( ! carts.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">No abandoned carts yet. Carts show up here once someone types their email at checkout and leaves.</td></tr>';
				return;
			}

			rowsEl.innerHTML = carts.map( function ( cart ) {
				var actions = [];
				if ( 'open' === cart.status && cart.stage < 2 ) {
					actions.push( '<button type="button" class="yp-row-action" data-yp-ac-send="' + cart.id + '">' + ( 0 === cart.stage ? 'Send email 1' : 'Send email 2' ) + '</button>' );
				}
				if ( 'open' === cart.status ) {
					actions.push( '<button type="button" class="yp-row-action" data-yp-ac-stop="' + cart.id + '">Stop</button>' );
				}
				if ( cart.order_id && ( 'recovered' === cart.status || 'ordered' === cart.status ) ) {
					actions.push( '<button type="button" class="yp-row-action" data-yp-open-order="' + cart.order_id + '">Order ' + YP.escapeHtml( cart.order_number ) + '</button>' );
				}
				actions.push( '<button type="button" class="yp-row-action" data-yp-ac-toggle="' + cart.id + '">View cart</button>' );

				return (
					'<tr>' +
						'<td><strong>' + YP.escapeHtml( cart.name || ( cart.is_guest ? 'Guest' : 'Customer' ) ) + '</strong><br><span class="yp-field__hint">' + YP.escapeHtml( cart.email ) + '</span></td>' +
						'<td>' + cartSummary( cart ) + '</td>' +
						'<td class="mono"><strong>' + money( cart.total ) + '</strong></td>' +
						'<td>' + ago( cart.left_at ) + '</td>' +
						'<td>' + statusPill( cart ) + '</td>' +
						'<td>' + actions.join( ' ' ) + '</td>' +
					'</tr>' +
					'<tr data-yp-ac-detail="' + cart.id + '" hidden><td colspan="6">' + detailHtml( cart ) + '</td></tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-ac-toggle]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var detail = rowsEl.querySelector( '[data-yp-ac-detail="' + button.getAttribute( 'data-yp-ac-toggle' ) + '"]' );
					detail.hidden = ! detail.hidden;
					button.textContent = detail.hidden ? 'View cart' : 'Hide cart';
				} );
			} );

			rowsEl.querySelectorAll( '[data-yp-open-order]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					YP.openWcOrderDrawer( parseInt( button.getAttribute( 'data-yp-open-order' ), 10 ) );
				} );
			} );

			rowsEl.querySelectorAll( '[data-yp-ac-send]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					act( button.getAttribute( 'data-yp-ac-send' ), 'send', button );
				} );
			} );

			rowsEl.querySelectorAll( '[data-yp-ac-stop]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					YP.confirmModal( {
						title: 'Stop reminders for this cart?',
						message: 'No more emails or Telegram messages will go out about this cart.',
						confirmLabel: 'Stop reminders',
						onConfirm: function () {
							act( button.getAttribute( 'data-yp-ac-stop' ), 'stop', button );
						}
					} );
				} );
			} );
		}

		function act( id, action, button ) {
			button.disabled = true;
			YP.request( endpoint( id + '/' + action ), { method: 'POST' } )
				.then( load )
				.catch( function ( error ) {
					button.disabled = false;
					window.alert( 'Couldn’t do that: ' + error.message );
				} );
		}

		function checkbox( id, checked, label ) {
			return '<div class="yp-field--checkbox yp-field"><input type="checkbox" id="' + id + '"' + ( checked ? ' checked' : '' ) + ' /><label for="' + id + '">' + label + '</label></div>';
		}

		function number( id, value, label, min ) {
			return '<div class="yp-field"><label for="' + id + '">' + label + '</label><input type="number" min="' + min + '" id="' + id + '" value="' + YP.escapeAttr( String( value ) ) + '" /></div>';
		}

		function renderSettings( settings ) {
			var el = viewEl.querySelector( '[data-yp-ac-settings]' );
			el.innerHTML =
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Recovery settings</h2></div>' +
					'<p class="yp-panel__hint">Reminders stop the moment the customer pays, taps “Don’t remind me again”, or you stop them. They pause while Away Mode is on, and a cart left more than 3 days ago is never emailed.</p>' +
					checkbox( 'yp-ac-enabled', settings.enabled, 'Send cart reminders' ) +
					'<div class="yp-form__row">' +
						number( 'yp-ac-delay1', settings.delay1_minutes, 'Email 1 after (minutes)', 15 ) +
						number( 'yp-ac-delay2', settings.delay2_hours, 'Email 2 after (hours)', 2 ) +
					'</div>' +
					checkbox( 'yp-ac-discount', settings.discount_enabled, 'Put a single-use discount code in Email 2' ) +
					'<div class="yp-form__row">' +
						number( 'yp-ac-percent', settings.discount_percent, 'Discount (%)', 1 ) +
						number( 'yp-ac-hours', settings.discount_hours, 'Code expires after (hours)', 1 ) +
					'</div>' +
					checkbox( 'yp-ac-telegram', settings.telegram_nudge, 'Also nudge customers on Telegram when their account is linked to the bot' ) +
					checkbox( 'yp-ac-owner', settings.owner_alerts, 'Alert me on Telegram when a cart is left (with Send now / Don’t send buttons) and when one is recovered' ) +
					'<div class="yp-form__actions"><button type="button" class="wp-block-button__link is-style-accent" data-yp-ac-save>Save settings</button></div>' +
					'<div data-yp-ac-save-status></div>' +
				'</div>';

			el.querySelector( '[data-yp-ac-save]' ).addEventListener( 'click', function () {
				var button   = this;
				var statusEl = el.querySelector( '[data-yp-ac-save-status]' );
				button.disabled = true;
				statusEl.innerHTML = '';

				YP.request( endpoint( 'settings' ), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						enabled: el.querySelector( '#yp-ac-enabled' ).checked,
						delay1_minutes: el.querySelector( '#yp-ac-delay1' ).value,
						delay2_hours: el.querySelector( '#yp-ac-delay2' ).value,
						discount_enabled: el.querySelector( '#yp-ac-discount' ).checked,
						discount_percent: el.querySelector( '#yp-ac-percent' ).value,
						discount_hours: el.querySelector( '#yp-ac-hours' ).value,
						telegram_nudge: el.querySelector( '#yp-ac-telegram' ).checked,
						owner_alerts: el.querySelector( '#yp-ac-owner' ).checked
					} )
				} )
					.then( function ( response ) {
						renderSettings( response.settings );
						viewEl.querySelector( '[data-yp-ac-save-status]' ).innerHTML = '<p class="yp-panel__hint">Saved.</p>';
					} )
					.catch( function ( error ) {
						button.disabled = false;
						statusEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
					} );
			} );
		}

		load();
	};
} )();
