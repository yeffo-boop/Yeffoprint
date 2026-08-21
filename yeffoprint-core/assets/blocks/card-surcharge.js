/**
 * Keeps the card-surcharge fee live on the block-based Checkout as the
 * customer switches payment methods — see class-card-surcharge-blocks-
 * integration.php for why this round trip is needed at all. No build
 * step, matching the rest of this codebase's JS.
 */
( function () {
	'use strict';

	if ( typeof window.wp === 'undefined' || ! window.wp.data || typeof window.wc === 'undefined' || ! window.wc.blocksCheckout ) {
		return;
	}

	// Must match YeffoPrint_Card_Surcharge_Blocks_Integration::NAMESPACE.
	var NAMESPACE = 'yeffoprint-card-surcharge';
	var PAYMENT_STORE = 'wc/store/payment';

	var lastSent = null;
	var sending = false;

	function sync() {
		var selectors = window.wp.data.select( PAYMENT_STORE );
		if ( ! selectors || typeof selectors.getActivePaymentMethod !== 'function' ) {
			return;
		}

		var method = selectors.getActivePaymentMethod() || '';
		if ( method === lastSent || sending ) {
			return;
		}

		sending = true;
		lastSent = method;

		window.wc.blocksCheckout.extensionCartUpdate( {
			namespace: NAMESPACE,
			data: { payment_method: method }
		} ).catch( function () {
			// A failed sync just leaves the fee stale until the next
			// successful payment-method change — nothing to recover here.
		} ).finally( function () {
			sending = false;
		} );
	}

	// wp.data.subscribe() has no store-scoped overload old enough to rely
	// on across this plugin's supported WP range, so this listens globally
	// and relies on sync()'s own lastSent check to stay a no-op the rest
	// of the time.
	window.wp.data.subscribe( sync );
	sync();
} )();
