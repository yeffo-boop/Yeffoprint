/**
 * Settings — every option the classic Settings-API page
 * (`class-admin-menu.php`) registers, reached as one `GET`/`POST
 * /admin/settings` pair instead of an `options.php` form (docs/
 * ARCHITECTURE.md, Phase 7). Same "edit the one active record
 * directly on the page, no list" shape as `views/pricing.js` — there's
 * only ever one settings record.
 *
 * Direct request: this screen "is becoming too cluttered" — its ~10
 * panels are grouped below into four tabs (General/Storefront/
 * Shipping/Integrations, TAB_GROUPS) so only one group is visible at a
 * time. Every panel still renders into the DOM up front and is just
 * hidden/shown via the native `hidden` attribute — save() keeps reading
 * every field by id regardless of which tab is active, so the single
 * GET/POST pair and single Save button below all of them are
 * unchanged; this is presentation-only, not a data model split.
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

	var TAB_GROUPS = [
		{ id: 'general', label: 'General' },
		{ id: 'storefront', label: 'Storefront' },
		{ id: 'shipping', label: 'Shipping' },
		{ id: 'integrations', label: 'Integrations' }
	];

	YP.views.settings = function ( viewEl ) {
		viewEl.innerHTML = '<p class="yp-app__intro">Loading settings&hellip;</p>';

		YP.request( endpoint() )
			.then( function ( settings ) { render( settings ); } )
			.catch( function ( error ) {
				viewEl.innerHTML = '<p class="yp-app__intro">Couldn’t load settings: ' + YP.escapeHtml( error.message ) + '</p>';
			} );

		function render( settings, activeTabId ) {
			activeTabId = activeTabId && TAB_GROUPS.some( function ( t ) { return t.id === activeTabId; } ) ? activeTabId : TAB_GROUPS[ 0 ].id;

			var generalHtml =
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Announcement Bar</h2></div>' +
					'<div class="yp-field"><label for="yp-set-announcement">Announcement text</label><input type="text" id="yp-set-announcement" value="' + YP.escapeAttr( settings.announcement_bar_text ) + '" /></div>' +
					'<p class="yp-panel__hint">Shown in the thin bar above the header, on every page. Leave blank to hide the bar entirely.</p>' +
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
				'</div>';

			var storefrontHtml =
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Homepage Promo</h2></div>' +
					'<p class="yp-panel__hint">Themed banners between the header and the hero. Fill in an Offer and Promo code for any theme below to make it active — two or more active themes rotate automatically. Shown exactly as typed, so make sure a matching active WooCommerce coupon exists for each code before turning this on.</p>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-promo-enabled"' + ( settings.promo_enabled ? ' checked' : '' ) + ' /><label for="yp-set-promo-enabled">Show it on the homepage</label></div>' +
					'<table class="yp-tier-table"><thead><tr><th>Theme</th><th>Offer</th><th>Promo code</th></tr></thead><tbody>' +
						Object.keys( settings.promo_themes ).map( function ( slug ) {
							var banner = settings.promo_banners[ slug ] || {};
							return '<tr>' +
								'<td>' + YP.escapeHtml( settings.promo_themes[ slug ] ) + '</td>' +
								'<td><input type="text" data-yp-promo-offer="' + YP.escapeAttr( slug ) + '" value="' + YP.escapeAttr( banner.offer || '' ) + '" placeholder="15% off" /></td>' +
								'<td><input type="text" data-yp-promo-code="' + YP.escapeAttr( slug ) + '" value="' + YP.escapeAttr( banner.code || '' ) + '" placeholder="SUMMERWEEN26" /></td>' +
							'</tr>';
						} ).join( '' ) +
					'</tbody></table>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Label Configurator</h2></div>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-live-preview"' + ( settings.live_preview_enabled ? ' checked' : '' ) + ' /><label for="yp-set-live-preview">Show customers the live, per-keystroke text preview on Label View</label></div>' +
					'<p class="yp-panel__hint">Turn off while adjusting field alignment on a Template so customers don’t see not-yet-correct positioning. Everything else keeps working either way.</p>' +
					'<div class="yp-field"><label for="yp-set-default-field-preset">Shared customization fields</label><select id="yp-set-default-field-preset">' +
						'<option value="0">— Off: each Template has its own fields —</option>' +
						settings.field_presets.map( function ( preset ) {
							return '<option value="' + preset.id + '"' + ( settings.default_field_preset_id === preset.id ? ' selected' : '' ) + '>' + YP.escapeHtml( preset.title ) + '</option>';
						} ).join( '' ) +
					'</select></div>' +
					'<p class="yp-panel__hint">When set, every Template — current and future — uses this one Field Preset’s fields instead of its own. Add, edit, or remove a field on that preset (Field Presets screen) and it applies everywhere at once, immediately.</p>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Contact Form</h2></div>' +
					'<div class="yp-field"><label for="yp-set-contact-email">Send messages to</label><input type="email" id="yp-set-contact-email" value="' + YP.escapeAttr( settings.contact_recipient_email ) + '" /></div>' +
					'<p class="yp-panel__hint">Every Contact form submission is emailed here. Replying goes straight to the customer — their address is set as Reply-To.</p>' +
				'</div>';

			var shippingHtml =
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
					'<div class="yp-panel__head"><h2>Shippo <span style="font-weight:400;color:var(--yp-muted,#767676);">(Beta)</span></h2></div>' +
					'<p class="yp-panel__hint">An independent rate-shopping/label-purchase panel, shown alongside the existing WooCommerce Shipping form on every order — nothing here replaces that. Get an API token from your <a href="https://apps.goshippo.com/settings/api" target="_blank" rel="noopener">Shippo dashboard</a>. Comparing rates is always free; purchasing a label is a real charge.</p>' +
					'<div class="yp-field"><label for="yp-set-shippo-key">API Token</label><input type="password" autocomplete="off" id="yp-set-shippo-key" value="' + YP.escapeAttr( settings.shippo_api_key ) + '" placeholder="shippo_live_... or shippo_test_..." /></div>' +
					'<div class="yp-field"><label for="yp-set-shippo-phone">Ship-from phone number</label><input type="tel" id="yp-set-shippo-phone" value="' + YP.escapeAttr( settings.shippo_ship_from_phone ) + '" placeholder="(555) 123-4567" /></div>' +
					'<p class="yp-panel__hint">Required by Shippo/USPS on every label as the sender\'s contact info — a real label purchase fails without it.</p>' +
					'<p class="yp-panel__hint">Default package — pre-fills the rate-shop form on every order, editable there when something is heavier or bigger. This is a starting estimate, not a measured value — correct it here once you\'ve weighed a real package.</p>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-set-shippo-weight">Weight (oz)</label><input type="number" min="0.1" step="0.1" id="yp-set-shippo-weight" value="' + YP.escapeAttr( settings.shippo_default_package.weight_oz ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-set-shippo-length">Length (in)</label><input type="number" min="0.1" step="0.1" id="yp-set-shippo-length" value="' + YP.escapeAttr( settings.shippo_default_package.length_in ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-set-shippo-width">Width (in)</label><input type="number" min="0.1" step="0.1" id="yp-set-shippo-width" value="' + YP.escapeAttr( settings.shippo_default_package.width_in ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-set-shippo-height">Height (in)</label><input type="number" min="0.1" step="0.1" id="yp-set-shippo-height" value="' + YP.escapeAttr( settings.shippo_default_package.height_in ) + '" /></div>' +
					'</div>' +
					// Direct request: "Shippo support webhooks for tracking updates
					// whenever a package status changes. Would that be better?" —
					// registered automatically on save when possible; this URL is
					// shown either way so it can be pasted into the Shippo dashboard
					// by hand (Settings → API → Webhooks) if that didn't take.
					'<p class="yp-panel__hint">Tracking webhook endpoint (auto-registers with Shippo when you save an API token above; event type <code>track_updated</code>): <code>' + YP.escapeHtml( settings.shippo_webhook_url ) + '</code></p>' +
					( settings.shippo_webhook_status ? '<p class="yp-panel__hint">' + YP.escapeHtml( settings.shippo_webhook_status ) + '</p>' : '' ) +
				'</div>';

			var integrationsHtml =
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Telegram Bot</h2></div>' +
					'<p class="yp-panel__hint">Answers FAQs and order-status questions for customers on Telegram. Create a bot with <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>, paste its token below, and turn it on — the webhook connects automatically when you save.</p>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-telegram-enabled"' + ( settings.telegram_enabled ? ' checked' : '' ) + ' /><label for="yp-set-telegram-enabled">Bot is active</label></div>' +
					'<div class="yp-field"><label for="yp-set-telegram-token">Bot token</label><input type="password" autocomplete="off" id="yp-set-telegram-token" value="' + YP.escapeAttr( settings.telegram_bot_token ) + '" placeholder="123456789:AA..." /></div>' +
					( settings.telegram_status ? '<p class="yp-panel__hint">' + YP.escapeHtml( settings.telegram_status ) + '</p>' : '' ) +
					'<div class="yp-field"><label for="yp-set-telegram-username">Public @username</label><input type="text" id="yp-set-telegram-username" value="' + YP.escapeAttr( settings.telegram_bot_username ) + '" placeholder="yeffoprint_bot" /></div>' +
					'<p class="yp-panel__hint">The bot\'s public handle from @BotFather (no "@") — powers the "Chat on Telegram" link on the homepage and in order emails. Separate from the token above, which is private and never shown to customers.</p>' +
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
				'</div>';

			var TAB_CONTENT = { general: generalHtml, storefront: storefrontHtml, shipping: shippingHtml, integrations: integrationsHtml };

			viewEl.innerHTML =
				'<p class="yp-app__intro">Site-wide options — changes apply to the storefront immediately.</p>' +
				'<div data-yp-save-status></div>' +

				'<div class="yp-settings-tabs" role="tablist">' +
					TAB_GROUPS.map( function ( tab ) {
						var isActive = tab.id === activeTabId;
						return '<button type="button" class="yp-settings-tabs__tab' + ( isActive ? ' is-active' : '' ) + '" data-yp-settings-tab-button="' + tab.id + '" role="tab" aria-selected="' + ( isActive ? 'true' : 'false' ) + '">' + YP.escapeHtml( tab.label ) + '</button>';
					} ).join( '' ) +
				'</div>' +

				TAB_GROUPS.map( function ( tab ) {
					return '<div data-yp-settings-tab="' + tab.id + '"' + ( tab.id === activeTabId ? '' : ' hidden' ) + '>' + TAB_CONTENT[ tab.id ] + '</div>';
				} ).join( '' ) +

				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-save>Save Settings</button>';

			viewEl.querySelectorAll( '[data-yp-settings-tab-button]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var target = button.getAttribute( 'data-yp-settings-tab-button' );

					viewEl.querySelectorAll( '[data-yp-settings-tab-button]' ).forEach( function ( b ) {
						var isActive = b === button;
						b.classList.toggle( 'is-active', isActive );
						b.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
					} );

					viewEl.querySelectorAll( '[data-yp-settings-tab]' ).forEach( function ( section ) {
						section.hidden = section.getAttribute( 'data-yp-settings-tab' ) !== target;
					} );
				} );
			} );

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

			var promoBanners = {};
			viewEl.querySelectorAll( '[data-yp-promo-offer]' ).forEach( function ( input ) {
				var slug = input.getAttribute( 'data-yp-promo-offer' );
				var codeInput = viewEl.querySelector( '[data-yp-promo-code="' + slug + '"]' );
				promoBanners[ slug ] = { offer: input.value, code: codeInput ? codeInput.value : '' };
			} );

			var body = {
				announcement_bar_text: viewEl.querySelector( '#yp-set-announcement' ).value,
				promo_enabled: viewEl.querySelector( '#yp-set-promo-enabled' ).checked,
				promo_banners: promoBanners,
				live_preview_enabled: viewEl.querySelector( '#yp-set-live-preview' ).checked,
				default_field_preset_id: parseInt( viewEl.querySelector( '#yp-set-default-field-preset' ).value, 10 ) || 0,
				ups_client_id: viewEl.querySelector( '#yp-set-ups-id' ).value,
				ups_client_secret: viewEl.querySelector( '#yp-set-ups-secret' ).value,
				usps_consumer_key: viewEl.querySelector( '#yp-set-usps-key' ).value,
				usps_consumer_secret: viewEl.querySelector( '#yp-set-usps-secret' ).value,
				shippo_api_key: viewEl.querySelector( '#yp-set-shippo-key' ).value,
				shippo_ship_from_phone: viewEl.querySelector( '#yp-set-shippo-phone' ).value,
				shippo_default_weight_oz: parseFloat( viewEl.querySelector( '#yp-set-shippo-weight' ).value ) || 4,
				shippo_default_length_in: parseFloat( viewEl.querySelector( '#yp-set-shippo-length' ).value ) || 8,
				shippo_default_width_in: parseFloat( viewEl.querySelector( '#yp-set-shippo-width' ).value ) || 6,
				shippo_default_height_in: parseFloat( viewEl.querySelector( '#yp-set-shippo-height' ).value ) || 1,
				contact_recipient_email: viewEl.querySelector( '#yp-set-contact-email' ).value,
				splash_enabled: viewEl.querySelector( '#yp-set-splash-enabled' ).checked,
				splash_image_id: parseInt( viewEl.querySelector( '[data-yp-splash-id]' ).value, 10 ) || 0,
				dashboard_due_date_days: parseInt( viewEl.querySelector( '#yp-set-due-date' ).value, 10 ) || 7,
				maintenance_payment_link: viewEl.querySelector( '#yp-set-maint-link' ).value,
				maintenance_webhook_secret: viewEl.querySelector( '#yp-set-maint-secret' ).value,
				telegram_bot_token: viewEl.querySelector( '#yp-set-telegram-token' ).value,
				telegram_bot_username: viewEl.querySelector( '#yp-set-telegram-username' ).value,
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

			var activeTabButton = viewEl.querySelector( '[data-yp-settings-tab-button].is-active' );
			var activeTabId = activeTabButton ? activeTabButton.getAttribute( 'data-yp-settings-tab-button' ) : '';

			YP.request( endpoint(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } )
				.then( function ( settings ) {
					render( settings, activeTabId );
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
