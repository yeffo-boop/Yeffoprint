/**
 * Web Design quote-request form — REST-backed submit
 * (yeffoprint-core/v1/web-design-quote), same fetch/error-handling shape
 * as contact-form.js. The package <select> is populated from
 * `yeffoprintWebDesignQuote.packages` (localized server-side in
 * functions.php from the same YeffoPrint_Web_Design_Package_Meta::get_published()
 * the pricing table itself reads — see class-web-design-quote-controller.php's
 * own docblock) rather than a fetch, since that list is already known at
 * page-render time; a trailing "Not sure yet" option is appended here so
 * the choice is never blocking.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintWebDesignQuote === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'yp-web-design-quote' );
	if ( ! root ) {
		return;
	}

	var form = document.getElementById( 'yp-web-design-quote-form' );
	var submitButton = root.querySelector( '[data-yp-wdq-submit]' );
	var statusEl = null;

	/* ---------- Package select ---------- */

	var packageSelect = document.getElementById( 'yp-wdq-package' );
	var packages = yeffoprintWebDesignQuote.packages || [];

	packageSelect.innerHTML =
		'<option value="">Choose one&hellip;</option>' +
		packages.map( function ( name ) {
			return '<option value="' + escapeAttr( name ) + '">' + escapeHtml( name ) + '</option>';
		} ).join( '' ) +
		'<option value="Not sure yet">Not sure yet</option>';

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = String( value );
		return div.innerHTML;
	}

	function escapeAttr( value ) {
		return escapeHtml( value ).replace( /"/g, '&quot;' );
	}

	/* ---------- Conditional field reveals ---------- */

	function wireConditionalField( radioSelector, revealValue, fieldSelector ) {
		var radios = root.querySelectorAll( radioSelector );
		var field = root.querySelector( fieldSelector );

		function update() {
			var checked = root.querySelector( radioSelector + ':checked' );
			field.hidden = ! checked || checked.value !== revealValue;
		}

		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', update );
		} );
		update();
	}

	// Neither revealed field (website_url, domain_name) is required even
	// when shown — both are optional detail, same as everything past the
	// four "About you" fields.
	wireConditionalField( '[data-yp-wdq-has-website]', 'yes', '[data-yp-wdq-website-url-field]' );
	wireConditionalField( '[data-yp-wdq-has-domain]', 'yes', '[data-yp-wdq-domain-name-field]' );

	/* ---------- Submit ---------- */

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

	function radioValue( name ) {
		var checked = root.querySelector( 'input[name="' + name + '"]:checked' );
		return checked ? checked.value : '';
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		submitButton.disabled = true;
		showStatus( 'Sending…', false );

		var payload = {
			business_name: document.getElementById( 'yp-wdq-business' ).value,
			name: document.getElementById( 'yp-wdq-name' ).value,
			email: document.getElementById( 'yp-wdq-email' ).value,
			phone: document.getElementById( 'yp-wdq-phone' ).value,
			what_you_sell: document.getElementById( 'yp-wdq-sell' ).value,
			has_website: radioValue( 'has_website' ),
			website_url: document.getElementById( 'yp-wdq-website-url' ).value,
			product_count: document.getElementById( 'yp-wdq-product-count' ).value,
			package: packageSelect.value,
			timeline: document.getElementById( 'yp-wdq-timeline' ).value,
			has_hosting: radioValue( 'has_hosting' ),
			has_domain: radioValue( 'has_domain' ),
			domain_name: document.getElementById( 'yp-wdq-domain-name' ).value,
			wants_hosting_addon: radioValue( 'wants_hosting_addon' ),
			wants_maintenance: radioValue( 'wants_maintenance' ),
			details: document.getElementById( 'yp-wdq-details' ).value,
			website: document.getElementById( 'yp-wdq-website' ).value
		};

		fetch( yeffoprintWebDesignQuote.restUrl + 'web-design-quote', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': yeffoprintWebDesignQuote.nonce },
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
					showStatus( ( result.data && result.data.message ) || "Couldn't send your request. Please try again.", true );
					return;
				}

				form.hidden = true;
				showStatus( "Thanks — we've got your details and will follow up with a real quote soon.", false );
			} )
			.catch( function () {
				submitButton.disabled = false;
				showStatus( "Couldn't reach the server — please try again.", true );
			} );
	} );
} )();
