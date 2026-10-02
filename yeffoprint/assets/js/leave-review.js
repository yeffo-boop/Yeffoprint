/**
 * Leave a Review page (/leave-a-review/). Reached from the star row and
 * button in the Delivered email, or the Review link on a My Account
 * order: `?order=<id>&key=<order_key>[&rating=1-5]`. The order_key is
 * the same guest secret the /track-order/ page trusts, so no account is
 * needed. A tapped email star arrives as `rating` and is pre-picked.
 *
 * Photos are shrunk in the browser (longest side 1800px, JPEG) before
 * upload, so a 12 MB phone photo goes up as a few hundred KB, and
 * iPhone HEIC photos arrive as JPEG wherever the browser can decode
 * them. Anything it can't decode is sent as-is and checked server-side.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintLeaveReview === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'yp-leave-review' );
	if ( ! root ) {
		return;
	}

	var WORDS = [ 'Tap a star', 'Poor', 'Fair', 'Good', 'Great', 'Excellent!' ];
	var MAX_SIDE = 1800;

	var statusEl  = root.querySelector( '.yp-configurator__status' );
	var contentEl = root.querySelector( '[data-yp-lr-content]' );
	var orderEl   = root.querySelector( '[data-yp-lr-order]' );
	var formEl    = root.querySelector( '[data-yp-lr-form]' );
	var starsEl   = root.querySelector( '[data-yp-lr-stars]' );
	var wordEl    = root.querySelector( '[data-yp-lr-rating-word]' );
	var textEl    = root.querySelector( '#yp-lr-text' );
	var countEl   = root.querySelector( '[data-yp-lr-count]' );
	var nameEl    = root.querySelector( '#yp-lr-name' );
	var photosEl  = root.querySelector( '[data-yp-lr-photos]' );
	var addEl     = root.querySelector( '[data-yp-lr-add-photo]' );
	var fileEl    = root.querySelector( '[data-yp-lr-file]' );
	var errorEl   = root.querySelector( '[data-yp-lr-error]' );
	var submitEl  = root.querySelector( '[data-yp-lr-submit]' );
	var doneEl    = root.querySelector( '[data-yp-lr-done]' );
	var titleEl   = root.querySelector( '[data-yp-lr-title]' );

	var params  = new URLSearchParams( window.location.search );
	var orderId = params.get( 'order' );
	var key     = params.get( 'key' ) || '';

	var rating    = Math.min( 5, Math.max( 0, parseInt( params.get( 'rating' ), 10 ) || 0 ) );
	var photos    = []; // { blob, name, url }
	var maxPhotos = 5;
	var maxText   = 2000;
	var firstName = '';

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}

	function starString( n ) {
		var out = '';
		for ( var i = 1; i <= 5; i++ ) {
			out += '<span class="yp-leave-review__mini-star' + ( i <= n ? ' is-on' : '' ) + '" aria-hidden="true">&#9733;</span>';
		}
		return '<span class="yp-leave-review__mini-stars" aria-label="' + n + ' out of 5 stars">' + out + '</span>';
	}

	function showStatus( message, isError ) {
		statusEl.textContent = message;
		if ( isError ) {
			statusEl.setAttribute( 'data-state', 'error' );
		}
		statusEl.hidden = false;
		contentEl.hidden = true;
	}

	function apiUrl() {
		var url = yeffoprintLeaveReview.restUrl + 'reviews/order/' + encodeURIComponent( orderId );
		return key ? url + '?key=' + encodeURIComponent( key ) : url;
	}

	function headers() {
		return yeffoprintLeaveReview.nonce ? { 'X-WP-Nonce': yeffoprintLeaveReview.nonce } : {};
	}

	/* ---------- Stars ---------- */

	function renderStars() {
		starsEl.innerHTML = '';
		for ( var i = 1; i <= 5; i++ ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'yp-leave-review__star' + ( i <= rating ? ' is-on' : '' );
			button.setAttribute( 'role', 'radio' );
			button.setAttribute( 'aria-checked', i === rating ? 'true' : 'false' );
			button.setAttribute( 'aria-label', i + ( 1 === i ? ' star' : ' stars' ) );
			button.tabIndex = ( i === rating || ( ! rating && 1 === i ) ) ? 0 : -1;
			button.innerHTML = '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 1.5l2.4 5.2 5.6.6-4.2 3.8 1.2 5.5L10 13.8 4.9 16.6l1.2-5.5L2 7.3l5.6-.6z"/></svg>';
			button.setAttribute( 'data-value', String( i ) );
			starsEl.appendChild( button );
		}
		wordEl.textContent = WORDS[ rating ];
	}

	function setRating( value, focus ) {
		rating = value;
		renderStars();
		errorEl.hidden = true;
		if ( focus ) {
			starsEl.querySelector( '[data-value="' + value + '"]' ).focus();
		}
	}

	starsEl.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-value]' );
		if ( button ) {
			setRating( parseInt( button.getAttribute( 'data-value' ), 10 ), true );
		}
	} );

	starsEl.addEventListener( 'keydown', function ( event ) {
		var current = rating || 1;
		if ( 'ArrowRight' === event.key || 'ArrowUp' === event.key ) {
			event.preventDefault();
			setRating( Math.min( 5, current + ( rating ? 1 : 0 ) ), true );
		} else if ( 'ArrowLeft' === event.key || 'ArrowDown' === event.key ) {
			event.preventDefault();
			setRating( Math.max( 1, current - 1 ), true );
		}
	} );

	/* ---------- Text ---------- */

	function updateCount() {
		var left = maxText - textEl.value.length;
		countEl.textContent = left < 200 ? left + ' characters left' : '';
	}

	textEl.addEventListener( 'input', updateCount );

	/* ---------- Photos ---------- */

	function shrink( file ) {
		return new Promise( function ( resolve ) {
			var url = URL.createObjectURL( file );
			var img = new Image();
			img.onload = function () {
				var scale  = Math.min( 1, MAX_SIDE / Math.max( img.naturalWidth, img.naturalHeight ) );
				var canvas = document.createElement( 'canvas' );
				canvas.width  = Math.round( img.naturalWidth * scale );
				canvas.height = Math.round( img.naturalHeight * scale );
				canvas.getContext( '2d' ).drawImage( img, 0, 0, canvas.width, canvas.height );
				URL.revokeObjectURL( url );
				canvas.toBlob( function ( blob ) {
					resolve( blob ? { blob: blob, name: 'photo.jpg' } : { blob: file, name: file.name } );
				}, 'image/jpeg', 0.85 );
			};
			img.onerror = function () {
				URL.revokeObjectURL( url );
				resolve( { blob: file, name: file.name, undecodable: true } );
			};
			img.src = url;
		} );
	}

	function renderPhotos() {
		photosEl.querySelectorAll( '.yp-leave-review__photo' ).forEach( function ( el ) { el.remove(); } );
		photos.forEach( function ( photo, index ) {
			var el = document.createElement( 'div' );
			el.className = 'yp-leave-review__photo';
			el.innerHTML = ( photo.url
				? '<img src="' + photo.url + '" alt="Your photo ' + ( index + 1 ) + '" />'
				: '<span class="yp-leave-review__photo-name">' + escapeHtml( photo.name ) + '</span>' ) +
				'<button type="button" class="yp-leave-review__photo-remove" aria-label="Remove photo ' + ( index + 1 ) + '">&times;</button>';
			el.querySelector( 'button' ).addEventListener( 'click', function () {
				if ( photo.url ) {
					URL.revokeObjectURL( photo.url );
				}
				photos.splice( index, 1 );
				renderPhotos();
			} );
			photosEl.insertBefore( el, addEl );
		} );
		addEl.hidden = photos.length >= maxPhotos;
	}

	fileEl.addEventListener( 'change', function () {
		var files = Array.prototype.slice.call( fileEl.files || [] ).slice( 0, maxPhotos - photos.length );
		fileEl.value = '';
		addEl.classList.add( 'is-busy' );

		Promise.all( files.map( shrink ) ).then( function ( results ) {
			results.forEach( function ( result ) {
				photos.push( {
					blob: result.blob,
					name: result.name,
					url: result.undecodable ? '' : URL.createObjectURL( result.blob )
				} );
			} );
			addEl.classList.remove( 'is-busy' );
			renderPhotos();
		} );
	} );

	/* ---------- Submit ---------- */

	function showDone( review, justSent ) {
		titleEl.textContent = justSent ? 'Thank you' + ( firstName ? ', ' + firstName : '' ) + '!' : 'You reviewed this order';
		formEl.hidden = true;
		orderEl.hidden = true;

		var photosHtml = ( review.photos || [] ).map( function ( photo ) {
			return '<img src="' + escapeHtml( photo.thumb ) + '" alt="Your photo" />';
		} ).join( '' );

		doneEl.innerHTML =
			'<div class="yp-leave-review__done-card">' +
				starString( review.rating ) +
				( review.text ? '<p class="yp-leave-review__done-text">&ldquo;' + escapeHtml( review.text ) + '&rdquo;</p>' : '' ) +
				( photosHtml ? '<div class="yp-leave-review__done-photos">' + photosHtml + '</div>' : '' ) +
				'<p class="yp-leave-review__done-name">&mdash; ' + escapeHtml( review.name ) + '</p>' +
			'</div>' +
			'<p>' + ( justSent ? 'Your review is in. We really appreciate you taking the time.' : 'Thanks again for your review!' ) + '</p>' +
			'<p><a class="wp-block-button__link is-style-outline" href="' + escapeHtml( yeffoprintLeaveReview.shopUrl ) + '">Back to the shop</a></p>';
		doneEl.hidden = false;
	}

	formEl.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		if ( ! rating ) {
			errorEl.textContent = 'Please pick a star rating first.';
			errorEl.hidden = false;
			starsEl.querySelector( '[data-value="1"]' ).focus();
			return;
		}

		var data = new FormData();
		data.append( 'rating', String( rating ) );
		data.append( 'text', textEl.value );
		data.append( 'name', nameEl.value );
		if ( key ) {
			data.append( 'key', key );
		}
		photos.forEach( function ( photo, index ) {
			var dot = photo.name.lastIndexOf( '.' );
			data.append( 'photos[]', photo.blob, 'photo-' + ( index + 1 ) + ( dot > -1 ? photo.name.slice( dot ) : '.jpg' ) );
		} );

		errorEl.hidden = true;
		submitEl.disabled = true;
		submitEl.textContent = photos.length ? 'Uploading photos…' : 'Sending…';

		fetch( yeffoprintLeaveReview.restUrl + 'reviews/order/' + encodeURIComponent( orderId ), {
			method: 'POST',
			headers: headers(),
			credentials: 'same-origin',
			body: data
		} )
			.then( function ( response ) {
				return response.json().catch( function () { return {}; } ).then( function ( body ) {
					if ( ! response.ok ) {
						throw new Error( body.message || 'Something went wrong. Please try again.' );
					}
					return body;
				} );
			} )
			.then( function ( body ) {
				showDone( body.review, true );
				window.scrollTo( { top: 0, behavior: 'smooth' } );
			} )
			.catch( function ( error ) {
				errorEl.textContent = error.message;
				errorEl.hidden = false;
				submitEl.disabled = false;
				submitEl.textContent = 'Submit review';
			} );
	} );

	/* ---------- Load ---------- */

	function renderOrder( data ) {
		var items = ( data.items || [] ).slice( 0, 4 ).map( function ( item ) {
			return '<li>' +
				( item.image ? '<img src="' + escapeHtml( item.image ) + '" alt="" />' : '<span class="yp-leave-review__item-ph" aria-hidden="true"></span>' ) +
				'<span>' + escapeHtml( item.name ) + '</span>' +
			'</li>';
		} ).join( '' );
		var more = data.items && data.items.length > 4 ? '<li class="yp-leave-review__more">+ ' + ( data.items.length - 4 ) + ' more</li>' : '';

		orderEl.innerHTML =
			'<p class="yp-leave-review__order-number">Order ' + escapeHtml( data.order_number ) + ' <span class="yp-leave-review__delivered">Delivered</span></p>' +
			( items ? '<ul class="yp-leave-review__items">' + items + more + '</ul>' : '' );
	}

	if ( ! orderId ) {
		showStatus( 'This review link is missing its order. Please use the link from your delivery email.', true );
		return;
	}

	fetch( apiUrl(), { headers: headers(), credentials: 'same-origin' } )
		.then( function ( response ) {
			return response.json().catch( function () { return {}; } ).then( function ( body ) {
				if ( ! response.ok ) {
					throw new Error( body.message || 'This review link isn’t working. Please use the link from your delivery email.' );
				}
				return body;
			} );
		} )
		.then( function ( data ) {
			maxPhotos = data.max_photos || maxPhotos;
			maxText   = data.max_text || maxText;
			firstName = data.first_name || '';
			statusEl.hidden = true;
			contentEl.hidden = false;

			if ( data.review ) {
				showDone( data.review, false );
				return;
			}
			if ( ! data.can_review ) {
				showStatus( 'You can review this order once it has been delivered. We’ll email you a link when it arrives.', false );
				return;
			}

			renderOrder( data );
			root.querySelector( '[data-yp-lr-max-photos]' ).textContent = String( maxPhotos );
			textEl.maxLength = maxText;
			nameEl.value = data.suggested_name || '';
			renderStars();
			renderPhotos();
			updateCount();
		} )
		.catch( function ( error ) {
			showStatus( error.message, true );
		} );
} )();
