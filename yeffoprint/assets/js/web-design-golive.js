/**
 * Public secure go-live portal — customer submits their production
 * server credentials so staff can push the final site live. Same
 * one-time-link/token trust model as the other Web Design portal pages.
 * The password itself only ever leaves the browser over HTTPS to this
 * one REST endpoint, encrypted at rest immediately
 * (YeffoPrint_Secret_Box, class-web-design-project-meta.php's
 * submit_golive()) and never echoed back by any endpoint this page calls.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintWebDesign === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'yp-wd-golive' );
	if ( ! root ) {
		return;
	}

	var statusEl = root.querySelector( '.yp-configurator__status' );
	var contentEl = root.querySelector( '[data-yp-wd-content]' );
	var messageEl = root.querySelector( '[data-yp-wd-message]' );
	var formEl = root.querySelector( '[data-yp-wd-form]' );

	var params = new URLSearchParams( window.location.search );
	var orderId = params.get( 'order' );
	var token = params.get( 'token' ) || '';
	var method = 'ftp';

	function showError( message ) {
		statusEl.textContent = message;
		statusEl.setAttribute( 'data-state', 'error' );
		statusEl.hidden = false;
		contentEl.hidden = true;
	}

	function showMessage( text, isError ) {
		messageEl.textContent = text;
		messageEl.classList.toggle( 'is-error', !! isError );
		messageEl.hidden = false;
	}

	function apiUrl( path ) {
		var url = yeffoprintWebDesign.restUrl + 'web-design/' + encodeURIComponent( orderId ) + path;
		return token ? url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'token=' + encodeURIComponent( token ) : url;
	}

	function apiPost( path, body ) {
		return fetch( apiUrl( path ), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': yeffoprintWebDesign.nonce
			},
			body: JSON.stringify( Object.assign( { token: token }, body || {} ) )
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				return { ok: response.ok, data: data };
			} );
		} );
	}

	function render( data ) {
		if ( data.is_live ) {
			root.querySelector( '[data-yp-wd-already-live]' ).hidden = false;
			formEl.hidden = true;
		} else if ( data.submitted_at ) {
			root.querySelector( '[data-yp-wd-already-submitted]' ).hidden = false;
			root.querySelector( '[data-yp-wd-submitted-at]' ).textContent = new Date( data.submitted_at.replace( ' ', 'T' ) ).toLocaleString();
			formEl.hidden = true;
		} else if ( ! data.approved ) {
			root.querySelector( '[data-yp-wd-not-approved]' ).hidden = false;
			formEl.hidden = true;
		}

		statusEl.hidden = true;
		contentEl.hidden = false;
	}

	function load() {
		if ( ! orderId ) {
			showError( "This link is missing information and can't be loaded. Please use the exact link you were sent." );
			return;
		}

		fetch( apiUrl( '/golive' ), { headers: { 'X-WP-Nonce': yeffoprintWebDesign.nonce } } )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					showError( ( result.data && result.data.message ) || "This project couldn't be loaded." );
					return;
				}
				render( result.data );
			} )
			.catch( function () {
				showError( "Couldn't reach the server — please try again." );
			} );
	}

	root.querySelectorAll( '[data-yp-wd-method]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			method = button.getAttribute( 'data-yp-wd-method' );
			root.querySelectorAll( '[data-yp-wd-method]' ).forEach( function ( b ) {
				b.classList.toggle( 'is-active', b === button );
			} );
			root.querySelector( '[data-yp-wd-ftp-fields]' ).hidden = 'ftp' !== method;
			root.querySelector( '[data-yp-wd-wp-fields]' ).hidden = 'wp_admin' !== method;
		} );
	} );

	var passwordInput = document.getElementById( 'yp-wd-password' );
	var togglePasswordButton = root.querySelector( '[data-yp-wd-toggle-password]' );
	togglePasswordButton.addEventListener( 'click', function () {
		var showing = 'text' === passwordInput.type;
		passwordInput.type = showing ? 'password' : 'text';
		togglePasswordButton.textContent = showing ? 'Show' : 'Hide';
	} );

	var submitButton = root.querySelector( '[data-yp-wd-submit]' );
	submitButton.addEventListener( 'click', function () {
		var username = document.getElementById( 'yp-wd-username' ).value.trim();
		var password = passwordInput.value;

		if ( ! username || ! password ) {
			showMessage( 'Please enter a username and password.', true );
			return;
		}

		submitButton.disabled = true;

		apiPost( '/golive/submit', {
			method: method,
			host: document.getElementById( 'yp-wd-host' ).value.trim(),
			port: document.getElementById( 'yp-wd-port' ).value.trim(),
			wp_url: document.getElementById( 'yp-wd-wp-url' ).value.trim(),
			username: username,
			password: password,
			notes: document.getElementById( 'yp-wd-notes' ).value.trim()
		} ).then( function ( result ) {
			submitButton.disabled = false;

			if ( ! result.ok ) {
				showMessage( ( result.data && result.data.message ) || "Couldn't submit this. Please try again.", true );
				return;
			}

			formEl.hidden = true;
			showMessage( "Thanks — we've received your access and will be in touch once your site is live.", false );
		} ).catch( function () {
			submitButton.disabled = false;
			showMessage( "Couldn't reach the server — please try again.", true );
		} );
	} );

	load();
} )();
