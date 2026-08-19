/* global jQuery, wcShippingConnectBanner */
( function ( $ ) {
	var $notice = $( '.wcshipping-nux__notice' );

	$notice.on( 'click', '.notice-dismiss', function () {
		$.ajax( {
			url: wcShippingConnectBanner.ajaxurl,
			type: 'POST',
			data: {
				action: 'wcshipping_dismiss_notice',
				dismissible_id: $notice.data( 'dismissible-id' ),
				nonce: wcShippingConnectBanner.nonce
			}
		} );
	} );
} )( jQuery );
