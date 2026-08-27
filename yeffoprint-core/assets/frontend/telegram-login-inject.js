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
 * the injected markup includes two `<script>` tags of its own (the bare
 * Telegram widget loader, and this feature's own Telegram.Login.auth()
 * click handler — see widget_html()'s docblock for why it's a custom
 * button and JS call rather than a plain link/button the way Google/
 * Discord/Apple are). A `<script>` inserted via insertAdjacentHTML/
 * innerHTML never actually executes — a deliberate browser behavior, not
 * a bug — so neither the widget loader nor the click handler would ever
 * run if this just appended the raw HTML the way the other fallback
 * does. Building the fragment in a detached wrapper first, recreating
 * every `<script>` found inside it via document.createElement() (copying
 * both its attributes and its inline text across), and only then moving
 * the wrapper's children into the real form sidesteps that — both
 * scripts do execute this way, regardless of which one is external
 * (`src`) or inline.
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

			var wrapper = document.createElement( 'div' );
			wrapper.innerHTML = yeffoprintTelegramLogin.html;

			wrapper.querySelectorAll( 'script' ).forEach( function ( inserted ) {
				var script = document.createElement( 'script' );
				Array.prototype.forEach.call( inserted.attributes, function ( attr ) {
					script.setAttribute( attr.name, attr.value );
				} );
				script.textContent = inserted.textContent;
				inserted.parentNode.replaceChild( script, inserted );
			} );

			while ( wrapper.firstChild ) {
				form.appendChild( wrapper.firstChild );
			}
		} );
	} );
} )();
