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

	// Same "read straight from the live DOM at save time, no parallel JS
	// array to keep in sync" repeater shape as views/pricing.js's own
	// bulk-discount tier table.
	function manualOrderShippingOptionRowHtml( option ) {
		option = option || { label: '', amount: '', region: 'domestic' };
		var regions = [ [ 'domestic', 'US only' ], [ 'international', 'International only' ], [ 'any', 'Everywhere' ] ];
		return (
			'<tr>' +
				'<td data-label="Label" class="yp-stack-grow"><input type="text" data-shipping-option-label aria-label="Label" placeholder="e.g. USPS Ground Advantage" value="' + YP.escapeAttr( option.label ) + '" /></td>' +
				'<td data-label="Price ($)" class="yp-stack-half"><input type="number" min="0" step="0.01" inputmode="decimal" data-shipping-option-amount aria-label="Price" value="' + YP.escapeAttr( option.amount ) + '" /></td>' +
				'<td data-label="Ships to" class="yp-stack-half yp-stack-wide-select"><select data-shipping-option-region aria-label="Ships to">' +
					regions.map( function ( region ) {
						return '<option value="' + region[ 0 ] + '"' + ( region[ 0 ] === option.region ? ' selected' : '' ) + '>' + region[ 1 ] + '</option>';
					} ).join( '' ) +
				'</select></td>' +
				'<td class="yp-stack-remove"><button type="button" class="yp-row-action" data-yp-remove-row aria-label="Remove shipping option">&times;</button></td>' +
			'</tr>'
		);
	}

	function wireRemoveShippingOptionRows( tbody ) {
		tbody.querySelectorAll( '[data-yp-remove-row]' ).forEach( function ( button ) {
			if ( button._wired ) {
				return;
			}
			button._wired = true;
			button.addEventListener( 'click', function () {
				button.closest( 'tr' ).remove();
			} );
		} );
	}

	function readShippingOptionRows( tbody ) {
		return Array.prototype.map.call( tbody.querySelectorAll( 'tr' ), function ( row ) {
			return {
				label: row.querySelector( '[data-shipping-option-label]' ).value,
				amount: parseFloat( row.querySelector( '[data-shipping-option-amount]' ).value ) || 0,
				region: row.querySelector( '[data-shipping-option-region]' ).value
			};
		} ).filter( function ( option ) { return '' !== option.label.trim(); } );
	}

	// `subId` opens a tab directly (`#/settings/shipping`), used by the new app's Settings hub.
	YP.views.settings = function ( viewEl, subId ) {
		viewEl.innerHTML = '<p class="yp-app__intro">Loading settings&hellip;</p>';

		YP.request( endpoint() )
			.then( function ( settings ) { render( settings, subId ); } )
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
					'<div class="yp-panel__head"><h2>Search & AI Summary</h2></div>' +
					'<div class="yp-field"><label for="yp-set-meta-description">Site description</label><textarea id="yp-set-meta-description" rows="3">' + YP.escapeHtml( settings.site_meta_description ) + '</textarea></div>' +
					'<p class="yp-panel__hint">Shown to Google and AI assistants (ChatGPT, Perplexity, etc.) as the summary of your site — on the homepage, and on any page without its own description. A design’s own page always describes that design instead. Aim for one or two plain sentences.</p>' +
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
					'<p class="yp-panel__hint">Themed banners between the header and the hero. Fill in an Offer and Promo code for any theme below to make it active — two or more active themes rotate automatically. 3D Prints Are Back needs no code: put the starting price (e.g. $14) in its Offer box. Shown exactly as typed, so make sure a matching active WooCommerce coupon exists for each code before turning this on.</p>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-promo-enabled"' + ( settings.promo_enabled ? ' checked' : '' ) + ' /><label for="yp-set-promo-enabled">Show it on the homepage</label></div>' +
					'<table class="yp-tier-table yp-stack-rows"><thead><tr><th>Theme</th><th>Offer</th><th>Promo code</th></tr></thead><tbody>' +
						Object.keys( settings.promo_themes ).map( function ( slug ) {
							var banner = settings.promo_banners[ slug ] || {};
							var codeOptional = ( settings.promo_code_optional || [] ).indexOf( slug ) !== -1;
							return '<tr>' +
								'<td><strong>' + YP.escapeHtml( settings.promo_themes[ slug ] ) + '</strong></td>' +
								'<td data-label="Offer" class="yp-stack-half"><input type="text" aria-label="Offer" data-yp-promo-offer="' + YP.escapeAttr( slug ) + '" value="' + YP.escapeAttr( banner.offer || '' ) + '" placeholder="' + ( codeOptional ? '$14' : '15% off' ) + '" /></td>' +
								'<td data-label="Promo code" class="yp-stack-half"><input type="text" aria-label="Promo code" data-yp-promo-code="' + YP.escapeAttr( slug ) + '" value="' + YP.escapeAttr( banner.code || '' ) + '" placeholder="' + ( codeOptional ? 'Not needed' : 'SUMMERWEEN26' ) + '" /></td>' +
							'</tr>';
						} ).join( '' ) +
					'</tbody></table>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Away Mode</h2></div>' +
					'<p class="yp-panel__hint">For the rare stretch when production is paused (vacation, out of town). Turning this on shows a delay notice on the storefront, at checkout, and in the order confirmation email — off by default, since this only fires occasionally.</p>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-away-enabled"' + ( settings.away_mode_enabled ? ' checked' : '' ) + ' /><label for="yp-set-away-enabled">Show a delay notice everywhere</label></div>' +
					'<div class="yp-field"><label for="yp-set-away-return">Resuming on</label><input type="date" id="yp-set-away-return" value="' + YP.escapeAttr( settings.away_mode_return_date ) + '" /></div>' +
					'<p class="yp-panel__hint">Shown to customers exactly as this date — the notice automatically stops showing itself the day after.</p>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Express Orders</h2></div>' +
					'<p class="yp-panel__hint">Customers can tick "Express: skip the line" at checkout for a flat fee per order. Once an express order is paid, you get a phone alert right away and every 30 minutes until you tap Acknowledge on the order (or "Got it" on the alert), or the order moves to In Production or Shipped. Express is paused automatically while Away Mode is on.</p>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-express-enabled"' + ( settings.express_enabled ? ' checked' : '' ) + ' /><label for="yp-set-express-enabled">Offer Express at checkout</label></div>' +
					'<div class="yp-field"><label for="yp-set-express-fee">Express fee ($ per order)</label><input type="number" min="0" step="0.01" id="yp-set-express-fee" value="' + YP.escapeAttr( String( settings.express_fee ) ) + '" /></div>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Label Configurator</h2></div>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-live-preview"' + ( settings.live_preview_enabled ? ' checked' : '' ) + ' /><label for="yp-set-live-preview">Show customers the live, per-keystroke text preview on Label View</label></div>' +
					'<p class="yp-panel__hint">Turn off while adjusting field alignment on a Template so customers don’t see not-yet-correct positioning. Everything else keeps working either way.</p>' +
					'<p class="yp-panel__hint">Customization fields (Compound Name, Strength, Color and the rest) are shared by every Template. Add, edit, or remove them on the <a href="#/label-fields">Label Fields</a> screen.</p>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Contact Form</h2></div>' +
					'<div class="yp-field"><label for="yp-set-contact-email">Send messages to</label><input type="email" id="yp-set-contact-email" value="' + YP.escapeAttr( settings.contact_recipient_email ) + '" /></div>' +
					'<p class="yp-panel__hint">Every Contact form submission is emailed here. Replying goes straight to the customer — their address is set as Reply-To.</p>' +
				'</div>';

			var liveRates = settings.live_rates || { enabled: false, markup: 1, keep_free: true, services: [], all: [], configured: false };
			var production = settings.production_time || { enabled: false, per_day: 4, min_days: 1, busy_days: 4, closed_dates: [], now: null };
			var shippingHtml =
				// Direct request: a live production time estimate from open
				// orders, without showing customers how many are ahead of them.
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Production time</h2></div>' +
					'<p class="yp-panel__hint">Checkout shows "Ships in about N business days" with the ship date, and arrival dates under each shipping option, worked out from your open orders. Customers never see how many orders there are. Hidden while Away mode is on.</p>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-prod-enabled"' + ( production.enabled ? ' checked' : '' ) + ' /><label for="yp-set-prod-enabled">Show live production time at checkout</label></div>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-set-prod-per-day">Orders you finish per business day</label><input type="number" min="1" step="1" id="yp-set-prod-per-day" value="' + YP.escapeAttr( production.per_day ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-set-prod-min">Shortest time to show (business days)</label><input type="number" min="1" step="1" id="yp-set-prod-min" value="' + YP.escapeAttr( production.min_days ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-set-prod-busy">Show the busy color from (business days)</label><input type="number" min="1" step="1" id="yp-set-prod-busy" value="' + YP.escapeAttr( production.busy_days ) + '" /></div>' +
					'</div>' +
					'<div class="yp-field"><label for="yp-set-prod-closed">Closed days besides weekends (one date per line, e.g. 2026-11-26)</label><textarea id="yp-set-prod-closed" rows="3" placeholder="2026-11-26">' + YP.escapeHtml( ( production.closed_dates || [] ).join( '\n' ) ) + '</textarea></div>' +
					( production.now ? '<p class="yp-panel__hint"><strong>Right now:</strong> ' + production.now.open + ' open orders (' + production.now.processing + ' Processing, ' + production.now.production + ' In Production, ' + production.now.curing + ' Curing) → about ' + production.now.days + ' business day' + ( production.now.days === 1 ? '' : 's' ) + '. A new order ships ' + YP.escapeHtml( production.now.ship_date ) + ', Express ' + YP.escapeHtml( production.now.express_date ) + '. Holographic adds 1 day.</p>' : '' ) +
				'</div>' +


				// Direct request: live rates at checkout plus $1 for packing
				// materials, with "a toggle in my dashboard to turn live rates on/off".
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Live shipping rates</h2></div>' +
					'<p class="yp-panel__hint">Customers see real Shippo prices for their address at checkout, plus the add-on below for packing materials. Turn this off to go back to your flat rates in Shipping Zones right away. If Shippo doesn’t answer within a few seconds, checkout shows the flat rates instead.</p>' +
					( liveRates.configured ? '' : '<p class="yp-form__error">Add a Shippo API token in the Shippo section below first. Until then checkout keeps the flat rates.</p>' ) +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-live-enabled"' + ( liveRates.enabled ? ' checked' : '' ) + ' /><label for="yp-set-live-enabled">Use live shipping rates at checkout</label></div>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-set-live-markup">Add to every rate ($)</label><input type="number" min="0" step="0.01" inputmode="decimal" id="yp-set-live-markup" value="' + YP.escapeAttr( liveRates.markup ) + '" /></div>' +
					'</div>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-live-keep-free"' + ( liveRates.keep_free ? ' checked' : '' ) + ' /><label for="yp-set-live-keep-free">Keep free shipping on orders over the free shipping minimum</label></div>' +
					'<div class="yp-field"><label>Services customers can pick</label>' +
						( liveRates.all || [] ).map( function ( service ) {
							var id = 'yp-set-live-service-' + service.key;
							return '<div class="yp-field--checkbox yp-field"><input type="checkbox" id="' + id + '" data-yp-live-service="' + YP.escapeAttr( service.key ) + '"' + ( liveRates.services.indexOf( service.key ) !== -1 ? ' checked' : '' ) + ' /><label for="' + id + '">' + YP.escapeHtml( service.label ) + '</label></div>';
						} ).join( '' ) +
					'</div>' +
					'<p class="yp-panel__hint">Prices are quoted for the Shippo default package below, so set it to your usual mailer and weight. A 3D print with its own shipping price still charges that price when it’s higher.</p>' +
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
					'<p class="yp-panel__hint">Customs for international labels — filled in on every international order\'s Shippo panel, where the description and value can still be changed. The value defaults to what the customer paid for the items.</p>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-set-customs-description">Contents description</label><input type="text" id="yp-set-customs-description" value="' + YP.escapeAttr( settings.shippo_customs.description ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-set-customs-tariff">HS tariff code</label><input type="text" id="yp-set-customs-tariff" value="' + YP.escapeAttr( settings.shippo_customs.tariff_number ) + '" placeholder="4821.10" /></div>' +
						'<div class="yp-field"><label for="yp-set-customs-signer">Signed by</label><input type="text" id="yp-set-customs-signer" value="' + YP.escapeAttr( settings.shippo_customs.signer_saved ) + '" placeholder="' + YP.escapeAttr( settings.shippo_customs.signer ) + '" /></div>' +
					'</div>' +
					'<p class="yp-panel__hint">Tracking webhook endpoint (auto-registers with Shippo when you save an API token above; event type <code>track_updated</code>): <code>' + YP.escapeHtml( settings.shippo_webhook_url ) + '</code></p>' +
					( settings.shippo_webhook_status ? '<p class="yp-panel__hint">' + YP.escapeHtml( settings.shippo_webhook_status ) + '</p>' : '' ) +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Manual order shipping options</h2></div>' +
					'<p class="yp-panel__hint">Direct request: "I don’t need to rate shop to add shipping, just use my default shipping options." Staff pick one of these flat rates on the Manual Order screen instead of live rate-shopping — works whether or not Shippo above is configured. Customers also pick from these on their payment link when the order’s shipping is left on “Customer picks”. “Ships to” limits each one to US addresses or international ones.</p>' +
					'<table class="yp-tier-table yp-stack-rows"><thead><tr><th>Label</th><th>Price</th><th>Ships to</th><th></th></tr></thead>' +
						'<tbody data-yp-shipping-option-rows>' +
							( settings.manual_order_shipping_options || [] ).map( manualOrderShippingOptionRowHtml ).join( '' ) +
						'</tbody>' +
					'</table>' +
					'<button type="button" class="wp-block-button__link is-style-outline" data-yp-add-shipping-option>+ Add shipping option</button>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Local Pickup</h2></div>' +
					'<p class="yp-panel__hint">Direct request: "I want to add a local pickup option, but only for certain items." A free pickup option shows at checkout only when every item in the cart is ticked below. One unticked item and the customer has to pick a shipping option instead.</p>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-pickup-enabled"' + ( settings.local_pickup_enabled ? ' checked' : '' ) + ' /><label for="yp-set-pickup-enabled">Offer local pickup at checkout</label></div>' +
					'<div class="yp-field"><label for="yp-set-pickup-label">Name shown at checkout</label><input type="text" id="yp-set-pickup-label" value="' + YP.escapeAttr( settings.local_pickup_label ) + '" placeholder="Local pickup" /></div>' +
					'<div class="yp-field"><label for="yp-set-pickup-instructions">Pickup instructions</label><textarea id="yp-set-pickup-instructions" rows="3" placeholder="Where and when to pick up, e.g. address and hours">' + YP.escapeHtml( settings.local_pickup_instructions ) + '</textarea></div>' +
					'<p class="yp-panel__hint">Shown on the order confirmation page and in the order emails of pickup orders.</p>' +
					'<div class="yp-field"><label>Items that can be picked up</label>' +
						'<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:4px 16px;">' +
							( settings.local_pickup_products || [] ).map( function ( product, i, all ) {
								// Several Templates share a name ("Spectrum"), so show the URL slug to tell them apart.
								var duplicate = all.some( function ( other ) { return other !== product && other.name === product.name; } );
								var id = 'yp-set-pickup-product-' + product.id;
								return '<div class="yp-field--checkbox yp-field"><input type="checkbox" id="' + id + '" data-yp-pickup-product="' + product.id + '"' + ( product.eligible ? ' checked' : '' ) + ' /><label for="' + id + '">' + YP.escapeHtml( product.name ) + ( duplicate ? ' <span style="color:var(--yp-muted,#767676);">(' + YP.escapeHtml( product.slug ) + ')</span>' : '' ) + '</label></div>';
							} ).join( '' ) +
						'</div>' +
					'</div>' +
				'</div>';

			var integrationsHtml =
				// Direct request: forward a customer's order email and have the site fill in the order.
				'<div class="yp-panel" id="email-orders">' +
					'<div class="yp-panel__head"><h2>Email orders</h2></div>' +
					'<p class="yp-panel__hint">Every 5 minutes the site checks a mailbox folder for order emails, Claude reads the list, and a filled-in order waits for you under Orders › Email Orders with a phone alert. Nothing is sent to the customer until you check it and press Create Order.</p>' +
					'<p class="yp-panel__hint"><strong>No extra mailbox needed:</strong> use your own Gmail login below and set the folder to a label such as <code>Email Orders</code>. Then tag a customer\'s email with that label, or forward it to your own address with <code>+orders</code> added (yeffo+orders@…) and have a Gmail filter put mail sent to that address under the label. Only emails in that label are read.</p>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-eo-enabled"' + ( settings.email_orders_enabled ? ' checked' : '' ) + ' /><label for="yp-set-eo-enabled">Check the mailbox for forwarded orders</label></div>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-set-eo-host">IMAP server</label><input type="text" id="yp-set-eo-host" value="' + YP.escapeAttr( settings.email_orders_host || '' ) + '" placeholder="imap.gmail.com" autocomplete="off" /></div>' +
						'<div class="yp-field"><label for="yp-set-eo-port">Port</label><input type="number" id="yp-set-eo-port" value="' + YP.escapeAttr( settings.email_orders_port || 993 ) + '" min="1" max="65535" /></div>' +
					'</div>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-set-eo-user">Mailbox username</label><input type="text" id="yp-set-eo-user" value="' + YP.escapeAttr( settings.email_orders_username || '' ) + '" placeholder="you@yeffoprint.com" autocomplete="off" /></div>' +
						'<div class="yp-field"><label for="yp-set-eo-password">Mailbox password</label><input type="password" id="yp-set-eo-password" value="" autocomplete="new-password" placeholder="' + ( settings.email_orders_password_set ? 'Saved. Type a new one to replace it' : 'Password or app password' ) + '" /></div>' +
					'</div>' +
					'<div class="yp-field"><label for="yp-set-eo-folder">Folder or Gmail label to read</label><input type="text" id="yp-set-eo-folder" value="' + YP.escapeAttr( settings.email_orders_folder || 'INBOX' ) + '" placeholder="Email Orders" autocomplete="off" /></div>' +
					'<p class="yp-panel__hint">Gmail: server imap.gmail.com, port 993, and an app password (Google Account › Security › App passwords), not your normal password. Type the label exactly as it shows in Gmail, and start with a new, empty one: everything put in it from then on is read (it doesn’t have to be unread). Use INBOX only for a mailbox that is just for orders.</p>' +
					'<div class="yp-field"><label for="yp-set-eo-senders">When reading INBOX, only accept emails forwarded from</label><input type="text" id="yp-set-eo-senders" value="' + YP.escapeAttr( settings.email_orders_senders || '' ) + '" placeholder="' + YP.escapeAttr( settings.email_orders_default_senders || '' ) + '" /></div>' +
					'<p class="yp-panel__hint">Your own addresses, separated by commas. Blank uses the ones shown. Anything else sent to the mailbox is ignored.</p>' +
					( settings.claude_api_key_in_config
						? '<p class="yp-panel__hint">A Claude API key is set in wp-config.php (YEFFOPRINT_CLAUDE_API_KEY), so that one is used.</p>'
						: '<div class="yp-field"><label for="yp-set-claude-key">Claude API key</label><input type="password" id="yp-set-claude-key" value="" autocomplete="new-password" placeholder="' + ( settings.claude_api_key_set ? 'Saved. Paste a new one to replace it' : 'sk-ant-...' ) + '" /></div>' +
							'<p class="yp-panel__hint">Create one at <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a> and add a few dollars of credit. Each email costs a few cents to read.</p>' ) +
					'<div class="yp-form__actions"><button type="button" class="wp-block-button__link is-style-outline" data-yp-eo-check>Check now</button></div>' +
					'<p class="yp-panel__hint" data-yp-eo-status>' + ( settings.email_orders_status ? 'Last check' + ( settings.email_orders_checked_at ? ' (' + YP.escapeHtml( new Date( settings.email_orders_checked_at ).toLocaleString() ) + ')' : '' ) + ': ' + YP.escapeHtml( settings.email_orders_status ) : 'Not checked yet. Save first, then press Check now.' ) + '</p>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>YeffoHealth food search</h2></div>' +
					'<p class="yp-panel__hint">Lets tracker users search foods by name (USDA FoodData Central) and scan barcodes (Open Food Facts) to add calories and macros. Get a free key at <a href="https://api.data.gov/signup/" target="_blank" rel="noopener">api.data.gov/signup</a>; it allows 1,000 searches an hour, and common searches are saved so they don\'t count again. Without a key, search uses USDA\'s shared demo key, which runs out after a few searches. Barcode scanning works either way.</p>' +
					( settings.usda_api_key_in_config ? '<p class="yp-panel__hint">A key is set in wp-config.php (YEFFOPRINT_USDA_API_KEY), so that one is used.</p>' : '' ) +
					'<div class="yp-field"><label for="yp-set-usda-key">USDA API key</label><input type="password" autocomplete="off" id="yp-set-usda-key" value="' + YP.escapeAttr( settings.usda_api_key || '' ) + '" placeholder="40-character key from the signup email" /></div>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Telegram Bot</h2></div>' +
					'<p class="yp-panel__hint">Answers FAQs and order-status questions for customers on Telegram. Create a bot with <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>, paste its token below, and turn it on — the webhook connects automatically when you save.</p>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-telegram-enabled"' + ( settings.telegram_enabled ? ' checked' : '' ) + ' /><label for="yp-set-telegram-enabled">Bot is active</label></div>' +
					'<div class="yp-field"><label for="yp-set-telegram-token">Bot token</label><input type="password" autocomplete="off" id="yp-set-telegram-token" value="' + YP.escapeAttr( settings.telegram_bot_token ) + '" placeholder="123456789:AA..." /></div>' +
					( settings.telegram_status ? '<p class="yp-panel__hint">' + YP.escapeHtml( settings.telegram_status ) + '</p>' : '' ) +
					'<div class="yp-field"><label for="yp-set-telegram-username">Public @username</label><input type="text" id="yp-set-telegram-username" value="' + YP.escapeAttr( settings.telegram_bot_username ) + '" placeholder="yeffoprint_bot" /></div>' +
					'<p class="yp-panel__hint">The bot\'s public handle from @BotFather (no "@") — powers the "Chat on Telegram" link on the homepage and in order emails. Separate from the token above, which is private and never shown to customers.</p>' +
					'<div class="yp-field"><label for="yp-set-telegram-admin-chat-id">Your chat ID</label><input type="text" id="yp-set-telegram-admin-chat-id" value="' + YP.escapeAttr( settings.telegram_admin_chat_id ) + '" placeholder="123456789" /></div>' +
					'<p class="yp-panel__hint">Message <code>/whoami</code> to the bot from your own Telegram to get this number. Lets you use the owner commands like <code>/pending</code> and <code>/ack</code>.</p>' +
					'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-set-telegram-owner-alerts"' + ( settings.telegram_owner_alerts ? ' checked' : '' ) + ' /><label for="yp-set-telegram-owner-alerts">Also send my store alerts to Telegram</label></div>' +
					'<p class="yp-panel__hint">New orders, express reminders, abandoned carts, messages, reviews and tracker feedback always come to the phone app as push alerts. Tick this to get a copy in your Telegram chat too. Customers\' Telegram messages and order updates aren\'t affected.</p>' +
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
					'<p class="yp-panel__hint">Sold via a Stripe Payment Link, created directly in your Stripe Dashboard — paste the webhook signing secret here once it’s set up. The payment link itself is set on the Maintenance add-on record under Web Design Add-ons.</p>' +
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

			var shippingOptionRows = viewEl.querySelector( '[data-yp-shipping-option-rows]' );
			wireRemoveShippingOptionRows( shippingOptionRows );
			viewEl.querySelector( '[data-yp-add-shipping-option]' ).addEventListener( 'click', function () {
				shippingOptionRows.insertAdjacentHTML( 'beforeend', manualOrderShippingOptionRowHtml( null ) );
				wireRemoveShippingOptionRows( shippingOptionRows );
			} );

			viewEl.querySelector( '[data-yp-save]' ).addEventListener( 'click', save );

			var eoCheck = viewEl.querySelector( '[data-yp-eo-check]' );
			eoCheck.addEventListener( 'click', function () {
				var statusLine = viewEl.querySelector( '[data-yp-eo-status]' );
				eoCheck.disabled = true;
				eoCheck.textContent = 'Checking…';
				YP.request( yeffoprintAdminApp.restUrl + 'admin/email-orders/check', { method: 'POST' } )
					.then( function ( result ) {
						statusLine.textContent = result.message;
					} )
					.catch( function ( error ) {
						statusLine.textContent = 'Couldn’t check: ' + error.message;
					} )
					.then( function () {
						eoCheck.disabled = false;
						eoCheck.textContent = 'Check now';
					} );
			} );
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
				site_meta_description: viewEl.querySelector( '#yp-set-meta-description' ).value,
				promo_enabled: viewEl.querySelector( '#yp-set-promo-enabled' ).checked,
				promo_banners: promoBanners,
				live_preview_enabled: viewEl.querySelector( '#yp-set-live-preview' ).checked,
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
				shippo_customs_description: viewEl.querySelector( '#yp-set-customs-description' ).value,
				shippo_customs_tariff: viewEl.querySelector( '#yp-set-customs-tariff' ).value,
				shippo_customs_signer: viewEl.querySelector( '#yp-set-customs-signer' ).value,
				manual_order_shipping_options: readShippingOptionRows( viewEl.querySelector( '[data-yp-shipping-option-rows]' ) ),
				contact_recipient_email: viewEl.querySelector( '#yp-set-contact-email' ).value,
				splash_enabled: viewEl.querySelector( '#yp-set-splash-enabled' ).checked,
				splash_image_id: parseInt( viewEl.querySelector( '[data-yp-splash-id]' ).value, 10 ) || 0,
				away_mode_enabled: viewEl.querySelector( '#yp-set-away-enabled' ).checked,
				away_mode_return_date: viewEl.querySelector( '#yp-set-away-return' ).value,
				express_enabled: viewEl.querySelector( '#yp-set-express-enabled' ).checked,
				production_time: {
					enabled: viewEl.querySelector( '#yp-set-prod-enabled' ).checked,
					per_day: parseInt( viewEl.querySelector( '#yp-set-prod-per-day' ).value, 10 ) || 4,
					min_days: parseInt( viewEl.querySelector( '#yp-set-prod-min' ).value, 10 ) || 1,
					busy_days: parseInt( viewEl.querySelector( '#yp-set-prod-busy' ).value, 10 ) || 4,
					closed_dates: viewEl.querySelector( '#yp-set-prod-closed' ).value.split( /[\s,]+/ ).filter( Boolean )
				},
				live_rates: {
					enabled: viewEl.querySelector( '#yp-set-live-enabled' ).checked,
					markup: Math.max( 0, parseFloat( viewEl.querySelector( '#yp-set-live-markup' ).value ) || 0 ),
					keep_free: viewEl.querySelector( '#yp-set-live-keep-free' ).checked,
					services: Array.prototype.filter.call( viewEl.querySelectorAll( '[data-yp-live-service]' ), function ( input ) { return input.checked; } )
						.map( function ( input ) { return input.getAttribute( 'data-yp-live-service' ); } )
				},
				local_pickup_enabled: viewEl.querySelector( '#yp-set-pickup-enabled' ).checked,
				local_pickup_label: viewEl.querySelector( '#yp-set-pickup-label' ).value,
				local_pickup_instructions: viewEl.querySelector( '#yp-set-pickup-instructions' ).value,
				local_pickup_product_ids: Array.prototype.filter.call( viewEl.querySelectorAll( '[data-yp-pickup-product]' ), function ( input ) { return input.checked; } )
					.map( function ( input ) { return parseInt( input.getAttribute( 'data-yp-pickup-product' ), 10 ); } ),
				express_fee: Math.max( 0, parseFloat( viewEl.querySelector( '#yp-set-express-fee' ).value ) || 0 ),
				dashboard_due_date_days: parseInt( viewEl.querySelector( '#yp-set-due-date' ).value, 10 ) || 7,
				maintenance_webhook_secret: viewEl.querySelector( '#yp-set-maint-secret' ).value,
				usda_api_key: viewEl.querySelector( '#yp-set-usda-key' ).value,
				email_orders_enabled: viewEl.querySelector( '#yp-set-eo-enabled' ).checked,
				email_orders_host: viewEl.querySelector( '#yp-set-eo-host' ).value.trim(),
				email_orders_port: parseInt( viewEl.querySelector( '#yp-set-eo-port' ).value, 10 ) || 993,
				email_orders_username: viewEl.querySelector( '#yp-set-eo-user' ).value.trim(),
				email_orders_folder: viewEl.querySelector( '#yp-set-eo-folder' ).value.trim(),
				email_orders_password: viewEl.querySelector( '#yp-set-eo-password' ).value,
				email_orders_senders: viewEl.querySelector( '#yp-set-eo-senders' ).value,
				claude_api_key: viewEl.querySelector( '#yp-set-claude-key' ) ? viewEl.querySelector( '#yp-set-claude-key' ).value.trim() : '',
				telegram_bot_token: viewEl.querySelector( '#yp-set-telegram-token' ).value,
				telegram_bot_username: viewEl.querySelector( '#yp-set-telegram-username' ).value,
				telegram_enabled: viewEl.querySelector( '#yp-set-telegram-enabled' ).checked,
				telegram_admin_chat_id: viewEl.querySelector( '#yp-set-telegram-admin-chat-id' ).value,
				telegram_owner_alerts: viewEl.querySelector( '#yp-set-telegram-owner-alerts' ).checked,
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

					// Direct report: after editing "Manual order shipping
					// options" here and saving, the Manual Order screen's
					// shipping picker still showed the old amount. Root
					// cause — yeffoprintAdminApp.shippo.manualOrderShippingOptions
					// (views/manual-order.js's own only source for that
					// picker) is wp_localize_script'd once per full
					// wp-admin page load; this SPA never reloads the page
					// between tabs, so that global just sat stale until a
					// hard refresh. Syncing it here means the very next
					// Manual Order screen in this same session already
					// sees what was just saved.
					if ( yeffoprintAdminApp.shippo ) {
						yeffoprintAdminApp.shippo.manualOrderShippingOptions = settings.manual_order_shipping_options || [];
					}

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
