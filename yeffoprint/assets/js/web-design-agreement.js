/**
 * Public agreement review & e-sign page. Reached via a one-time link —
 * `?order=<id>&token=<token>` for a guest (the token is the access
 * credential, class-web-design-portal-controller.php's check_access()),
 * or the same URL without a token for a logged-in customer viewing
 * their own order (nonce-protected instead). Same shape as
 * proof-approval.js.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintWebDesign === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'yp-wd-agreement' );
	if ( ! root ) {
		return;
	}

	var statusEl = root.querySelector( '.yp-configurator__status' );
	var contentEl = root.querySelector( '[data-yp-wd-content]' );
	var messageEl = root.querySelector( '[data-yp-wd-message]' );

	var params = new URLSearchParams( window.location.search );
	var orderId = params.get( 'order' );
	var token = params.get( 'token' ) || '';

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}

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
		root.querySelector( '[data-yp-wd-package]' ).textContent = data.package_name;
		root.querySelector( '[data-yp-wd-paid]' ).textContent = '$' + Number( data.total_paid ).toFixed( 2 );
		root.querySelector( '[data-yp-wd-kickoff]' ).textContent = data.kickoff_date || '—';
		root.querySelector( '[data-yp-wd-staging-due]' ).textContent = data.staging_due || '—';
		root.querySelector( '[data-yp-wd-golive-due]' ).textContent = data.golive_due || '—';

		var milestonesEl = root.querySelector( '[data-yp-wd-milestones]' );
		milestonesEl.innerHTML = data.milestones.length
			? data.milestones.map( function ( m ) {
				return '<li><span>' + escapeHtml( m.label ) + '</span><span>' + escapeHtml( m.due_date ) + '</span></li>';
			} ).join( '' )
			: '<li><span>No milestones added yet.</span></li>';

		var addonsEl = root.querySelector( '[data-yp-wd-addons]' );
		addonsEl.innerHTML = data.addons.length
			? data.addons.map( function ( a ) {
				return '<span>' + escapeHtml( a.label ) + ( a.price ? ' — $' + escapeHtml( a.price ) : '' ) + '</span>';
			} ).join( '' )
			: '';

		root.querySelector( '[data-yp-wd-scope]' ).textContent = data.scope_text || '';

		if ( data.is_signed ) {
			root.querySelector( '[data-yp-wd-signed]' ).hidden = false;
			root.querySelector( '[data-yp-wd-signed-name]' ).textContent = data.signed_name;
			root.querySelector( '[data-yp-wd-signed-at]' ).textContent = new Date( data.signed_at.replace( ' ', 'T' ) ).toLocaleString();
			root.querySelector( '[data-yp-wd-sign-form]' ).hidden = true;
		}

		statusEl.hidden = true;
		contentEl.hidden = false;
	}

	function load() {
		if ( ! orderId ) {
			showError( "This link is missing information and can't be loaded. Please use the exact link you were sent." );
			return;
		}

		fetch( apiUrl( '/agreement' ), { headers: { 'X-WP-Nonce': yeffoprintWebDesign.nonce } } )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					showError( ( result.data && result.data.message ) || "This agreement couldn't be loaded." );
					return;
				}
				render( result.data );
			} )
			.catch( function () {
				showError( "Couldn't reach the server — please try again." );
			} );
	}

	var agreeCheckbox = root.querySelector( '[data-yp-wd-agree]' );
	var nameInput = root.querySelector( '[data-yp-wd-sign-name]' );
	var signButton = root.querySelector( '[data-yp-wd-sign-submit]' );

	function refreshSignButton() {
		signButton.disabled = ! ( agreeCheckbox.checked && nameInput.value.trim() );
	}

	agreeCheckbox.addEventListener( 'change', refreshSignButton );
	nameInput.addEventListener( 'input', refreshSignButton );

	signButton.addEventListener( 'click', function () {
		if ( signButton.disabled ) {
			return;
		}

		signButton.disabled = true;

		apiPost( '/agreement/sign', { name: nameInput.value.trim() } ).then( function ( result ) {
			if ( ! result.ok ) {
				signButton.disabled = false;
				showMessage( ( result.data && result.data.message ) || "Couldn't sign this agreement. Please try again.", true );
				return;
			}

			root.querySelector( '[data-yp-wd-signed]' ).hidden = false;
			root.querySelector( '[data-yp-wd-signed-name]' ).textContent = nameInput.value.trim();
			root.querySelector( '[data-yp-wd-signed-at]' ).textContent = new Date( result.data.signed_at.replace( ' ', 'T' ) ).toLocaleString();
			root.querySelector( '[data-yp-wd-sign-form]' ).hidden = true;
			showMessage( "Thanks — you're all set. We'll be in touch once your staging site is ready.", false );
		} ).catch( function () {
			signButton.disabled = false;
			showMessage( "Couldn't reach the server — please try again.", true );
		} );
	} );

	load();
} )();
