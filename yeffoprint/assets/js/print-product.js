/**
 * 3D print product page (blocks/print-product/render.php). The page is
 * complete without this — every filament is a real radio button inside a
 * <details> — so this only adds the live parts: each part's picked
 * filament row, the pickers' search / group chips / bigger photo on
 * hover or press-and-hold, the badge on that part's photo dot, the
 * running total, the "Your print" summary, the optional lid text / lid image add-ons (the image goes
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
	var photoEl = root.querySelector( '[data-yp-photo]' );
	var photoImg = root.querySelector( '[data-yp-photo-img]' );
	var photoEmpty = root.querySelector( '[data-yp-photo-empty]' );
	var mainImage = photoEl ? photoEl.getAttribute( 'data-yp-main-image' ) : '';
	var imageUploading = null;

	function money( amount ) {
		return '$' + amount.toFixed( 2 );
	}

	/* ---------- Filament pickers ---------- */

	var pickers = Array.prototype.slice.call( root.querySelectorAll( '[data-yp-fil-picker]' ) );

	// The picked filament's row: same tile as its card, name, then brand
	// and any extra charge.
	function renderChosen( pickerEl ) {
		var chosen = pickerEl.querySelector( '[data-yp-fil-chosen]' );
		var input = pickerEl.querySelector( 'input[type="radio"]:checked' );
		var toggle = chosen.querySelector( '.yp-fil-picker__toggle' );

		Array.prototype.slice.call( chosen.children ).forEach( function ( child ) {
			if ( child !== toggle ) {
				chosen.removeChild( child );
			}
		} );

		var tile;
		var text = document.createElement( 'span' );
		var name = document.createElement( 'b' );
		text.className = 'yp-fil__text';

		if ( input ) {
			tile = input.parentNode.querySelector( '.yp-fil__tile' ).cloneNode( true );
			var extra = parseFloat( input.getAttribute( 'data-extra' ) ) || 0;
			var small = document.createElement( 'small' );
			name.textContent = input.getAttribute( 'data-name' );
			small.textContent = [ input.getAttribute( 'data-brand' ), extra > 0 ? '+' + money( extra ) : '' ].filter( Boolean ).join( ' · ' );
			text.appendChild( name );
			text.appendChild( small );
		} else {
			tile = document.createElement( 'span' );
			tile.className = 'yp-fil__tile is-empty';
			name.className = 'is-missing';
			name.textContent = 'Pick a filament';
			text.appendChild( name );
		}

		chosen.insertBefore( tile, toggle );
		chosen.insertBefore( text, toggle );
	}

	function filterPicker( pickerEl ) {
		var search = pickerEl.querySelector( '[data-yp-fil-search]' );
		var chip = pickerEl.querySelector( '[data-yp-fil-chip].is-on' );
		var words = search ? search.value.trim().toLowerCase().split( /\s+/ ).filter( Boolean ) : [];
		var group = chip ? chip.getAttribute( 'data-yp-fil-chip' ) : '';
		var shown = 0;

		pickerEl.querySelectorAll( '[data-yp-fil-group]' ).forEach( function ( groupEl ) {
			var inGroup = ! group || groupEl.getAttribute( 'data-yp-fil-group' ) === group;
			var groupShown = 0;
			groupEl.querySelectorAll( '[data-yp-fil]' ).forEach( function ( card ) {
				var haystack = card.getAttribute( 'data-search' ) || '';
				var match = inGroup && words.every( function ( word ) { return haystack.indexOf( word ) !== -1; } );
				card.hidden = ! match;
				if ( match ) {
					groupShown++;
				}
			} );
			groupEl.hidden = ! groupShown;
			shown += groupShown;
		} );

		pickerEl.querySelector( '[data-yp-fil-empty]' ).hidden = shown > 0;
	}

	// One floating bigger photo, shared by every picker. It lives on
	// <body> so the scrolling list can't clip it.
	var zoomEl = document.createElement( 'div' );
	zoomEl.className = 'yp-fil-zoom';
	zoomEl.hidden = true;
	document.body.appendChild( zoomEl );
	var zoomTimer = null;
	var suppressClick = false;

	function showZoom( card ) {
		var input = card.querySelector( 'input' );
		var image = input.getAttribute( 'data-image' );
		if ( ! image ) {
			return;
		}
		var line = [ input.getAttribute( 'data-brand' ), input.getAttribute( 'data-line' ) ].filter( Boolean ).join( ' · ' );
		zoomEl.innerHTML = '';
		var img = document.createElement( 'img' );
		img.src = image;
		img.alt = '';
		var name = document.createElement( 'b' );
		name.textContent = input.getAttribute( 'data-name' );
		var small = document.createElement( 'small' );
		small.textContent = line;
		zoomEl.appendChild( img );
		zoomEl.appendChild( name );
		zoomEl.appendChild( small );
		zoomEl.hidden = false;

		// Beside the picker on wide screens, above the card otherwise.
		var rect = card.getBoundingClientRect();
		var panel = card.closest( '[data-yp-fil-picker]' ).getBoundingClientRect();
		var width = zoomEl.offsetWidth;
		var height = zoomEl.offsetHeight;
		var left = panel.left - width - 12;
		var top = Math.max( 8, Math.min( window.innerHeight - height - 8, rect.top + rect.height / 2 - height / 2 ) );
		if ( left < 8 ) {
			left = Math.max( 8, Math.min( window.innerWidth - width - 8, rect.left + rect.width / 2 - width / 2 ) );
			top = rect.top - height - 8 < 8 ? rect.bottom + 8 : rect.top - height - 8;
		}
		zoomEl.style.left = left + 'px';
		zoomEl.style.top = top + 'px';
	}

	function hideZoom() {
		clearTimeout( zoomTimer );
		zoomEl.hidden = true;
	}

	pickers.forEach( function ( pickerEl ) {
		var tools = pickerEl.querySelector( '[data-yp-fil-tools]' );
		var search = pickerEl.querySelector( '[data-yp-fil-search]' );

		if ( tools ) {
			tools.hidden = false;
		}
		if ( search ) {
			search.addEventListener( 'input', function () { filterPicker( pickerEl ); } );
			// Enter in the search box shouldn't submit the whole form.
			search.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
				}
			} );
		}

		pickerEl.querySelectorAll( '[data-yp-fil-chip]' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				pickerEl.querySelectorAll( '[data-yp-fil-chip]' ).forEach( function ( other ) {
					other.classList.toggle( 'is-on', other === chip );
					other.setAttribute( 'aria-pressed', other === chip ? 'true' : 'false' );
				} );
				filterPicker( pickerEl );
			} );
		} );

		pickerEl.querySelectorAll( '[data-yp-fil]' ).forEach( function ( card ) {
			// A tap or click picks and folds the picker back to one row;
			// arrow keys move through filaments without closing it.
			card.addEventListener( 'click', function ( event ) {
				if ( suppressClick ) {
					event.preventDefault();
					suppressClick = false;
					return;
				}
				if ( event.target.tagName === 'INPUT' && ! event.detail ) {
					return;
				}
				var input = card.querySelector( 'input' );
				if ( input.disabled ) {
					return;
				}
				setTimeout( function () {
					pickerEl.open = false;
					hideZoom();
					pickerEl.querySelector( 'summary' ).focus( { preventScroll: true } );
				}, 120 );
			} );

			card.addEventListener( 'mouseenter', function () {
				if ( window.matchMedia( '(hover: hover)' ).matches ) {
					showZoom( card );
				}
			} );
			card.addEventListener( 'mouseleave', hideZoom );

			card.addEventListener( 'touchstart', function () {
				clearTimeout( zoomTimer );
				zoomTimer = setTimeout( function () {
					suppressClick = true;
					showZoom( card );
				}, 450 );
			}, { passive: true } );
			card.addEventListener( 'touchmove', hideZoom, { passive: true } );
			card.addEventListener( 'touchend', function () {
				clearTimeout( zoomTimer );
				if ( ! zoomEl.hidden ) {
					setTimeout( hideZoom, 900 );
				}
			} );
			card.addEventListener( 'contextmenu', function ( event ) {
				if ( ! zoomEl.hidden ) {
					event.preventDefault(); // Long-press shows the photo, not the browser menu.
				}
			} );
		} );

		pickerEl.addEventListener( 'toggle', hideZoom );
		pickerEl.querySelector( '[data-yp-fil-list]' ).addEventListener( 'scroll', hideZoom, { passive: true } );
	} );

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

	// The picked size's own photo, or the main photo for a size without
	// one. Dots belong to the main photo, so they hide on a size photo.
	function showSizePhoto() {
		if ( ! photoImg || ! sizesEl ) {
			return;
		}
		var input = sizesEl.querySelector( 'input[type="radio"]:checked' );
		var sizeImage = input ? input.getAttribute( 'data-image' ) : '';
		var src = sizeImage || mainImage;

		if ( src && photoImg.getAttribute( 'src' ) !== src ) {
			photoImg.setAttribute( 'src', src );
		}
		photoImg.hidden = ! src;
		if ( photoEmpty ) {
			photoEmpty.hidden = !! src;
		}
		photoEl.classList.toggle( 'is-size-photo', !! sizeImage && sizeImage !== mainImage );
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

	function filamentLabel( input ) {
		var brand = input.getAttribute( 'data-brand' );
		return input.getAttribute( 'data-name' ) + ( brand ? ' (' + brand + ')' : '' );
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

		pickers.forEach( renderChosen );

		slots.forEach( function ( slotEl ) {
			var index = slotEl.getAttribute( 'data-yp-slot' );
			var input = picked( slotEl );
			var dotColor = root.querySelector( '[data-yp-dot="' + index + '"] .yp-print__dot-color' );
			var extra = input ? parseFloat( input.getAttribute( 'data-extra' ) ) || 0 : 0;

			unit += extra;
			parts.push( slotName( slotEl ) + ' ' + ( input ? filamentLabel( input ) : '(not picked)' ) );

			if ( dotColor ) {
				var image = input ? input.getAttribute( 'data-image' ) : '';
				var tileImg = input && image ? input.parentNode.querySelector( '.yp-fil__tile img' ) : null;
				dotColor.style.backgroundColor = input ? input.getAttribute( 'data-hex' ) : 'transparent';
				dotColor.style.backgroundImage = image ? 'url("' + image.replace( /"/g, '%22' ) + '")' : '';
				dotColor.style.backgroundSize = tileImg ? ( parseFloat( tileImg.style.transform.replace( /[^0-9.]/g, '' ) ) || 1 ) * 100 + '%' : '';
				dotColor.style.backgroundPosition = tileImg ? tileImg.style.objectPosition : '';
				dotColor.classList.toggle( 'is-silk', !! input && ! image && 'silk' === input.getAttribute( 'data-finish' ) );
			}
		} );
		if ( lidText() ) {
			parts.push( 'Text “' + lidText() + '” in ' + ( addonColor( textAddon ) ? filamentLabel( addonColor( textAddon ) ) : '(filament not picked)' ) );
		}
		if ( addonOn( imageAddon ) ) {
			parts.push( ( imageId ? 'Your image' : 'Image (not uploaded yet)' ) + ' in ' + ( addonColor( imageAddon ) ? filamentLabel( addonColor( imageAddon ) ) : '(filament not picked)' ) );
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

	function openPicker( containerEl ) {
		var pickerEl = containerEl.querySelector( '[data-yp-fil-picker]' );
		if ( pickerEl ) {
			pickerEl.open = true;
		}
		containerEl.scrollIntoView( { block: 'center', behavior: 'smooth' } );
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
		showSizePhoto();
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
				missing = { name: slotName( slotEl ), el: slotEl };
			}
		} );

		if ( missing ) {
			setStatus( 'Pick a filament for ' + missing.name + '.', true );
			openPicker( missing.el );
			return;
		}

		if ( addonOn( textAddon ) && ! lidText() ) {
			setStatus( 'Type your text, or untick "Add text".', true );
			textInput.focus();
			return;
		}

		if ( lidText() && ! addonColor( textAddon ) ) {
			setStatus( 'Pick a filament for your text.', true );
			openPicker( textAddon.querySelector( '[data-yp-addon-colors]' ) );
			return;
		}

		if ( addonOn( imageAddon ) && ! addonColor( imageAddon ) ) {
			setStatus( 'Pick a filament for your image.', true );
			openPicker( imageAddon.querySelector( '[data-yp-addon-colors]' ) );
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
