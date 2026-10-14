/**
 * YeffoHealth landing page (/health/): shows the "Get it on Google Play"
 * banner to Android browsers, never inside the YeffoHealth app itself
 * (its WebView adds "YeffoHealth/" to the user agent) and never again
 * once dismissed. The tracker has its own copy (tracker.js playBanner()).
 */
( function () {
	var banner = document.querySelector( '.yp-health__play-banner' );
	var ua = navigator.userAgent;
	if ( ! banner || ! /android/i.test( ua ) || /YeffoHealth\//.test( ua ) ) {
		return;
	}
	try {
		if ( window.localStorage.getItem( 'yp-health-play-dismissed' ) ) {
			return;
		}
	} catch ( e ) {}
	banner.hidden = false;
	banner.querySelector( '.yp-health__play-banner-close' ).addEventListener( 'click', function () {
		banner.hidden = true;
		try {
			window.localStorage.setItem( 'yp-health-play-dismissed', String( Date.now() ) );
		} catch ( e ) {}
	} );
}() );
