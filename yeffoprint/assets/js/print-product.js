/**
 * 3D print product page (blocks/print-product/render.php). The page is
 * complete without this — every color is a real radio button — so this
 * only adds the live parts: each part's picked color name, the colored
 * badge on that part's photo dot, the running total, the "Your print"
 * summary, the optional lid text / lid image add-ons (the image goes
 * up through the same /custom-orders/uploads endpoint the custom label
 * form uses), and Add to Cart through yeffoprint-core's /prints/cart
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
	var sizeErrorEl = root.querySelector( '[data-yp-size-error]' );
	var basePrice = parseFloat( root.getAttribute( 'data-yp-base-price' ) ) || 0;
	var fromPrice = parseFloat( root.getAttribute( 'data-yp-from-price' ) ) || basePrice;
	var textAddon = root.querySelector( '[data-yp-addon="text"]' );
	var imageAddon = root.querySelector( '[data-yp-addon="image"]' );
	var textInput = root.querySelector( '[data-yp-text]' );
	var textCount = root.querySelector( '[data-yp-text-count]' );
	var imageFile = root.querySelector( '[data-yp-image-file]' );
	var imageLabel = root.querySelector( '[data-yp-image-label]' );
	var imageId = 0;
	var imageUploading = null;

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

	// Until a size is picked, the total shows the cheapest size (the
	// "From" price) rather than a base price no size actually sells at.
	function sizePrice() {
		if ( ! sizesEl ) {
			return basePrice;
		}
		var input = sizesEl.querySelector( 'input[type="radio"]:checked' );
		return input ? parseFloat( input.getAttribute( 'data-price' ) ) || basePrice : fromPrice;
	}

	function addonOn( addonEl ) {
		var toggle = addonEl ? addonEl.querySelector( '[data-yp-addon-toggle]' ) : null;
		return !! toggle && toggle.checked;
	}

	function addonPrice( addonEl ) {
		return addonOn( addonEl ) ? parseFloat( addonEl.querySelector( '[data-yp-addon-toggle]' ).getAttribute( 'data-price' ) ) || 0 : 0;
	}

	function addonColor( addonEl ) {
		var input = addonEl ? addonEl.querySelector( '[data-yp-addon-colors] input:checked' ) : null;
		return input;
	}

	function lidText() {
		return textInput && addonOn( textAddon ) ? textInput.value.trim() : '';
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
		var unit = sizePrice() + addonPrice( textAddon ) + addonPrice( imageAddon );
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

		[ textAddon, imageAddon ].forEach( function ( addonEl ) {
			var pickedEl = addonEl ? addonEl.querySelector( '[data-yp-addon-colors] [data-yp-picked]' ) : null;
			var input = addonColor( addonEl );
			if ( pickedEl ) {
				pickedEl.textContent = input ? input.getAttribute( 'data-name' ) : 'Pick a color';
				pickedEl.classList.toggle( 'is-missing', ! input );
			}
		} );
		if ( lidText() ) {
			parts.push( 'Text “' + lidText() + '” in ' + ( addonColor( textAddon ) ? addonColor( textAddon ).getAttribute( 'data-name' ) : '(color not picked)' ) );
		}
		if ( addonOn( imageAddon ) ) {
			parts.push( ( imageId ? 'Your image' : 'Image (not uploaded yet)' ) + ' in ' + ( addonColor( imageAddon ) ? addonColor( imageAddon ).getAttribute( 'data-name' ) : '(color not picked)' ) );
		}
		if ( textCount && textInput ) {
			textCount.textContent = textInput.value.length;
		}

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
		if ( sizesEl && pickedSize() ) {
			sizesEl.classList.remove( 'is-missing' );
			sizeErrorEl.hidden = true;
		}
		update();
	} );
	qtyInput.addEventListener( 'input', update );
	if ( textInput ) {
		textInput.addEventListener( 'input', update );
	}

	// Ticking an add-on opens its box; unticking hides it and drops it
	// from the price (the typed text / uploaded image stay, in case the
	// customer ticks it again).
	[ textAddon, imageAddon ].forEach( function ( addonEl ) {
		if ( ! addonEl ) {
			return;
		}
		var toggle = addonEl.querySelector( '[data-yp-addon-toggle]' );
		var body = addonEl.querySelector( '[data-yp-addon-body]' );
		toggle.addEventListener( 'change', function () {
			body.hidden = ! toggle.checked;
			addonEl.classList.toggle( 'is-on', toggle.checked );
			if ( toggle.checked && addonEl === textAddon && textInput ) {
				textInput.focus();
			}
		} );
	} );

	// The image uploads as soon as it's chosen, so Add to Cart only has
	// to send its ID.
	if ( imageFile ) {
		imageFile.addEventListener( 'change', function () {
			var file = imageFile.files && imageFile.files[ 0 ];
			imageId = 0;
			if ( ! file ) {
				imageLabel.textContent = 'Choose a logo or image';
				update();
				return;
			}

			var data = new FormData();
			data.append( 'files[]', file );
			imageLabel.textContent = 'Uploading ' + file.name + '…';
			imageAddon.classList.remove( 'is-error' );

			imageUploading = fetch( yeffoprintPrint.restUrl + 'custom-orders/uploads', {
				method: 'POST',
				headers: { 'X-WP-Nonce': yeffoprintPrint.nonce },
				body: data
			} )
				.then( function ( response ) {
					return response.json().then( function ( json ) {
						return { ok: response.ok, data: json };
					} );
				} )
				.then( function ( result ) {
					var uploaded = result.ok && result.data && result.data.files && result.data.files[ 0 ];
					if ( uploaded && uploaded.success ) {
						imageId = uploaded.id;
						imageLabel.textContent = '✓ ' + file.name;
					} else {
						imageLabel.textContent = ( uploaded && uploaded.message ) || ( result.data && result.data.message ) || 'That file didn’t upload. Try another.';
						imageAddon.classList.add( 'is-error' );
					}
				} )
				.catch( function () {
					imageLabel.textContent = 'Couldn’t upload. Please try again.';
					imageAddon.classList.add( 'is-error' );
				} )
				.then( function () {
					imageUploading = null;
					update();
				} );
		} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		var colors = {};
		var missing = '';

		// The message sits right at the size picker, not only under the
		// button: on a phone the scroll up to the sizes would otherwise
		// hide it, and Add to Cart looks like it did nothing.
		if ( sizesEl && ! pickedSize() ) {
			setStatus( 'Pick a size.', true );
			sizesEl.classList.add( 'is-missing' );
			sizeErrorEl.hidden = false;
			sizesEl.scrollIntoView( { block: 'center', behavior: 'smooth' } );
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

		if ( addonOn( textAddon ) && ! lidText() ) {
			setStatus( 'Type your text, or untick "Add text".', true );
			textInput.focus();
			return;
		}

		if ( lidText() && ! addonColor( textAddon ) ) {
			setStatus( 'Pick a color for your text.', true );
			textAddon.querySelector( '[data-yp-addon-colors]' ).scrollIntoView( { block: 'center', behavior: 'smooth' } );
			return;
		}

		if ( addonOn( imageAddon ) && ! addonColor( imageAddon ) ) {
			setStatus( 'Pick a color for your image.', true );
			imageAddon.querySelector( '[data-yp-addon-colors]' ).scrollIntoView( { block: 'center', behavior: 'smooth' } );
			return;
		}

		if ( addonOn( imageAddon ) && imageUploading ) {
			setStatus( 'Your image is still uploading. One moment…', false );
			return;
		}

		if ( addonOn( imageAddon ) && ! imageId ) {
			setStatus( 'Choose your image, or untick "Add an image".', true );
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
				text: lidText(),
				text_color: lidText() && addonColor( textAddon ) ? parseInt( addonColor( textAddon ).value, 10 ) : 0,
				image_id: addonOn( imageAddon ) ? imageId : 0,
				image_color: addonOn( imageAddon ) && addonColor( imageAddon ) ? parseInt( addonColor( imageAddon ).value, 10 ) : 0,
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
