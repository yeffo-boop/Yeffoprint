/**
 * Card Surcharge — a rate table, one row per currently-registered
 * WooCommerce payment gateway (docs/ARCHITECTURE.md, Phase 7). Same
 * "one active record, no list" shape as views/pricing.js/settings.js.
 * `YeffoPrint_Admin_Menu::SURCHARGE_GATEWAY_RATES_OPTION` was
 * deliberately never REST-exposed before this (class-surcharge-admin.php's
 * own docblock) — `class-admin-surcharge-controller.php` is the first
 * thing that reaches it over REST, with the exact same sanitize rule
 * (`sanitize_gateway_rates()`) the classic Settings-API page enforces.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	function endpoint() {
		return yeffoprintAdminApp.restUrl + 'admin/surcharge';
	}

	YP.views.surcharge = function ( viewEl ) {
		viewEl.innerHTML = '<p class="yp-app__intro">Loading&hellip;</p>';

		YP.request( endpoint() )
			.then( function ( data ) { render( data ); } )
			.catch( function ( error ) {
				viewEl.innerHTML = '<p class="yp-app__intro">Couldn’t load: ' + YP.escapeHtml( error.message ) + '</p>';
			} );

		function render( data ) {
			viewEl.innerHTML =
				'<p class="yp-app__intro">Adds a fee to the order total when a customer pays with a gateway given a rate below.</p>' +
				'<div class="yp-form__error" style="background: rgba(181, 121, 10, 0.1); color: #8a5c08;"><strong>Before turning this on:</strong> credit card surcharging is banned outright in a few states (Connecticut, Massachusetts, Maine, and — as of a 2024 law — California, though its status has been challenged in court) and is capped by the card networks (currently 3% for Visa, 4% for Mastercard, or your actual processing cost if lower, whichever is less). It can never legally apply to a debit card, and this can\'t tell a credit card from a debit card before checkout. Confirm with your payment processor or a lawyer before relying on it.</div>' +
				'<div data-yp-save-status></div>' +

				( data.gateways.length
					? '<div class="yp-record-card"><table class="yp-record-table"><thead><tr><th>Gateway</th><th>Rate (%)</th><th>Label</th></tr></thead><tbody data-yp-rows>' +
						data.gateways.map( function ( gateway, index ) {
							return (
								'<tr data-index="' + index + '">' +
									'<td>' + YP.escapeHtml( gateway.title ) + ' <span class="yp-pill ' + ( gateway.enabled ? 'yp-pill--good' : 'yp-pill--neutral' ) + '">' + ( gateway.enabled ? 'Enabled' : 'Disabled' ) + '</span></td>' +
									'<td><input type="number" step="0.01" min="0" max="10" data-yp-rate value="' + YP.escapeAttr( gateway.rate || '' ) + '" style="width:90px;" /></td>' +
									'<td><input type="text" data-yp-label value="' + YP.escapeAttr( gateway.label ) + '" placeholder="' + YP.escapeAttr( data.label_default ) + '" style="width:100%;" /></td>' +
								'</tr>'
							);
						} ).join( '' ) +
					'</tbody></table></div>'
					: '<p class="yp-field__hint">No payment gateways are registered yet.</p>' ) +

				'<p class="yp-panel__hint">Blank or 0 turns the surcharge off for that gateway — leave every gateway except your actual credit card gateway(s) at 0, never a debit-only, bank-transfer, or manual gateway like Venmo/Zelle. Label is shown in the cart, checkout, and order emails as "Label (rate%)" — e.g. "Card Processing Fee (2.9%)" — and defaults to "' + YP.escapeHtml( data.label_default ) + '" if left blank.</p>' +

				( data.gateways.length ? '<button type="button" class="wp-block-button__link is-style-accent" data-yp-save>Save Rates</button>' : '' );

			if ( data.gateways.length ) {
				viewEl.querySelector( '[data-yp-save]' ).addEventListener( 'click', function () { save( data ); } );
			}
		}

		function save( data ) {
			var saveButton = viewEl.querySelector( '[data-yp-save]' );
			var statusEl = viewEl.querySelector( '[data-yp-save-status]' );

			var rates = {};
			viewEl.querySelectorAll( '[data-yp-rows] tr' ).forEach( function ( row ) {
				var index = parseInt( row.getAttribute( 'data-index' ), 10 );
				var gateway = data.gateways[ index ];
				rates[ gateway.id ] = {
					rate: parseFloat( row.querySelector( '[data-yp-rate]' ).value ) || 0,
					label: row.querySelector( '[data-yp-label]' ).value
				};
			} );

			saveButton.disabled = true;
			saveButton.textContent = 'Saving…';
			statusEl.innerHTML = '';

			YP.request( endpoint(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { rates: rates } ) } )
				.then( function ( updated ) {
					render( updated );
					viewEl.querySelector( '[data-yp-save-status]' ).innerHTML = '<p class="yp-panel__hint">Saved — live at checkout now.</p>';
				} )
				.catch( function ( error ) {
					saveButton.disabled = false;
					saveButton.textContent = 'Save Rates';
					statusEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}
	};
} )();
