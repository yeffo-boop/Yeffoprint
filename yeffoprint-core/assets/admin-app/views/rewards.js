/**
 * Rewards — points/referral rates, a customer lookup (balance + full
 * history), a manual adjust form, and the global recent-adjustments
 * log (docs/ARCHITECTURE.md, Phase 7). Four REST routes
 * (class-admin-rewards-controller.php) back the four sections the
 * classic `class-rewards-admin.php` page has; `adjust()` there calls
 * the exact same `YeffoPrint_Rewards::adjust_balance()` this screen's
 * "Apply" button ends up calling too.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	function endpoint( path ) {
		return yeffoprintAdminApp.restUrl + 'admin/' + path;
	}

	function formatDelta( delta ) {
		return '<span style="color:' + ( delta >= 0 ? 'var(--wp--preset--color--cyan-deep, #0078A4)' : 'var(--wp--preset--color--magenta-deep)' ) + ';font-weight:600;">' + ( delta >= 0 ? '+' : '' ) + delta + '</span>';
	}

	YP.views.rewards = function ( viewEl ) {
		viewEl.innerHTML =
			'<p class="yp-app__intro">Points/referral rates, manual balance adjustments, and per-customer lookups.</p>' +

			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>Points &amp; Referral Rates</h2></div>' +
				'<div data-yp-rates-status></div>' +
				'<div data-yp-rates-fields><p class="yp-field__hint">Loading&hellip;</p></div>' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-save-rates>Save Rates</button>' +
			'</div>' +

			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>Look Up a Customer</h2></div>' +
				'<div class="yp-form__row">' +
					'<div class="yp-field"><input type="text" data-yp-lookup-input placeholder="Email or username" /></div>' +
					'<div><button type="button" class="wp-block-button__link is-style-outline" data-yp-lookup-go>Look up</button></div>' +
				'</div>' +
				'<div data-yp-lookup-result></div>' +
			'</div>' +

			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>Award or Adjust Points</h2></div>' +
				'<p class="yp-panel__hint">For migrating a balance from the old site, or making a customer-service situation right — anything with no real order behind it. A negative amount deducts points; the balance never goes below zero.</p>' +
				'<div data-yp-adjust-status></div>' +
				'<div class="yp-form">' +
					'<div class="yp-field"><label for="yp-rw-user">Customer</label><input type="text" id="yp-rw-user" placeholder="Email or username" /></div>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-rw-points">Points</label><input type="number" step="1" id="yp-rw-points" /><p class="yp-field__hint">Positive to award, negative to deduct.</p></div>' +
						'<div class="yp-field"><label for="yp-rw-reason">Reason</label><input type="text" id="yp-rw-reason" placeholder="e.g. Migrated balance from old site" /></div>' +
					'</div>' +
					'<div class="yp-form__actions"><button type="button" class="wp-block-button__link is-style-accent" data-yp-apply>Apply</button></div>' +
				'</div>' +
			'</div>' +

			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>Recent Adjustments</h2></div>' +
				'<div data-yp-history><p class="yp-field__hint">Loading&hellip;</p></div>' +
			'</div>';

		loadRates();
		loadHistory();

		viewEl.querySelector( '[data-yp-lookup-go]' ).addEventListener( 'click', lookup );
		viewEl.querySelector( '[data-yp-lookup-input]' ).addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) { lookup(); }
		} );
		viewEl.querySelector( '[data-yp-apply]' ).addEventListener( 'click', applyAdjustment );

		/* ---------- Rates ---------- */

		function loadRates() {
			YP.request( endpoint( 'rewards-settings' ) )
				.then( function ( rates ) { renderRates( rates ); } )
				.catch( function ( error ) {
					viewEl.querySelector( '[data-yp-rates-fields]' ).innerHTML = '<p class="yp-form__error">Couldn’t load: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		function renderRates( rates ) {
			viewEl.querySelector( '[data-yp-rates-fields]' ).innerHTML =
				'<div class="yp-form__row">' +
					'<div class="yp-field"><label for="yp-rw-ppd">Points earned per $1 spent</label><input type="number" step="0.01" min="0" id="yp-rw-ppd" value="' + YP.escapeAttr( rates.points_per_dollar ) + '" /></div>' +
					'<div class="yp-field"><label for="yp-rw-dpp">Redemption value per point</label><input type="number" step="0.001" min="0" id="yp-rw-dpp" value="' + YP.escapeAttr( rates.dollars_per_point ) + '" /><p class="yp-field__hint">Default 0.01 means 100 points = $1.</p></div>' +
					'<div class="yp-field"><label for="yp-rw-ref">Points per successful referral</label><input type="number" step="1" min="0" id="yp-rw-ref" value="' + YP.escapeAttr( rates.referral_points ) + '" /><p class="yp-field__hint">0 turns referral rewards off entirely.</p></div>' +
				'</div>';

			viewEl.querySelector( '[data-yp-save-rates]' ).onclick = function () {
				var button = viewEl.querySelector( '[data-yp-save-rates]' );
				var statusEl = viewEl.querySelector( '[data-yp-rates-status]' );
				button.disabled = true;
				button.textContent = 'Saving…';
				statusEl.innerHTML = '';

				YP.request( endpoint( 'rewards-settings' ), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						points_per_dollar: parseFloat( viewEl.querySelector( '#yp-rw-ppd' ).value ) || 0,
						dollars_per_point: parseFloat( viewEl.querySelector( '#yp-rw-dpp' ).value ) || 0,
						referral_points: parseFloat( viewEl.querySelector( '#yp-rw-ref' ).value ) || 0
					} )
				} ).then( function ( updated ) {
					renderRates( updated );
					viewEl.querySelector( '[data-yp-rates-status]' ).innerHTML = '<p class="yp-panel__hint">Saved.</p>';
				} ).catch( function ( error ) {
					button.disabled = false;
					button.textContent = 'Save Rates';
					statusEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
			};
		}

		/* ---------- Lookup ---------- */

		function lookup() {
			var identifier = viewEl.querySelector( '[data-yp-lookup-input]' ).value.trim();
			var resultEl = viewEl.querySelector( '[data-yp-lookup-result]' );

			if ( ! identifier ) {
				return;
			}

			resultEl.innerHTML = '<p class="yp-field__hint">Looking up&hellip;</p>';

			YP.request( endpoint( 'rewards-lookup?user=' + encodeURIComponent( identifier ) ) )
				.then( function ( data ) {
					if ( ! data.found ) {
						resultEl.innerHTML = '<p class="yp-field__hint">No customer found with that email or username.</p>';
						return;
					}

					var historyHtml = ! data.history.length
						? '<p class="yp-field__hint">No rewards activity yet.</p>'
						: '<table class="yp-record-table"><thead><tr><th>Date</th><th>Activity</th><th>Points</th><th>By</th></tr></thead><tbody>' +
							data.history.map( function ( entry ) {
								var parts = [];
								if ( entry.earned > 0 ) { parts.push( '+' + entry.earned ); }
								if ( entry.redeemed > 0 ) { parts.push( '&minus;' + entry.redeemed ); }
								var net = entry.earned - entry.redeemed;
								var pointsHtml = '<span style="color:' + ( net >= 0 ? 'var(--wp--preset--color--cyan-deep, #0078A4)' : 'var(--wp--preset--color--magenta-deep)' ) + ';font-weight:600;">' + parts.join( ' / ' ) + '</span>';
								var label = entry.order_edit_url
									? '<a href="' + YP.escapeAttr( entry.order_edit_url ) + '" target="_blank" rel="noopener noreferrer">' + YP.escapeHtml( entry.label ) + '</a>'
									: YP.escapeHtml( entry.label );
								return '<tr><td>' + new Date( entry.timestamp * 1000 ).toLocaleString() + '</td><td>' + label + '</td><td>' + pointsHtml + '</td><td>' + YP.escapeHtml( entry.by || '—' ) + '</td></tr>';
							} ).join( '' ) +
						'</tbody></table>';

					resultEl.innerHTML =
						'<p>' + YP.escapeHtml( data.display_name ) + ' (' + YP.escapeHtml( data.email ) + ') — current balance: <strong>' + data.balance + '</strong> points.</p>' +
						historyHtml;

					viewEl.querySelector( '#yp-rw-user' ).value = data.email;
				} )
				.catch( function ( error ) {
					resultEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		/* ---------- Adjust ---------- */

		function applyAdjustment() {
			var button = viewEl.querySelector( '[data-yp-apply]' );
			var statusEl = viewEl.querySelector( '[data-yp-adjust-status]' );

			var body = {
				user: viewEl.querySelector( '#yp-rw-user' ).value.trim(),
				points: parseInt( viewEl.querySelector( '#yp-rw-points' ).value, 10 ) || 0,
				reason: viewEl.querySelector( '#yp-rw-reason' ).value.trim()
			};

			button.disabled = true;
			button.textContent = 'Applying…';
			statusEl.innerHTML = '';

			YP.request( endpoint( 'rewards-adjust' ), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } )
				.then( function ( result ) {
					button.disabled = false;
					button.textContent = 'Apply';
					statusEl.innerHTML = '<p class="yp-panel__hint">Updated ' + YP.escapeHtml( result.user_email ) + ' — new balance: ' + result.balance + ' points.</p>';
					viewEl.querySelector( '#yp-rw-points' ).value = '';
					viewEl.querySelector( '#yp-rw-reason' ).value = '';
					loadHistory();
				} )
				.catch( function ( error ) {
					button.disabled = false;
					button.textContent = 'Apply';
					statusEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		/* ---------- History ---------- */

		function loadHistory() {
			var el = viewEl.querySelector( '[data-yp-history]' );
			YP.request( endpoint( 'rewards-history' ) )
				.then( function ( entries ) {
					if ( ! entries.length ) {
						el.innerHTML = '<p class="yp-field__hint">No manual adjustments yet.</p>';
						return;
					}
					el.innerHTML =
						'<table class="yp-record-table"><thead><tr><th>Date</th><th>Customer</th><th>Points</th><th>Reason</th><th>By</th></tr></thead><tbody>' +
							entries.map( function ( entry ) {
								return (
									'<tr>' +
										'<td>' + ( entry.date ? new Date( entry.date ).toLocaleString() : '—' ) + '</td>' +
										'<td>' + YP.escapeHtml( entry.customer_email || '(deleted user)' ) + '</td>' +
										'<td>' + formatDelta( entry.delta ) + '</td>' +
										'<td>' + YP.escapeHtml( entry.reason ) + '</td>' +
										'<td>' + YP.escapeHtml( entry.by || '—' ) + '</td>' +
									'</tr>'
								);
							} ).join( '' ) +
						'</tbody></table>';
				} )
				.catch( function ( error ) {
					el.innerHTML = '<p class="yp-form__error">Couldn’t load: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}
	};
} )();
