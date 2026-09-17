/**
 * Web Design Add-ons — same CRUD pattern as views/web-design-packages.js
 * (that screen's own docblock explains why: WP core's own
 * `/wp/v2/yp_web_design_addon` REST route, no classic editor at all).
 * Direct request: "remember the add-ons we offer. I'd like to be able
 * to add/edit available add-on options that can be added to web design
 * orders." Replaces the two hardcoded badges (Maintenance, Hosting)
 * patterns/web-design-packages.php used to hold — see
 * class-web-design-addon-meta.php for the field shapes.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var META = {
		price: '_yp_price',
		badgeText: '_yp_badge_text',
		modalHeading: '_yp_modal_heading',
		modalBody: '_yp_modal_body',
		features: '_yp_features',
		ctaLabel: '_yp_cta_label',
		ctaUrl: '_yp_cta_url',
		icon: '_yp_icon'
	};

	/** Same 6 choices as YeffoPrint_Web_Design_Addon_Meta::ICON_CHOICES — kept in sync by hand, same as any other small fixed enum in this app (e.g. coupon discount types). */
	var ICONS = [ 'wrench', 'globe', 'shield', 'clock', 'tag', 'star' ];

	function endpoint( path ) {
		return yeffoprintAdminApp.wpApiUrl + 'yp_web_design_addon' + ( path || '' );
	}

	YP.views[ 'web-design-addons' ] = function ( viewEl ) {
		var allAddons = [];

		viewEl.innerHTML =
			'<p class="yp-app__intro">The badges above the Web Design page’s pricing table — Maintenance, Hosting, and anything else you want to offer alongside a package.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search add-ons&hellip;" />' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add Add-on</button>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Add-on</th><th>Price</th><th>CTA</th><th>Status</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="5">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl = viewEl.querySelector( '[data-yp-search]' );

		function load() {
			rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">Loading&hellip;</td></tr>';
			YP.request( endpoint( '?context=edit&status=publish,draft&per_page=100&orderby=menu_order&order=asc' ) )
				.then( function ( addons ) {
					allAddons = addons || [];
					renderRows( allAddons );
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">Couldn’t load add-ons: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows( addons ) {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? addons.filter( function ( a ) { return a.title.raw.toLowerCase().indexOf( query ) !== -1; } )
				: addons;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">' + ( addons.length ? 'No add-ons match your search.' : 'No add-ons yet — add the first one above.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( addon, index ) {
				var price = addon.meta ? ( addon.meta[ META.price ] || '&mdash;' ) : '&mdash;';
				var ctaUrl = addon.meta ? ( addon.meta[ META.ctaUrl ] || '' ) : '';
				var isPublished = 'publish' === addon.status;

				return (
					'<tr data-id="' + addon.id + '">' +
						'<td><div class="yp-record-name">' + YP.escapeHtml( addon.title.raw ) + '</div></td>' +
						'<td><span class="yp-chip">' + YP.escapeHtml( price ) + '</span></td>' +
						'<td>' + ( ctaUrl
							? '<span class="yp-pill yp-pill--good">Payment link</span>'
							: '<span class="yp-pill yp-pill--neutral">Quote form</span>' ) + '</td>' +
						'<td><span class="yp-pill ' + ( isPublished ? 'yp-pill--good' : 'yp-pill--neutral' ) + '">' + ( isPublished ? 'Active' : 'Draft' ) + '</span></td>' +
						'<td class="yp-row-actions">' +
							'<button type="button" class="yp-row-action" data-yp-move-up="' + addon.id + '" ' + ( 0 === index ? 'disabled' : '' ) + ' aria-label="Move up">&uarr;</button>' +
							'<button type="button" class="yp-row-action" data-yp-move-down="' + addon.id + '" ' + ( index === filtered.length - 1 ? 'disabled' : '' ) + ' aria-label="Move down">&darr;</button>' +
							'<button type="button" class="yp-row-action" data-yp-edit="' + addon.id + '" aria-label="Edit">Edit</button>' +
							'<button type="button" class="yp-row-action" data-yp-delete="' + addon.id + '" aria-label="Delete">Delete</button>' +
						'</td>' +
					'</tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-edit]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { openForm( findById( button.getAttribute( 'data-yp-edit' ) ) ); } );
			} );
			rowsEl.querySelectorAll( '[data-yp-delete]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { deleteAddon( findById( button.getAttribute( 'data-yp-delete' ) ) ); } );
			} );
			rowsEl.querySelectorAll( '[data-yp-move-up]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { move( findById( button.getAttribute( 'data-yp-move-up' ) ), -1 ); } );
			} );
			rowsEl.querySelectorAll( '[data-yp-move-down]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { move( findById( button.getAttribute( 'data-yp-move-down' ) ), 1 ); } );
			} );
		}

		function findById( id ) {
			id = parseInt( id, 10 );
			for ( var i = 0; i < allAddons.length; i++ ) {
				if ( allAddons[ i ].id === id ) {
					return allAddons[ i ];
				}
			}
			return null;
		}

		/** Same renumber-the-whole-list approach as web-design-packages.js's own move() — see that file's docblock for why a plain swap doesn't work here either. */
		function move( addon, direction ) {
			var index = allAddons.indexOf( addon );
			var swapIndex = index + direction;
			if ( ! addon || swapIndex < 0 || swapIndex >= allAddons.length ) {
				return;
			}

			var reordered = allAddons.slice();
			reordered.splice( index, 1 );
			reordered.splice( swapIndex, 0, addon );

			var updates = [];
			reordered.forEach( function ( a, i ) {
				if ( a.menu_order !== i ) {
					updates.push( YP.request( endpoint( '/' + a.id ), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { menu_order: i } ) } ) );
				}
			} );

			Promise.all( updates ).then( load ).catch( function ( error ) {
				window.alert( 'Couldn’t reorder: ' + error.message );
			} );
		}

		function deleteAddon( addon ) {
			if ( ! addon || ! window.confirm( 'Delete "' + addon.title.raw + '"? This moves it to Trash — it can be restored from Web Design Add-ons → Trash in wp-admin if needed.' ) ) {
				return;
			}
			YP.request( endpoint( '/' + addon.id ), { method: 'DELETE' } )
				.then( load )
				.catch( function ( error ) {
					window.alert( 'Couldn’t delete: ' + error.message );
				} );
		}

		/* ---------- Add/Edit drawer ---------- */

		function openForm( addon ) {
			var isEdit = !! addon;
			var meta = ( addon && addon.meta ) || {};
			var features = ( meta[ META.features ] || [] ).join( '\n' );
			var icon = meta[ META.icon ] || 'tag';
			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--center';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="' + ( isEdit ? 'Edit Add-on' : 'Add Add-on' ) + '">' +
					'<div class="yp-drawer__header"><span>' + ( isEdit ? 'Edit Add-on' : 'Add Add-on' ) + '</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<form class="yp-form" data-yp-form>' +
							'<div data-yp-form-error></div>' +
							'<div class="yp-field"><label for="yp-addon-name">Name</label><input type="text" id="yp-addon-name" name="name" required value="' + ( isEdit ? YP.escapeAttr( addon.title.raw ) : '' ) + '" placeholder="e.g. Ongoing Maintenance &amp; Monitoring" /></div>' +
							'<div class="yp-form__row">' +
								'<div class="yp-field"><label for="yp-addon-price">Price</label><input type="text" id="yp-addon-price" name="price" value="' + YP.escapeAttr( meta[ META.price ] || '' ) + '" placeholder="$35/mo" /></div>' +
								'<div class="yp-field"><label for="yp-addon-icon">Icon</label><select id="yp-addon-icon" name="icon">' +
									ICONS.map( function ( key ) {
										return '<option value="' + key + '"' + ( icon === key ? ' selected' : '' ) + '>' + key.charAt( 0 ).toUpperCase() + key.slice( 1 ) + '</option>';
									} ).join( '' ) +
								'</select></div>' +
							'</div>' +
							'<div class="yp-field"><label for="yp-addon-badge">Badge text</label><input type="text" id="yp-addon-badge" name="badgeText" value="' + YP.escapeAttr( meta[ META.badgeText ] || '' ) + '" placeholder="Every package can add ongoing maintenance for $35/mo" />' +
								'<p class="yp-field__hint">The pill button’s own one-line text, shown exactly as typed — include the price yourself wherever it reads naturally.</p>' +
							'</div>' +
							'<div class="yp-field"><label for="yp-addon-modal-heading">Modal heading</label><input type="text" id="yp-addon-modal-heading" name="modalHeading" value="' + YP.escapeAttr( meta[ META.modalHeading ] || '' ) + '" placeholder="Ongoing Maintenance &amp; Monitoring" /></div>' +
							'<div class="yp-field"><label for="yp-addon-modal-body">Modal body</label><textarea id="yp-addon-modal-body" name="modalBody" placeholder="A launched site still needs attention&hellip;">' + YP.escapeHtml( meta[ META.modalBody ] || '' ) + '</textarea></div>' +
							'<div class="yp-field"><label for="yp-addon-features">Features</label><textarea id="yp-addon-features" name="features" placeholder="One per line">' + YP.escapeHtml( features ) + '</textarea>' +
								'<p class="yp-field__hint">One bullet per line — shown in this order inside the modal.</p>' +
							'</div>' +
							'<div class="yp-form__row">' +
								'<div class="yp-field"><label for="yp-addon-cta-label">Button label</label><input type="text" id="yp-addon-cta-label" name="ctaLabel" value="' + YP.escapeAttr( meta[ META.ctaLabel ] || '' ) + '" placeholder="Subscribe to Maintenance" /></div>' +
								'<div class="yp-field"><label for="yp-addon-cta-url">Payment link (optional)</label><input type="url" id="yp-addon-cta-url" name="ctaUrl" value="' + YP.escapeAttr( meta[ META.ctaUrl ] || '' ) + '" placeholder="https://buy.stripe.com/&hellip;" /></div>' +
							'</div>' +
							'<p class="yp-field__hint">Leave the payment link blank to send the button to the Web Design quote form instead — that’s the right choice for an add-on that isn’t sold self-serve yet.</p>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-addon-active" name="active"' + ( ! isEdit || 'publish' === addon.status ? ' checked' : '' ) + ' /><label for="yp-addon-active">Active (visible to customers)</label></div>' +
							'<div class="yp-form__actions">' +
								'<button type="submit" class="wp-block-button__link is-style-accent" data-yp-save>' + ( isEdit ? 'Save changes' : 'Add add-on' ) + '</button>' +
								'<button type="button" class="wp-block-button__link is-style-outline" data-yp-drawer-close>Cancel</button>' +
							'</div>' +
						'</form>' +
					'</div>' +
				'</div>';

			document.body.appendChild( drawer );
			YP.initDrawer( drawer );
			YP.openDrawer( drawer );

			drawer.querySelector( '[data-yp-form]' ).addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				save( addon, drawer );
			} );
		}

		function save( existing, drawer ) {
			var form = drawer.querySelector( '[data-yp-form]' );
			var errorEl = drawer.querySelector( '[data-yp-form-error]' );
			var saveButton = drawer.querySelector( '[data-yp-save]' );
			var name = form.name.value.trim();

			if ( ! name ) {
				errorEl.innerHTML = '<p class="yp-form__error">Name is required.</p>';
				return;
			}

			errorEl.innerHTML = '';
			saveButton.disabled = true;
			saveButton.textContent = 'Saving…';

			var body = {
				title: name,
				status: form.active.checked ? 'publish' : 'draft',
				meta: {}
			};
			body.meta[ META.price ] = form.price.value.trim();
			body.meta[ META.icon ] = form.icon.value;
			body.meta[ META.badgeText ] = form.badgeText.value.trim();
			body.meta[ META.modalHeading ] = form.modalHeading.value.trim();
			body.meta[ META.modalBody ] = form.modalBody.value.trim();
			body.meta[ META.ctaLabel ] = form.ctaLabel.value.trim();
			body.meta[ META.ctaUrl ] = form.ctaUrl.value.trim();
			body.meta[ META.features ] = form.features.value.split( '\n' ).map( function ( line ) { return line.trim(); } ).filter( function ( line ) { return line.length; } );
			if ( ! existing ) {
				body.menu_order = allAddons.length; // New add-ons land at the end of the list — see move()'s docblock above.
			}

			var url = existing ? endpoint( '/' + existing.id ) : endpoint();

			YP.request( url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } )
				.then( function () {
					YP.closeDrawer( drawer );
					load();
				} )
				.catch( function ( error ) {
					saveButton.disabled = false;
					saveButton.textContent = existing ? 'Save changes' : 'Add add-on';
					errorEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		viewEl.querySelector( '[data-yp-add]' ).addEventListener( 'click', function () { openForm( null ); } );
		searchEl.addEventListener( 'input', function () { renderRows( allAddons ); } );

		load();
	};
} )();
