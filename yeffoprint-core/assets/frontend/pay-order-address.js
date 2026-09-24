/**
 * Address + shipping-method step on the classic "Pay for order" page
 * (class-order-pay-address.php): shows/hides the billing fields, only
 * offers shipping methods that fit the shipping country (domestic ones
 * for the store's own country, international ones everywhere else), and
 * refreshes the totals table when one is picked. The card-surcharge
 * script (pay-order-surcharge.js) is then re-run by re-firing the checked
 * payment radio's change event, so its fee is sized from the new total.
 * No build step, matching the rest of this codebase's JS.
 */
( function ( $ ) {
	'use strict';

	var settings = window.yeffoprintPayOrderAddress;
	var root     = document.querySelector( '[data-yp-pay-address]' );

	if ( ! settings || ! root ) {
		return;
	}

	var billToggle   = root.querySelector( '[data-yp-bill-toggle]' );
	var billingBlock = root.querySelector( '[data-yp-billing-fields]' );

	if ( billToggle && billingBlock ) {
		billToggle.addEventListener( 'change', function () {
			billingBlock.hidden = ! billToggle.checked;
		} );
	}

	var shippingBlock = root.querySelector( '[data-yp-pay-shipping]' );
	if ( ! shippingBlock ) {
		return;
	}

	var emptyNote = shippingBlock.querySelector( '[data-yp-shipping-empty]' );
	var tfoot     = document.querySelector( 'table.shop_table tfoot' );
	var orderKey  = new URLSearchParams( window.location.search ).get( 'key' ) || '';

	function country() {
		var field = document.getElementById( 'shipping_country' );
		return field ? String( field.value || '' ).toUpperCase() : '';
	}

	function regionFits( region ) {
		var current = country();
		if ( ! current || 'any' === region ) {
			return true;
		}
		return ( current === settings.domesticCountry ) === ( 'domestic' === region );
	}

	var sending = false;
	var pending = null;

	function sync( option ) {
		if ( sending ) {
			pending = option;
			return;
		}
		sending = true;
		pending = null;

		var body = new URLSearchParams( {
			action: 'yeffoprint_pay_order_shipping',
			nonce: settings.nonce,
			order_id: settings.orderId,
			order_key: orderKey,
			country: country(),
			option: option
		} );

		window.fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( response ) { return response.json(); } )
			.then( function ( json ) {
				if ( tfoot && json && json.success && json.data && 'string' === typeof json.data.totalsHtml ) {
					tfoot.innerHTML = json.data.totalsHtml;
				}
				// Re-size the card fee from the new total.
				var checkedPayment = document.querySelector( '.wc_payment_methods input[name="payment_method"]:checked' );
				if ( checkedPayment ) {
					checkedPayment.dispatchEvent( new Event( 'change' ) );
				}
			} )
			.catch( function () {
				// Only the live preview is lost; the pick is still
				// validated and applied when the form is submitted.
			} )
			.finally( function () {
				sending = false;
				if ( null !== pending ) {
					var next = pending;
					pending = null;
					sync( next );
				}
			} );
	}

	function checkedOption() {
		var checked = shippingBlock.querySelector( 'input[name="yp_pay_shipping"]:checked' );
		return checked ? checked.value : '';
	}

	function filterOptions( sendChange ) {
		var visible     = 0;
		var lostChecked = false;

		shippingBlock.querySelectorAll( '[data-yp-region]' ).forEach( function ( row ) {
			var fits  = regionFits( row.getAttribute( 'data-yp-region' ) );
			var radio = row.querySelector( 'input' );
			row.hidden = ! fits;
			if ( fits ) {
				visible++;
			} else if ( radio.checked ) {
				radio.checked = false;
				lostChecked = true;
			}
		} );

		// One option left for this country: pick it for them.
		var visibleRadios = shippingBlock.querySelectorAll( '[data-yp-region]:not([hidden]) input' );
		if ( 1 === visibleRadios.length && ! visibleRadios[ 0 ].checked ) {
			visibleRadios[ 0 ].checked = true;
			lostChecked = true;
		}

		if ( emptyNote ) {
			emptyNote.hidden = visible > 0;
		}

		if ( sendChange && lostChecked ) {
			sync( checkedOption() );
		}
	}

	shippingBlock.querySelectorAll( 'input[name="yp_pay_shipping"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			sync( radio.value );
		} );
	} );

	// WooCommerce's country dropdown is a selectWoo widget, which fires
	// jQuery change events rather than native ones.
	if ( $ ) {
		$( document.body ).on( 'change', '#shipping_country', function () {
			filterOptions( true );
		} );
	} else {
		var countryField = document.getElementById( 'shipping_country' );
		if ( countryField ) {
			countryField.addEventListener( 'change', function () { filterOptions( true ); } );
		}
	}

	filterOptions( true );
} )( window.jQuery );
