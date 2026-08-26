/**
 * Fallback for the "Continue with Google/Discord" login buttons
 * (class-social-login.php) — only enqueued (with yeffoprintSocialLogin.html
 * localized) when at least one provider is configured and turned on.
 *
 * `render_login_buttons()` normally injects the exact same markup
 * server-side via `woocommerce_login_form_end`, which works on a stock
 * WooCommerce login template. This script exists for the installs where
 * that hook doesn't fire — a customized `myaccount/form-login.php`
 * predating this feature — by finding the login form via WooCommerce's
 * own standard `.woocommerce-form-login` class (present regardless of
 * which template rendered it) and appending the same markup directly.
 * The guard below makes this a no-op wherever the server-side hook did
 * fire, so there's never a duplicate.
 */

( function () {
	'use strict';

	if ( typeof yeffoprintSocialLogin === 'undefined' || ! yeffoprintSocialLogin.html ) {
		return;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.woocommerce-form-login' ).forEach( function ( form ) {
			if ( form.querySelector( '.yp-social-login' ) ) {
				return;
			}
			form.insertAdjacentHTML( 'beforeend', yeffoprintSocialLogin.html );
		} );
	} );
} )();
