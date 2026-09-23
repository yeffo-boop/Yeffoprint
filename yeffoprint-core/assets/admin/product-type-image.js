/**
 * Media library picker for the Product Type "Image" field — see
 * includes/post-types/class-product-type-image.php for the markup this
 * binds to. Same wp.media usage as vial-mockup-picker.js.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var selectButton = document.getElementById( 'yp-product-type-image-select' );
		var removeButton = document.getElementById( 'yp-product-type-image-remove' );
		var idInput = document.getElementById( 'yp-product-type-image-id' );
		var preview = document.getElementById( 'yp-product-type-image-preview' );

		if ( ! selectButton || typeof wp === 'undefined' || ! wp.media ) {
			return;
		}

		var frame;

		function clear() {
			idInput.value = '';
			preview.innerHTML = '';
			removeButton.style.display = 'none';
		}

		selectButton.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media( {
				title: 'Select product type image',
				multiple: false,
				library: { type: 'image' },
				button: { text: 'Use this image' }
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var src = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
				idInput.value = attachment.id;
				preview.innerHTML = '';
				var img = document.createElement( 'img' );
				img.src = src;
				img.alt = '';
				img.style.maxWidth = '200px';
				img.style.height = 'auto';
				preview.appendChild( img );
				removeButton.style.display = '';
			} );

			frame.open();
		} );

		removeButton.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			clear();
		} );

		// The "Add New Product Type" form submits over admin-ajax and
		// then resets its own inputs, but not this hidden one or the
		// preview, so the next new term would silently inherit the image.
		if ( window.jQuery && document.getElementById( 'addtag' ) ) {
			window.jQuery( document ).on( 'ajaxSuccess', function ( e, xhr, settings ) {
				if ( settings && typeof settings.data === 'string' && settings.data.indexOf( 'action=add-tag' ) !== -1 ) {
					clear();
				}
			} );
		}
	} );
} )();
