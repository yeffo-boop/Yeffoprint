/**
 * Fallback for the "Log in with Telegram" widget (class-telegram-login.php)
 * — same resilience mechanism, and the same reason it exists, as
 * social-login-inject.js (class-social-login.php's own Google/Discord/Apple
 * fallback): on an install whose login template doesn't fire
 * `woocommerce_login_form_end`, this finds the login form via
 * WooCommerce's own standard `.woocommerce-form-login` class and appends
 * the same markup directly. The guard below makes this a no-op wherever
 * the server-side hook did fire.
 *
 * One thing this fallback needs that social-login-inject.js's doesn't:
 * the injected markup includes Telegram's own `<script src="https://
 * telegram.org/js/telegram-widget.js...">` tag (that script is the
 * widget — there's no plain link/button to inject the way there is for
 * Google/Discord/Apple). A `<script>` inserted via insertAdjacentHTML/
 * innerHTML never actually executes — a deliberate browser behavior,
 * not a bug — so the widget's iframe would silently never load if this
 * just appended the raw HTML the way the other fallback does. The block
 * below recreates that script tag via document.createElement() (copying
 * its attributes across) and swaps it in, which does execute.
 */

( function () {
	'use strict';

	if ( typeof yeffoprintTelegramLogin === 'undefined' || ! yeffoprintTelegramLogin.html ) {
		return;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.woocommerce-form-login' ).forEach( function ( form ) {
			if ( form.querySelector( '.yp-telegram-login' ) ) {
				return;
			}

			form.insertAdjacentHTML( 'beforeend', yeffoprintTelegramLogin.html );

			var inserted = form.querySelector( '.yp-telegram-login script' );
			if ( ! inserted ) {
				return;
			}

			var script = document.createElement( 'script' );
			Array.prototype.forEach.call( inserted.attributes, function ( attr ) {
				script.setAttribute( attr.name, attr.value );
			} );
			inserted.parentNode.replaceChild( script, inserted );
		} );
	} );
} )();
