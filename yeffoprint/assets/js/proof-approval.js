/**
 * Public proof-approval page (V2). Reached via a one-time link — either
 * `?custom_order=<id>&token=<token>` for a guest (the token itself is
 * the access credential, class-proof-approval-controller.php's
 * check_access()), or the same URL without a token for a logged-in
 * customer viewing their own request (nonce-protected instead, same as
 * any other authenticated write in this plugin).
 */
( function () {
	'use strict';

	if ( typeof yeffoprintProofApproval === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'yp-proof-approval' );
	if ( ! root ) {
		return;
	}

	var statusEl = root.querySelector( '.yp-configurator__status' );
	var contentEl = root.querySelector( '[data-yp-pa-content]' );
	var brandEl = root.querySelector( '[data-yp-pa-brand]' );
	var statusPillEl = root.querySelector( '[data-yp-pa-status-pill]' );
	var proofsEl = root.querySelector( '[data-yp-pa-proofs]' );
	var actionsEl = root.querySelector( '[data-yp-pa-actions]' );
	var approveButton = root.querySelector( '[data-yp-pa-approve]' );
	var showChangesButton = root.querySelector( '[data-yp-pa-show-changes]' );
	var changesFormEl = root.querySelector( '[data-yp-pa-changes-form]' );
	var notesEl = document.getElementById( 'yp-pa-notes' );
	var submitChangesButton = root.querySelector( '[data-yp-pa-submit-changes]' );
	var cancelChangesButton = root.querySelector( '[data-yp-pa-cancel-changes]' );
	var messageEl = root.querySelector( '[data-yp-pa-message]' );

	var params = new URLSearchParams( window.location.search );
	var customOrderId = params.get( 'custom_order' );
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
		var url = yeffoprintProofApproval.restUrl + 'custom-orders/' + encodeURIComponent( customOrderId ) + path;
		return token ? url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'token=' + encodeURIComponent( token ) : url;
	}

	function apiPost( path, body ) {
		return fetch( apiUrl( path ), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': yeffoprintProofApproval.nonce
			},
			body: JSON.stringify( Object.assign( { token: token }, body || {} ) )
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				return { ok: response.ok, data: data };
			} );
		} );
	}

	function renderProof( proof ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'yp-proof-approval__file';

		if ( proof.is_image ) {
			wrap.innerHTML = '<img src="' + encodeURI( proof.url ) + '" alt="Proof" />' +
				'<span class="yp-proof-approval__file-date">' + escapeHtml( proof.date ) + '</span>';
		} else {
			wrap.innerHTML = '<a class="yp-proof-approval__file-link" href="' + encodeURI( proof.url ) + '" target="_blank" rel="noopener noreferrer">View proof file</a>' +
				'<span class="yp-proof-approval__file-date">' + escapeHtml( proof.date ) + '</span>';
		}

		return wrap;
	}

	function render( data ) {
		brandEl.textContent = data.brand_name || 'Your custom design';
		statusPillEl.textContent = data.status_label || '';

		proofsEl.innerHTML = '';
		if ( data.proofs && data.proofs.length ) {
			data.proofs.forEach( function ( proof ) {
				proofsEl.appendChild( renderProof( proof ) );
			} );
		} else {
			proofsEl.innerHTML = '<p>No proof has been uploaded yet — check back soon.</p>';
		}

		actionsEl.hidden = ! data.can_respond;
		changesFormEl.hidden = true;

		statusEl.hidden = true;
		contentEl.hidden = false;
	}

	function load() {
		if ( ! customOrderId ) {
			showError( "This link is missing information and can't be loaded. Please use the exact link you were sent." );
			return;
		}

		fetch( apiUrl( '/proof' ), {
			headers: { 'X-WP-Nonce': yeffoprintProofApproval.nonce }
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					showError( ( result.data && result.data.message ) || "This proof couldn't be loaded." );
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

		apiPost( '/proof/approve' ).then( function ( result ) {
			approveButton.disabled = false;

			if ( ! result.ok ) {
				showMessage( ( result.data && result.data.message ) || "Couldn't approve this proof. Please try again.", true );
				return;
			}

			statusPillEl.textContent = result.data.status_label;
			actionsEl.hidden = true;
			showMessage( "Thanks! You've approved this proof — we'll begin printing shortly.", false );
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
			showMessage( 'Please describe what you\'d like changed.', true );
			return;
		}

		submitChangesButton.disabled = true;

		apiPost( '/proof/request-changes', { notes: notesEl.value } ).then( function ( result ) {
			submitChangesButton.disabled = false;

			if ( ! result.ok ) {
				showMessage( ( result.data && result.data.message ) || "Couldn't submit your changes. Please try again.", true );
				return;
			}

			statusPillEl.textContent = result.data.status_label;
			actionsEl.hidden = true;
			changesFormEl.hidden = true;
			showMessage( "Thanks — we've sent your changes to our design team and will follow up with a new proof.", false );
		} ).catch( function () {
			submitChangesButton.disabled = false;
			showMessage( "Couldn't reach the server — please try again.", true );
		} );
	} );

	load();
} )();
