/**
 * Keeps the card-surcharge fee (class-card-surcharge.php) live on the
 * classic "Pay for order" page as the customer switches payment
 * methods. That page has no cart to hook woocommerce_cart_calculate_
 * fees into (it works off an already-placed order), and unlike the
 * real Checkout, WooCommerce never AJAX-refreshes its totals on its
 * own when a different payment radio is picked — this fills that gap
 * with its own small round trip. No build step, matching the rest of
 * this codebase's JS.
 */
( function () {
	'use strict';

	if ( typeof window.yeffoprintPayOrderSurcharge === 'undefined' || typeof window.fetch === 'undefined' ) {
		return;
	}

	var settings = window.yeffoprintPayOrderSurcharge;
	var tfoot     = document.querySelector( 'table.shop_table tfoot' );
	var radios    = document.querySelectorAll( '.wc_payment_methods input[name="payment_method"]' );

	if ( ! tfoot || ! radios.length ) {
		return;
	}

	// The order's secret key lives in the page URL (?pay_for_order=true&
	// key=wc_order_xxx) — sent back so the AJAX handler can prove this
	// request actually knows the order, the same ownership check
	// WC_Form_Handler::pay_action() itself makes for a guest paying an
	// order link (see class-card-surcharge.php's ajax handler).
	var orderKey = new URLSearchParams( window.location.search ).get( 'key' ) || '';

	var sending = false;
	var pending = null;

	function sync( method ) {
		if ( sending ) {
			// A second change before the first request lands — remember
			// the latest choice and send it once the in-flight one
			// finishes, rather than dropping it or racing two responses.
			pending = method;
			return;
		}

		sending = true;
		pending = null;

		var body = new URLSearchParams( {
			action: 'yeffoprint_sync_pay_order_surcharge',
			nonce: settings.nonce,
			order_id: settings.orderId,
			order_key: orderKey,
			payment_method: method
		} );

		window.fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( response ) { return response.json(); } )
			.then( function ( json ) {
				if ( json && json.success && json.data && typeof json.data.totalsHtml === 'string' ) {
					tfoot.innerHTML = json.data.totalsHtml;
				}
			} )
			.catch( function () {
				// Leaves the totals table showing whatever gateway was
				// last synced — the same fee still gets applied server-
				// side (woocommerce_before_pay_action) at actual
				// submission regardless of whether this preview
				// succeeded, so only the live preview is at risk here.
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

	radios.forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			sync( radio.value );
		} );
	} );
} )();
