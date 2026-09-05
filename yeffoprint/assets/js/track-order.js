/**
 * Public order-tracking page. Reached from the "Track your order" link
 * in order emails (class-order-tracking.php builds it as
 * `?order=<id>&key=<order_key>`) — the order_key is WooCommerce's own
 * built-in guest-order secret, the same one its native "View order"
 * pages already trust, so a guest customer needs no account here
 * either. A logged-in customer viewing their own order, or staff, can
 * also open this with just `?order=<id>` — same nonce-protected fallback
 * pattern as proof-approval.js.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintTrackOrder === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'yp-track-order' );
	if ( ! root ) {
		return;
	}

	var statusEl = root.querySelector( '.yp-configurator__status' );
	var contentEl = root.querySelector( '[data-yp-to-content]' );
	var orderNumberEl = root.querySelector( '[data-yp-to-order-number]' );
	var stepperEl = root.querySelector( '[data-yp-to-stepper]' );
	var shipmentsEl = root.querySelector( '[data-yp-to-shipments]' );

	var params = new URLSearchParams( window.location.search );
	var orderId = params.get( 'order' );
	var key = params.get( 'key' ) || '';

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

	function apiUrl() {
		var url = yeffoprintTrackOrder.restUrl + 'orders/' + encodeURIComponent( orderId ) + '/tracking';
		return key ? url + '?key=' + encodeURIComponent( key ) : url;
	}

	function formatTimestamp( iso ) {
		if ( ! iso ) {
			return '';
		}
		var date = new Date( iso );
		if ( isNaN( date.getTime() ) ) {
			return iso;
		}
		return date.toLocaleString( undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' } );
	}

	function renderEvents( events ) {
		if ( ! events || ! events.length ) {
			return '<p class="yp-track-order__no-events">Live tracking updates aren\'t available for this shipment yet — check the direct link above for the latest from the carrier.</p>';
		}

		var items = events.map( function ( event, index ) {
			return '<li class="yp-track-order__event' + ( 0 === index ? ' is-latest' : '' ) + '">' +
				'<span class="yp-track-order__event-dot"></span>' +
				'<div class="yp-track-order__event-body">' +
					'<span class="yp-track-order__event-description">' + escapeHtml( event.description || event.status ) + '</span>' +
					'<span class="yp-track-order__event-meta">' + escapeHtml( [ formatTimestamp( event.timestamp ), event.location ].filter( Boolean ).join( ' — ' ) ) + '</span>' +
				'</div>' +
			'</li>';
		} ).join( '' );

		return '<ul class="yp-track-order__events">' + items + '</ul>';
	}

	/**
	 * Mirrors YeffoPrint_Order_Status_Stepper::render_html() on the PHP
	 * side (My Account → View Order) so both surfaces produce the exact
	 * same markup/classes for the shared order-stepper.css to style —
	 * this page renders it client-side since it's already JSON/REST-
	 * driven, rather than the server-rendered hook that page uses.
	 */
	function renderStepper( steps, statusLabel ) {
		if ( ! steps || ! steps.length ) {
			// Cancelled/refunded/failed — a forward-moving progress bar has
			// nothing honest to say here, so fall back to the plain status
			// text instead of rendering nothing at all.
			return '<span class="yp-track-order__status-pill">' + escapeHtml( statusLabel || '' ) + '</span>';
		}

		var items = steps.map( function ( step, index ) {
			var dot = 'complete' === step.state ? '&#10003;' : String( index + 1 );
			var sublabel = step.sublabel
				? '<span class="yp-order-stepper__sublabel">' + escapeHtml( step.sublabel ) + '</span>'
				: '';

			return '<li class="yp-order-stepper__step is-' + step.state + '">' +
				'<span class="yp-order-stepper__dot" aria-hidden="true">' + dot + '</span>' +
				'<span class="yp-order-stepper__label">' + escapeHtml( step.label ) + '</span>' +
				sublabel +
				'</li>';
		} ).join( '' );

		return '<ol class="yp-order-stepper">' + items + '</ol>';
	}

	function renderShipment( shipment ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'yp-track-order__shipment';

		var linkHtml = shipment.carrier_url
			? '<a class="yp-track-order__carrier-link" href="' + encodeURI( shipment.carrier_url ) + '" target="_blank" rel="noopener noreferrer">View on ' + escapeHtml( shipment.carrier_label ) + '’s site</a>'
			: '';

		wrap.innerHTML =
			'<div class="yp-track-order__shipment-header">' +
				'<span class="yp-track-order__carrier">' + escapeHtml( shipment.carrier_label ) + '</span>' +
				'<span class="yp-track-order__tracking-number">' + escapeHtml( shipment.tracking_number ) + '</span>' +
			'</div>' +
			renderEvents( shipment.events ) +
			linkHtml;

		return wrap;
	}

	function render( data ) {
		orderNumberEl.textContent = 'Order #' + data.order_number;
		stepperEl.innerHTML = renderStepper( data.steps, data.status_label );

		shipmentsEl.innerHTML = '';
		if ( data.shipments && data.shipments.length ) {
			data.shipments.forEach( function ( shipment ) {
				shipmentsEl.appendChild( renderShipment( shipment ) );
			} );
		} else {
			shipmentsEl.innerHTML = '<p>Nothing has shipped yet — check back once your order is on its way.</p>';
		}

		statusEl.hidden = true;
		contentEl.hidden = false;
	}

	function load() {
		if ( ! orderId ) {
			showError( "This link is missing information and can't be loaded. Please use the exact link from your order email." );
			return;
		}

		fetch( apiUrl(), {
			headers: { 'X-WP-Nonce': yeffoprintTrackOrder.nonce }
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					showError( ( result.data && result.data.message ) || "This order couldn't be loaded." );
					return;
				}

				render( result.data );
			} )
			.catch( function () {
				showError( "Couldn't reach the server — please try again." );
			} );
	}

	load();
} )();
