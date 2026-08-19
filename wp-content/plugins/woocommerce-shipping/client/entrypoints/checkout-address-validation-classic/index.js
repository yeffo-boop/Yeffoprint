// Internal dependencies.
import { initClassicNoticePlacement } from 'components/checkout/address-validation/classic-notice-placement';

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initClassicNoticePlacement, {
		once: true,
	} );
} else {
	initClassicNoticePlacement();
}
