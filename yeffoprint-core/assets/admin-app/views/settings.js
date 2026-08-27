/**
 * Settings — every option the classic Settings-API page
 * (`class-admin-menu.php`) registers, reached as one `GET`/`POST
 * /admin/settings` pair instead of an `options.php` form (docs/
 * ARCHITECTURE.md, Phase 7). Same "edit the one active record
 * directly on the page, no list" shape as `views/pricing.js` — there's
 * only ever one settings record.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	function endpoint() {
		return yeffoprintAdminApp.restUrl + 'admin/settings';
	}

	YP.views.settings = function ( viewEl ) {
		viewEl.innerHTML = '<p class="yp-app__intro">Loading settings&hellip;</p>';

		YP.request( endpoint() )
			.then( function ( settings ) { render( settings ); } )
			.catch( function ( error ) {
				viewEl.innerHTML = '<p class="yp-app__intro">Couldn’t load settings: ' + YP.escapeHtml( error.message ) + '</p>';
			} );

		function render( settings ) {
			viewEl.innerHTML =
				'<p class="yp-app__intro">Site-wide options — changes apply to the storefront immediately.</p>' +
				'<div data-yp-save-status></div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Announcement Bar</h2></div>' +
					'<div class="yp-field"><label for="yp-set-announcement">Announcement text</label><input type="text" id="yp-set-announcement" value="' + YP.escapeAttr( settings.announcement_bar_text ) + '" /></div>' +
					'<p class="yp-panel__hint">Shown in the thin bar above the header, on every page. Leave blank to hide the bar entirely.</p>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Homepage Promo</h2></div>' +
					'<p class="yp-panel__hint">A seasonal banner between the header and the hero — pick a theme, fill in the offer and code, and turn it on. Won’t show until both Offer and Promo code are filled in.</p>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-promo-enabled"' + ( settings.promo_enabled ? ' checked' : '' ) + ' /><label for="yp-set-promo-enabled">Show it on the homepage</label></div>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-set-promo-theme">Theme</label><select id="yp-set-promo-theme">' +
							Object.keys( settings.promo_themes ).map( function ( slug ) {
								return '<option value="' + YP.escapeAttr( slug ) + '"' + ( settings.promo_theme === slug ? ' selected' : '' ) + '>' + YP.escapeHtml( settings.promo_themes[ slug ] ) + '</option>';
							} ).join( '' ) +
						'</select></div>' +
						'<div class="yp-field"><label for="yp-set-promo-offer">Offer</label><input type="text" id="yp-set-promo-offer" value="' + YP.escapeAttr( settings.promo_offer ) + '" placeholder="15% off" /></div>' +
					'</div>' +
					'<div class="yp-field"><label for="yp-set-promo-code">Promo code</label><input type="text" id="yp-set-promo-code" value="' + YP.escapeAttr( settings.promo_code ) + '" placeholder="SUMMERWEEN26" /></div>' +
					'<p class="yp-panel__hint">Shown in the banner exactly as typed — make sure a matching active WooCommerce coupon with this exact code exists before turning the banner on.</p>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Label Configurator</h2></div>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-live-preview"' + ( settings.live_preview_enabled ? ' checked' : '' ) + ' /><label for="yp-set-live-preview">Show customers the live, per-keystroke text preview on Label View</label></div>' +
					'<p class="yp-panel__hint">Turn off while adjusting field alignment on a Template so customers don’t see not-yet-correct positioning. Everything else keeps working either way.</p>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Shipment Tracking</h2></div>' +
					'<p class="yp-panel__hint">Powers the live tracking timeline on /track-order/ and order emails. Optional — a direct carrier-site link is shown instead when these are blank.</p>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-set-ups-id">UPS Client ID</label><input type="password" autocomplete="off" id="yp-set-ups-id" value="' + YP.escapeAttr( settings.ups_client_id ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-set-ups-secret">UPS Client Secret</label><input type="password" autocomplete="off" id="yp-set-ups-secret" value="' + YP.escapeAttr( settings.ups_client_secret ) + '" /></div>' +
					'</div>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-set-usps-key">USPS Consumer Key</label><input type="password" autocomplete="off" id="yp-set-usps-key" value="' + YP.escapeAttr( settings.usps_consumer_key ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-set-usps-secret">USPS Consumer Secret</label><input type="password" autocomplete="off" id="yp-set-usps-secret" value="' + YP.escapeAttr( settings.usps_consumer_secret ) + '" /></div>' +
					'</div>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Contact Form</h2></div>' +
					'<div class="yp-field"><label for="yp-set-contact-email">Send messages to</label><input type="email" id="yp-set-contact-email" value="' + YP.escapeAttr( settings.contact_recipient_email ) + '" /></div>' +
					'<p class="yp-panel__hint">Every Contact form submission is emailed here. Replying goes straight to the customer — their address is set as Reply-To.</p>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Splash Screen</h2></div>' +
					'<p class="yp-panel__hint">A dismissible "we’ve upgraded" welcome screen on the homepage — each visitor sees it once per browser session until switched off here.</p>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-splash-enabled"' + ( settings.splash_enabled ? ' checked' : '' ) + ' /><label for="yp-set-splash-enabled">Show it on the homepage</label></div>' +
					'<div class="yp-field"><label>Screenshot</label>' +
						'<div class="yp-media-field">' +
							'<div class="yp-media-field__preview" data-yp-splash-preview>' + ( settings.splash_image_url ? '<img src="' + YP.escapeAttr( settings.splash_image_url ) + '" alt="" />' : '' ) + '</div>' +
							'<div class="yp-media-field__buttons">' +
								'<input type="hidden" data-yp-splash-id value="' + ( settings.splash_image_id || '' ) + '" />' +
								'<button type="button" class="wp-block-button__link is-style-outline" data-yp-splash-select>Select screenshot</button>' +
								'<button type="button" class="yp-row-action" data-yp-splash-remove ' + ( settings.splash_image_id ? '' : 'hidden' ) + '>Remove</button>' +
							'</div>' +
						'</div>' +
					'</div>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Dashboard</h2></div>' +
					'<div class="yp-field"><label for="yp-set-due-date">Order due date (days)</label><input type="number" min="1" step="1" id="yp-set-due-date" value="' + YP.escapeAttr( settings.dashboard_due_date_days ) + '" style="max-width:100px;" /></div>' +
					'<p class="yp-panel__hint">How long after an order/request comes in before the Dashboard flags it as overdue.</p>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Telegram Bot</h2></div>' +
					'<p class="yp-panel__hint">Answers FAQs and order-status questions for customers on Telegram. Create a bot with <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>, paste its token below, and turn it on — the webhook connects automatically when you save.</p>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-telegram-enabled"' + ( settings.telegram_enabled ? ' checked' : '' ) + ' /><label for="yp-set-telegram-enabled">Bot is active</label></div>' +
					'<div class="yp-field"><label for="yp-set-telegram-token">Bot token</label><input type="password" autocomplete="off" id="yp-set-telegram-token" value="' + YP.escapeAttr( settings.telegram_bot_token ) + '" placeholder="123456789:AA..." /></div>' +
					( settings.telegram_status ? '<p class="yp-panel__hint">' + YP.escapeHtml( settings.telegram_status ) + '</p>' : '' ) +
					'<div class="yp-field"><label for="yp-set-telegram-admin-chat-id">Your chat ID (for alerts)</label><input type="text" id="yp-set-telegram-admin-chat-id" value="' + YP.escapeAttr( settings.telegram_admin_chat_id ) + '" placeholder="123456789" /></div>' +
					'<p class="yp-panel__hint">Message <code>/whoami</code> to the bot from your own Telegram to get this number. New paid orders, custom design requests, and Contact form messages get sent here.</p>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-telegram-login-enabled"' + ( settings.telegram_login_enabled ? ' checked' : '' ) + ' /><label for="yp-set-telegram-login-enabled">Log in with Telegram</label></div>' +
					'<p class="yp-panel__hint">Shows a "Log in with Telegram" button on the login/account pages, using the same bot token above — no separate app registration needed. One extra step on Telegram\'s side: message @BotFather with <code>/setdomain</code> and authorize this site\'s domain, or Telegram refuses to render the button.</p>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Social Login</h2></div>' +
					'<p class="yp-panel__hint">Lets customers sign up/log in with Google, Discord, or Apple instead of a password. For each provider, register a developer app with its redirect/return URL set to the exact URL shown below, then paste in the credentials it gives you.</p>' +
					'<div class="yp-social-provider">' +
						'<h3>Google</h3>' +
						'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-google-enabled"' + ( settings.google_login_enabled ? ' checked' : '' ) + ' /><label for="yp-set-google-enabled">Show this button on the login form</label></div>' +
						'<div class="yp-form__row">' +
							'<div class="yp-field"><label for="yp-set-google-id">Client ID</label><input type="password" autocomplete="off" id="yp-set-google-id" value="' + YP.escapeAttr( settings.google_client_id ) + '" /></div>' +
							'<div class="yp-field"><label for="yp-set-google-secret">Client Secret</label><input type="password" autocomplete="off" id="yp-set-google-secret" value="' + YP.escapeAttr( settings.google_client_secret ) + '" /></div>' +
						'</div>' +
						'<p class="yp-panel__hint">Redirect URI: <code>' + YP.escapeHtml( settings.google_redirect_uri ) + '</code> — set this in <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a>.</p>' +
					'</div>' +
					'<div class="yp-social-provider">' +
						'<h3>Discord</h3>' +
						'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-discord-enabled"' + ( settings.discord_login_enabled ? ' checked' : '' ) + ' /><label for="yp-set-discord-enabled">Show this button on the login form</label></div>' +
						'<div class="yp-form__row">' +
							'<div class="yp-field"><label for="yp-set-discord-id">Client ID</label><input type="password" autocomplete="off" id="yp-set-discord-id" value="' + YP.escapeAttr( settings.discord_client_id ) + '" /></div>' +
							'<div class="yp-field"><label for="yp-set-discord-secret">Client Secret</label><input type="password" autocomplete="off" id="yp-set-discord-secret" value="' + YP.escapeAttr( settings.discord_client_secret ) + '" /></div>' +
						'</div>' +
						'<p class="yp-panel__hint">Redirect URI: <code>' + YP.escapeHtml( settings.discord_redirect_uri ) + '</code> — set this in the <a href="https://discord.com/developers/applications" target="_blank" rel="noopener">Discord Developer Portal</a>.</p>' +
					'</div>' +
					'<div class="yp-social-provider">' +
						'<h3>Apple</h3>' +
						'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-apple-enabled"' + ( settings.apple_login_enabled ? ' checked' : '' ) + ' /><label for="yp-set-apple-enabled">Show this button on the login form</label></div>' +
						'<div class="yp-form__row">' +
							'<div class="yp-field"><label for="yp-set-apple-id">Services ID</label><input type="password" autocomplete="off" id="yp-set-apple-id" value="' + YP.escapeAttr( settings.apple_client_id ) + '" placeholder="com.yeffoprint.web" /></div>' +
							'<div class="yp-field"><label for="yp-set-apple-team">Team ID</label><input type="password" autocomplete="off" id="yp-set-apple-team" value="' + YP.escapeAttr( settings.apple_team_id ) + '" /></div>' +
							'<div class="yp-field"><label for="yp-set-apple-key-id">Key ID</label><input type="password" autocomplete="off" id="yp-set-apple-key-id" value="' + YP.escapeAttr( settings.apple_key_id ) + '" /></div>' +
						'</div>' +
						'<div class="yp-field"><label for="yp-set-apple-key">Private Key (.p8 contents)</label><textarea id="yp-set-apple-key" autocomplete="off" spellcheck="false" rows="6" style="font-family:monospace;font-size:12px;" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----">' + YP.escapeHtml( settings.apple_private_key ) + '</textarea></div>' +
						'<p class="yp-panel__hint">Downloadable only once from Apple when you create the key — keep a copy somewhere safe. Return URL: <code>' + YP.escapeHtml( settings.apple_redirect_uri ) + '</code> — set this (and verify this domain) in <a href="https://developer.apple.com/account/resources/identifiers/list/serviceId" target="_blank" rel="noopener">Apple Developer — Identifiers</a>.</p>' +
					'</div>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Maintenance Subscription</h2></div>' +
					'<p class="yp-panel__hint">Sold via a Stripe Payment Link, created directly in your Stripe Dashboard — paste that link and the webhook signing secret here once both are set up.</p>' +
					'<div class="yp-field"><label for="yp-set-maint-link">Payment Link URL</label><input type="url" id="yp-set-maint-link" value="' + YP.escapeAttr( settings.maintenance_payment_link ) + '" placeholder="https://buy.stripe.com/..." /></div>' +
					'<div class="yp-field"><label for="yp-set-maint-secret">Stripe webhook signing secret</label><input type="password" autocomplete="off" id="yp-set-maint-secret" value="' + YP.escapeAttr( settings.maintenance_webhook_secret ) + '" placeholder="whsec_..." /></div>' +
					'<p class="yp-panel__hint">Webhook endpoint: <code>' + YP.escapeHtml( settings.maintenance_webhook_url ) + '</code></p>' +
				'</div>' +

				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-save>Save Settings</button>';

			YP.bindMediaPicker( {
				title: 'Select screenshot',
				selectButton: viewEl.querySelector( '[data-yp-splash-select]' ),
				removeButton: viewEl.querySelector( '[data-yp-splash-remove]' ),
				idInput: viewEl.querySelector( '[data-yp-splash-id]' ),
				preview: viewEl.querySelector( '[data-yp-splash-preview]' )
			} );

			viewEl.querySelector( '[data-yp-save]' ).addEventListener( 'click', save );
		}

		function save() {
			var saveButton = viewEl.querySelector( '[data-yp-save]' );
			var statusEl = viewEl.querySelector( '[data-yp-save-status]' );

			var body = {
				announcement_bar_text: viewEl.querySelector( '#yp-set-announcement' ).value,
				promo_enabled: viewEl.querySelector( '#yp-set-promo-enabled' ).checked,
				promo_theme: viewEl.querySelector( '#yp-set-promo-theme' ).value,
				promo_offer: viewEl.querySelector( '#yp-set-promo-offer' ).value,
				promo_code: viewEl.querySelector( '#yp-set-promo-code' ).value,
				live_preview_enabled: viewEl.querySelector( '#yp-set-live-preview' ).checked,
				ups_client_id: viewEl.querySelector( '#yp-set-ups-id' ).value,
				ups_client_secret: viewEl.querySelector( '#yp-set-ups-secret' ).value,
				usps_consumer_key: viewEl.querySelector( '#yp-set-usps-key' ).value,
				usps_consumer_secret: viewEl.querySelector( '#yp-set-usps-secret' ).value,
				contact_recipient_email: viewEl.querySelector( '#yp-set-contact-email' ).value,
				splash_enabled: viewEl.querySelector( '#yp-set-splash-enabled' ).checked,
				splash_image_id: parseInt( viewEl.querySelector( '[data-yp-splash-id]' ).value, 10 ) || 0,
				dashboard_due_date_days: parseInt( viewEl.querySelector( '#yp-set-due-date' ).value, 10 ) || 7,
				maintenance_payment_link: viewEl.querySelector( '#yp-set-maint-link' ).value,
				maintenance_webhook_secret: viewEl.querySelector( '#yp-set-maint-secret' ).value,
				telegram_bot_token: viewEl.querySelector( '#yp-set-telegram-token' ).value,
				telegram_enabled: viewEl.querySelector( '#yp-set-telegram-enabled' ).checked,
				telegram_admin_chat_id: viewEl.querySelector( '#yp-set-telegram-admin-chat-id' ).value,
				telegram_login_enabled: viewEl.querySelector( '#yp-set-telegram-login-enabled' ).checked,
				google_login_enabled: viewEl.querySelector( '#yp-set-google-enabled' ).checked,
				google_client_id: viewEl.querySelector( '#yp-set-google-id' ).value,
				google_client_secret: viewEl.querySelector( '#yp-set-google-secret' ).value,
				discord_login_enabled: viewEl.querySelector( '#yp-set-discord-enabled' ).checked,
				discord_client_id: viewEl.querySelector( '#yp-set-discord-id' ).value,
				discord_client_secret: viewEl.querySelector( '#yp-set-discord-secret' ).value,
				apple_login_enabled: viewEl.querySelector( '#yp-set-apple-enabled' ).checked,
				apple_client_id: viewEl.querySelector( '#yp-set-apple-id' ).value,
				apple_team_id: viewEl.querySelector( '#yp-set-apple-team' ).value,
				apple_key_id: viewEl.querySelector( '#yp-set-apple-key-id' ).value,
				apple_private_key: viewEl.querySelector( '#yp-set-apple-key' ).value
			};

			saveButton.disabled = true;
			saveButton.textContent = 'Saving…';
			statusEl.innerHTML = '';

			YP.request( endpoint(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } )
				.then( function ( settings ) {
					render( settings );
					viewEl.querySelector( '[data-yp-save-status]' ).innerHTML = '<p class="yp-panel__hint">Saved — live on the storefront now.</p>';
				} )
				.catch( function ( error ) {
					saveButton.disabled = false;
					saveButton.textContent = 'Save Settings';
					statusEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}
	};
} )();
