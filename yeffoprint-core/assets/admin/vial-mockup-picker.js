/**
 * Media library picker for the Template "Vial mockup image" field
 * (Gallery & Compatibility meta box). Standard wp.media usage — see
 * includes/admin/class-template-editor.php for the markup this binds to.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var selectButton = document.getElementById( 'yp-vial-mockup-select' );
		var removeButton = document.getElementById( 'yp-vial-mockup-remove' );
		var idInput = document.getElementById( 'yp-vial-mockup-id' );
		var preview = document.getElementById( 'yp-vial-mockup-preview' );

		if ( ! selectButton || typeof wp === 'undefined' || ! wp.media ) {
			return;
		}

		var frame;

		selectButton.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media( {
				title: 'Select vial mockup image',
				multiple: false,
				library: { type: 'image' },
				button: { text: 'Use this image' }
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				idInput.value = attachment.id;
				preview.innerHTML = '<img src="' + attachment.url + '" alt="" style="max-width:100%;height:auto;" />';
				removeButton.style.display = '';
			} );

			frame.open();
		} );

		if ( removeButton ) {
			removeButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				idInput.value = '';
				preview.innerHTML = '';
				removeButton.style.display = 'none';
			} );
		}
	} );
} )();
