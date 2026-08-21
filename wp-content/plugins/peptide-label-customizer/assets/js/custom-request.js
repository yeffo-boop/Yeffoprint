jQuery( function ( $ ) {
	var $form = $( 'form.cart' );
	if ( ! $form.length || ! $( '#plc-custom-request' ).length ) {
		return;
	}

	// File uploads require multipart encoding — WooCommerce's default cart
	// form doesn't set this, so we add it for custom-request products.
	$form.attr( 'enctype', 'multipart/form-data' );

	$form.on( 'submit', function ( e ) {
		var $error = $( '#plc-cr-error-message' );
		$error.hide().text( '' );

		if ( ! $.trim( $( '#plc_cr_description' ).val() ) ) {
			e.preventDefault();
			$error.text( 'Please describe what you\'d like designed.' ).show();
			$( 'html, body' ).animate( { scrollTop: $( '#plc-custom-request' ).offset().top - 100 }, 300 );
			return false;
		}
	} );
} );
