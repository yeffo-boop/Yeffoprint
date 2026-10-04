/**
 * Settings › Payments (`#/payments`) — turn each checkout payment method
 * on or off, and edit the store's own methods' settings (Venmo/Zelle
 * handle, checkout wording, NOWPayments keys) without WooCommerce >
 * Payments (class-admin-payments-controller.php). Direct request: run
 * the business from the dashboard without wp-admin.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var esc = YP.escapeHtml;
	var escAttr = YP.escapeAttr;

	function api( path ) {
		return yeffoprintAdminApp.restUrl + 'admin/payments' + ( path ? '/' + path : '' );
	}

	function save( id, body ) {
		return YP.request( api( id ), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( body )
		} );
	}

	function cardHtml( g ) {
		var fields = g.fields.map( function ( f ) {
			var input = 'textarea' === f.type
				? '<textarea rows="3" data-ypn-pay-field="' + escAttr( f.key ) + '">' + esc( f.value ) + '</textarea>'
				: '<input type="' + ( 'password' === f.type ? 'password' : 'text' ) + '" autocomplete="off" data-ypn-pay-field="' + escAttr( f.key ) + '" value="' + escAttr( f.value ) + '"' +
					( 'password' === f.type ? ' placeholder="' + ( f.set ? 'Saved. Type a new one to replace it' : 'Not set yet' ) + '"' : '' ) + '>';
			return '<label class="ypn-form__field' + ( 'textarea' === f.type ? ' is-wide' : '' ) + '"><span>' + esc( f.label ) + '</span>' + input + ( f.hint ? '<small>' + esc( f.hint ) + '</small>' : '' ) + '</label>';
		} ).join( '' );

		return '<section class="ypn-card ypn-paycard" data-ypn-pay="' + escAttr( g.id ) + '">' +
			'<div class="ypn-paycard__head">' +
				'<div><b>' + esc( g.name ) + '</b>' + ( g.title && g.title !== g.name ? '<span class="ypn-muted">Shown at checkout as “' + esc( g.title ) + '”</span>' : '' ) + '</div>' +
				'<label class="ypn-switch"><span class="screen-reader-text">Offer ' + esc( g.name ) + ' at checkout</span><input type="checkbox" role="switch" data-ypn-pay-on' + ( g.enabled ? ' checked' : '' ) + '><i></i></label>' +
			'</div>' +
			( fields
				? '<details class="ypn-paycard__more"><summary>Settings</summary><div class="ypn-form">' + fields + '</div>' +
					'<button type="button" class="ypn-btn ypn-btn--primary" data-ypn-pay-save>Save</button> <span class="ypn-muted" data-ypn-pay-status></span></details>'
				: '<p class="ypn-muted ypn-paycard__note">' + ( g.refunds ? 'Refunds from the order page go back to the customer’s card automatically. ' : '' ) + 'Account setup for this method stays in WooCommerce.</p>' ) +
		'</section>';
	}

	YP.views.payments = function ( viewEl ) {
		viewEl.innerHTML = '<p class="yp-field__hint">Loading&hellip;</p>';

		YP.request( api() ).then( function ( data ) {
			viewEl.innerHTML =
				'<p class="yp-app__intro">The ways customers can pay at checkout and on pay links. Each switch saves right away.</p>' +
				data.gateways.map( cardHtml ).join( '' );

			viewEl.querySelectorAll( '[data-ypn-pay]' ).forEach( function ( card ) {
				var id = card.getAttribute( 'data-ypn-pay' );
				var toggle = card.querySelector( '[data-ypn-pay-on]' );

				toggle.addEventListener( 'change', function () {
					toggle.disabled = true;
					save( id, { enabled: toggle.checked } ).then( function ( g ) {
						toggle.checked = g.enabled;
					} ).catch( function ( error ) {
						toggle.checked = ! toggle.checked;
						window.alert( 'Couldn’t save: ' + error.message );
					} ).then( function () { toggle.disabled = false; } );
				} );

				var button = card.querySelector( '[data-ypn-pay-save]' );
				if ( ! button ) {
					return;
				}
				button.addEventListener( 'click', function () {
					var fields = {};
					card.querySelectorAll( '[data-ypn-pay-field]' ).forEach( function ( input ) {
						fields[ input.getAttribute( 'data-ypn-pay-field' ) ] = input.value;
					} );
					var status = card.querySelector( '[data-ypn-pay-status]' );
					button.disabled = true;
					status.textContent = 'Saving…';
					save( id, { fields: fields } ).then( function ( g ) {
						button.disabled = false;
						status.textContent = 'Saved ✓';
						// Keys are never sent back: clear what was typed and say it's saved.
						g.fields.forEach( function ( f ) {
							var input = card.querySelector( '[data-ypn-pay-field="' + f.key + '"]' );
							if ( input && 'password' === f.type ) {
								input.value = '';
								input.placeholder = f.set ? 'Saved. Type a new one to replace it' : 'Not set yet';
							}
						} );
					} ).catch( function ( error ) {
						button.disabled = false;
						status.textContent = 'Couldn’t save: ' + error.message;
					} );
				} );
			} );
		} ).catch( function ( error ) {
			viewEl.innerHTML = '<p class="yp-form__error">Couldn’t load payment methods: ' + esc( error.message ) + '</p>';
		} );
	};
} )();
