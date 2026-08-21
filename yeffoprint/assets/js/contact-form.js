/**
 * Contact form — REST-backed submit (yeffoprint-core/v1/contact), same
 * fetch/error-handling shape as custom-sticker-form.js, just without
 * any pricing/upload machinery: three plain fields plus a conditional
 * fourth (a WhatsApp/Telegram username, only when that contact method
 * is chosen).
 */
( function () {
	'use strict';

	if ( typeof yeffoprintContact === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'yp-contact' );
	if ( ! root ) {
		return;
	}

	var form = document.getElementById( 'yp-contact-form' );
	var methodSelect = document.getElementById( 'yp-contact-method' );
	var handleField = root.querySelector( '[data-yp-contact-handle-field]' );
	var handleLabel = root.querySelector( '[data-yp-contact-handle-label]' );
	var handleInput = document.getElementById( 'yp-contact-handle' );
	var submitButton = root.querySelector( '[data-yp-contact-submit]' );
	var statusEl = null;

	var HANDLE_LABELS = { whatsapp: 'WhatsApp username', telegram: 'Telegram username' };

	function onMethodChange() {
		var method = methodSelect.value;
		var needsHandle = 'email' !== method;

		handleField.hidden = ! needsHandle;
		handleInput.required = needsHandle;
		if ( needsHandle ) {
			handleLabel.textContent = HANDLE_LABELS[ method ] || 'Username';
		}
	}

	methodSelect.addEventListener( 'change', onMethodChange );
	onMethodChange();

	function showStatus( message, isError ) {
		if ( ! statusEl ) {
			// A sibling of the form, not a child of it — has to survive
			// form.hidden being set true on a successful submit below.
			statusEl = document.createElement( 'p' );
			statusEl.className = 'yp-configurator__cart-status';
			form.insertAdjacentElement( 'afterend', statusEl );
		}
		statusEl.textContent = message;
		statusEl.classList.toggle( 'is-error', !! isError );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		submitButton.disabled = true;
		showStatus( 'Sending…', false );

		var payload = {
			name: document.getElementById( 'yp-contact-name' ).value,
			email: document.getElementById( 'yp-contact-email' ).value,
			contact_method: methodSelect.value,
			contact_handle: handleInput.value,
			message: document.getElementById( 'yp-contact-message' ).value,
			website: document.getElementById( 'yp-contact-website' ).value
		};

		fetch( yeffoprintContact.restUrl + 'contact', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': yeffoprintContact.nonce },
			body: JSON.stringify( payload )
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				submitButton.disabled = false;

				if ( ! result.ok ) {
					showStatus( ( result.data && result.data.message ) || "Couldn't send your message. Please try again.", true );
					return;
				}

				form.hidden = true;
				showStatus( "Thanks — your message is on its way. We'll get back to you soon.", false );
			} )
			.catch( function () {
				submitButton.disabled = false;
				showStatus( "Couldn't reach the server — please try again.", true );
			} );
	} );
} )();
