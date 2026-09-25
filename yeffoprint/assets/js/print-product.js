/**
 * 3D print product page (blocks/print-product/render.php). The page is
 * complete without this — every color is a real radio button — so this
 * only adds the live parts: each part's picked color name, the colored
 * badge on that part's photo dot, the running total, the "Your print"
 * summary, and Add to Cart through yeffoprint-core's /prints/cart
 * endpoint, which opens the cart drawer the same way the label
 * configurator does (site.js listens for `yp:cart-updated`).
 */

( function () {
	'use strict';

	var root = document.getElementById( 'yp-print' );
	if ( ! root || typeof yeffoprintPrint === 'undefined' ) {
		return;
	}

	var form = root.querySelector( '[data-yp-print-form]' );
	var slots = Array.prototype.slice.call( root.querySelectorAll( '[data-yp-slot]' ) );
	var qtyInput = root.querySelector( '[data-yp-qty-input]' );
	var totalEl = root.querySelector( '[data-yp-total]' );
	var summaryEl = root.querySelector( '[data-yp-summary]' );
	var statusEl = root.querySelector( '[data-yp-status]' );
	var addButton = root.querySelector( '[data-yp-add]' );
	var sizesEl = root.querySelector( '[data-yp-sizes]' );
	var basePrice = parseFloat( root.getAttribute( 'data-yp-base-price' ) ) || 0;

	function money( amount ) {
		return '$' + amount.toFixed( 2 );
	}

	function picked( slotEl ) {
		return slotEl.querySelector( 'input[type="radio"]:checked' );
	}

	function pickedSize() {
		var input = sizesEl ? sizesEl.querySelector( 'input[type="radio"]:checked' ) : null;
		return input ? input.value : '';
	}

	function slotName( slotEl ) {
		var strong = slotEl.querySelector( '.yp-print-slot__label strong' );
		return strong ? strong.textContent : '';
	}

	function quantity() {
		var q = parseInt( qtyInput.value, 10 );
		return isNaN( q ) ? 1 : Math.max( 1, Math.min( 100, q ) );
	}

	function update() {
		var unit = basePrice;
		var parts = sizesEl ? [ 'Size ' + ( pickedSize() || '(not picked)' ) ] : [];

		slots.forEach( function ( slotEl ) {
			var index = slotEl.getAttribute( 'data-yp-slot' );
			var input = picked( slotEl );
			var pickedEl = slotEl.querySelector( '[data-yp-picked]' );
			var dotColor = root.querySelector( '[data-yp-dot="' + index + '"] .yp-print__dot-color' );
			var extra = input ? parseFloat( input.getAttribute( 'data-extra' ) ) || 0 : 0;
			var label = input ? input.getAttribute( 'data-name' ) + ( extra > 0 ? ' +' + money( extra ) : '' ) : 'Pick a color';

			unit += extra;
			pickedEl.textContent = label;
			pickedEl.classList.toggle( 'is-missing', ! input );
			parts.push( slotName( slotEl ) + ' ' + ( input ? input.getAttribute( 'data-name' ) : '(not picked)' ) );

			if ( dotColor ) {
				dotColor.style.backgroundColor = input ? input.getAttribute( 'data-hex' ) : 'transparent';
				dotColor.classList.toggle( 'is-silk', !! input && 'silk' === input.getAttribute( 'data-finish' ) );
			}
		} );

		totalEl.textContent = money( unit * quantity() );

		if ( summaryEl ) {
			summaryEl.innerHTML = '';
			var strong = document.createElement( 'strong' );
			strong.textContent = 'Your print: ';
			summaryEl.appendChild( strong );
			summaryEl.appendChild( document.createTextNode( parts.join( ' · ' ) ) );
		}
	}

	function setStatus( message, isError ) {
		statusEl.textContent = message || '';
		statusEl.classList.toggle( 'is-error', !! isError );
	}

	// Hovering a part's picker lights up its dot on the photo.
	slots.forEach( function ( slotEl ) {
		var dot = root.querySelector( '[data-yp-dot="' + slotEl.getAttribute( 'data-yp-slot' ) + '"]' );
		if ( ! dot ) {
			return;
		}
		[ 'mouseenter', 'focusin' ].forEach( function ( type ) {
			slotEl.addEventListener( type, function () { dot.classList.add( 'is-active' ); } );
		} );
		[ 'mouseleave', 'focusout' ].forEach( function ( type ) {
			slotEl.addEventListener( type, function () { dot.classList.remove( 'is-active' ); } );
		} );
	} );

	root.querySelectorAll( '[data-yp-qty]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			qtyInput.value = Math.max( 1, Math.min( 100, quantity() + parseInt( button.getAttribute( 'data-yp-qty' ), 10 ) ) );
			update();
		} );
	} );

	form.addEventListener( 'change', function () {
		setStatus( '', false ); // A fresh pick clears an old "Pick a size." message.
		update();
	} );
	qtyInput.addEventListener( 'input', update );

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		var colors = {};
		var missing = '';

		if ( sizesEl && ! pickedSize() ) {
			setStatus( 'Pick a size.', true );
			sizesEl.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
			return;
		}

		slots.forEach( function ( slotEl ) {
			var input = picked( slotEl );
			if ( input ) {
				colors[ slotEl.getAttribute( 'data-yp-slot' ) ] = parseInt( input.value, 10 );
			} else if ( ! missing ) {
				missing = slotName( slotEl );
			}
		} );

		if ( missing ) {
			setStatus( 'Pick a color for ' + missing + '.', true );
			return;
		}

		addButton.disabled = true;
		setStatus( 'Adding to your cart…', false );

		fetch( yeffoprintPrint.restUrl + 'prints/cart', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': yeffoprintPrint.nonce },
			body: JSON.stringify( {
				print_id: parseInt( root.getAttribute( 'data-yp-print-id' ), 10 ),
				size: pickedSize(),
				colors: colors,
				quantity: quantity()
			} )
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				addButton.disabled = false;

				if ( ! result.ok ) {
					setStatus( ( result.data && result.data.message ) || 'Couldn’t add this to your cart.', true );
					return;
				}

				setStatus( 'Added to your cart.', false );
				document.dispatchEvent( new CustomEvent( 'yp:cart-updated', {
					detail: {
						count: result.data.cart_count,
						drawerHtml: result.data.drawer_html
					}
				} ) );
			} )
			.catch( function () {
				addButton.disabled = false;
				setStatus( 'Couldn’t reach the server. Please try again.', true );
			} );
	} );

	update();
} )();
