/**
 * Public staging-review page — approve the staged site or request
 * changes. Same one-time-link/token trust model as proof-approval.js;
 * approving redirects to the go-live portal link the API hands back.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintWebDesign === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'yp-wd-staging-review' );
	if ( ! root ) {
		return;
	}

	var statusEl = root.querySelector( '.yp-configurator__status' );
	var contentEl = root.querySelector( '[data-yp-wd-content]' );
	var messageEl = root.querySelector( '[data-yp-wd-message]' );
	var actionsEl = root.querySelector( '[data-yp-wd-actions]' );
	var approveButton = root.querySelector( '[data-yp-wd-approve]' );
	var showChangesButton = root.querySelector( '[data-yp-wd-show-changes]' );
	var changesFormEl = root.querySelector( '[data-yp-wd-changes-form]' );
	var notesEl = document.getElementById( 'yp-wd-changes-notes' );
	var submitChangesButton = root.querySelector( '[data-yp-wd-submit-changes]' );
	var cancelChangesButton = root.querySelector( '[data-yp-wd-cancel-changes]' );

	var params = new URLSearchParams( window.location.search );
	var orderId = params.get( 'order' );
	var token = params.get( 'token' ) || '';

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
		root.querySelector( '[data-yp-wd-package]' ).textContent = 'Staging site for ' + data.package_name;

		var linkEl = root.querySelector( '[data-yp-wd-staging-url]' );
		linkEl.href = data.staging_url;

		if ( ! data.can_respond ) {
			actionsEl.hidden = true;
			var alreadyEl = root.querySelector( '[data-yp-wd-already]' );
			alreadyEl.hidden = false;
			root.querySelector( '[data-yp-wd-already-text]' ).textContent = 'approved' === data.response
				? "You've already approved this staging site — we'll be in touch about going live."
				: 'changes_requested' === data.response
					? "You've already requested changes — we're on it."
					: 'Staging credentials have not been sent yet — check back soon.';
		}

		statusEl.hidden = true;
		contentEl.hidden = false;
	}

	function load() {
		if ( ! orderId ) {
			showError( "This link is missing information and can't be loaded. Please use the exact link you were sent." );
			return;
		}

		fetch( apiUrl( '/staging' ), { headers: { 'X-WP-Nonce': yeffoprintWebDesign.nonce } } )
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

	approveButton.addEventListener( 'click', function () {
		approveButton.disabled = true;

		apiPost( '/staging/approve' ).then( function ( result ) {
			approveButton.disabled = false;

			if ( ! result.ok ) {
				showMessage( ( result.data && result.data.message ) || "Couldn't approve — please try again.", true );
				return;
			}

			actionsEl.hidden = true;
			showMessage( "Thanks! You're approved — redirecting you to the go-live access form…", false );
			window.setTimeout( function () {
				window.location.href = result.data.golive_url;
			}, 1500 );
		} ).catch( function () {
			approveButton.disabled = false;
			showMessage( "Couldn't reach the server — please try again.", true );
		} );
	} );

	showChangesButton.addEventListener( 'click', function () {
		changesFormEl.hidden = false;
		notesEl.focus();
	} );

	cancelChangesButton.addEventListener( 'click', function () {
		changesFormEl.hidden = true;
	} );

	submitChangesButton.addEventListener( 'click', function () {
		if ( ! notesEl.value.trim() ) {
			showMessage( "Please describe what you'd like changed.", true );
			return;
		}

		submitChangesButton.disabled = true;

		apiPost( '/staging/request-changes', { notes: notesEl.value } ).then( function ( result ) {
			submitChangesButton.disabled = false;

			if ( ! result.ok ) {
				showMessage( ( result.data && result.data.message ) || "Couldn't submit your changes. Please try again.", true );
				return;
			}

			actionsEl.hidden = true;
			changesFormEl.hidden = true;
			showMessage( "Thanks — we've sent your notes to our design team and will follow up with an updated staging site.", false );
		} ).catch( function () {
			submitChangesButton.disabled = false;
			showMessage( "Couldn't reach the server — please try again.", true );
		} );
	} );

	load();
} )();
