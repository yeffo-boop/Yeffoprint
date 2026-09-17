/**
 * The manual order-number + email fallback form on /add-to-order/
 * (blocks/order-addon-gate/render.php) — only rendered when the page
 * was reached without a valid order+key token. Same shape as
 * proof-approval.js's own form submit: a fetch, an error paragraph, a
 * redirect on success.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintOrderAddon === 'undefined' ) {
		return;
	}

	var form = document.querySelector( '[data-yp-addon-form]' );
	if ( ! form ) {
		return;
	}

	var errorEl = form.querySelector( '[data-yp-addon-error]' );
	var submitButton = form.querySelector( 'button[type="submit"]' );

	function showError( message ) {
		errorEl.textContent = message;
		errorEl.hidden = false;
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		errorEl.hidden = true;
		submitButton.disabled = true;
		submitButton.textContent = 'Checking…';

		fetch( yeffoprintOrderAddon.restUrl + 'addon/verify', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				order_ref: form.order_ref.value.trim(),
				email: form.email.value.trim()
			} )
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					showError( ( result.data && result.data.message ) || "This order couldn't be found." );
					submitButton.disabled = false;
					submitButton.textContent = 'Check my order';
					return;
				}

				window.location.href = result.data.redirect;
			} )
			.catch( function () {
				showError( "Couldn't reach the server — please try again." );
				submitButton.disabled = false;
				submitButton.textContent = 'Check my order';
			} );
	} );
} )();
