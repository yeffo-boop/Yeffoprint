/**
 * Web Design "Order Now" — opens the shared package modal (site.js
 * drawer) and POSTs /web-design-order to create a pending WC order,
 * then redirects to get_checkout_payment_url().
 */
( function () {
	'use strict';

	if ( typeof yeffoprintWebDesignOrder === 'undefined' ) {
		return;
	}

	var form = document.getElementById( 'yp-wd-order-form' );
	if ( ! form ) {
		return;
	}

	var packageIdInput = form.querySelector( '[data-yp-wd-order-package-id]' );
	var nameInput = form.querySelector( '[data-yp-wd-order-name]' );
	var emailInput = form.querySelector( '[data-yp-wd-order-email]' );
	var submitButton = form.querySelector( '[data-yp-wd-order-submit]' );
	var statusEl = form.querySelector( '[data-yp-wd-order-status]' );
	var packageLabelEl = document.querySelector( '[data-yp-wd-order-package-label]' );

	if ( yeffoprintWebDesignOrder.defaultName && nameInput && ! nameInput.value ) {
		nameInput.value = yeffoprintWebDesignOrder.defaultName;
	}
	if ( yeffoprintWebDesignOrder.defaultEmail && emailInput && ! emailInput.value ) {
		emailInput.value = yeffoprintWebDesignOrder.defaultEmail;
	}

	document.querySelectorAll( '[data-yp-wd-order]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var packageId = button.getAttribute( 'data-package-id' ) || '';
			var packageName = button.getAttribute( 'data-package-name' ) || '';
			if ( packageIdInput ) {
				packageIdInput.value = packageId;
			}
			if ( packageLabelEl ) {
				packageLabelEl.textContent = packageName
					? 'Pay for “' + packageName + '” and we’ll get started.'
					: '';
			}
			if ( statusEl ) {
				statusEl.hidden = true;
				statusEl.textContent = '';
			}
		} );
	} );

	function showStatus( message, isError ) {
		if ( ! statusEl ) {
			return;
		}
		statusEl.hidden = false;
		statusEl.textContent = message;
		statusEl.classList.toggle( 'is-error', !! isError );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		var packageId = parseInt( packageIdInput && packageIdInput.value, 10 ) || 0;
		if ( ! packageId ) {
			showStatus( 'Pick a package first.', true );
			return;
		}

		submitButton.disabled = true;
		showStatus( 'Starting checkout…', false );

		var honeypot = form.querySelector( 'input[name="website"]' );

		fetch( yeffoprintWebDesignOrder.restUrl + 'web-design-order', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': yeffoprintWebDesignOrder.nonce
			},
			body: JSON.stringify( {
				package_id: packageId,
				name: nameInput ? nameInput.value : '',
				email: emailInput ? emailInput.value : '',
				website: honeypot ? honeypot.value : ''
			} )
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok || ! result.data || ! result.data.payment_url ) {
					submitButton.disabled = false;
					showStatus( ( result.data && result.data.message ) || "Couldn't start checkout. Please try Get a Quote instead.", true );
					return;
				}
				window.location.href = result.data.payment_url;
			} )
			.catch( function () {
				submitButton.disabled = false;
				showStatus( "Couldn't reach the server — please try again.", true );
			} );
	} );
} )();
