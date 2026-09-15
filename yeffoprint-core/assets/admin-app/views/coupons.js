/**
 * Coupons — direct request: "coupon management would be great."
 * `shop_coupon` (WooCommerce core) has no REST support of its own
 * (`show_in_rest` was never set on it), so this talks to a small
 * dedicated controller (class-admin-coupon-controller.php) that wraps
 * `WC_Coupon` directly instead of riding a `/wp/v2/shop_coupon` route
 * the way Materials/Sizes do on their own REST-enabled CPTs.
 *
 * Same list/search + add/edit drawer shape as views/materials.js.
 * Deliberate scope limit (see the controller's own docblock): no
 * product/category restriction picker — a coupon needing that still
 * gets built in the classic wp-admin Coupons screen, unaffected by
 * this one existing alongside it.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var DISCOUNT_TYPES = {
		fixed_cart: 'Fixed cart discount',
		percent: 'Percentage discount',
		fixed_product: 'Fixed product discount'
	};

	function endpoint( path ) {
		return yeffoprintAdminApp.restUrl + 'admin/' + path;
	}

	function formatAmount( coupon ) {
		return 'percent' === coupon.discount_type ? coupon.amount + '%' : '$' + parseFloat( coupon.amount ).toFixed( 2 );
	}

	YP.views.coupons = function ( viewEl ) {
		var allCoupons  = [];
		var searchTimer = null;

		viewEl.innerHTML =
			'<p class="yp-app__intro">Discount codes customers can apply at checkout.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search coupon codes&hellip;" />' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add Coupon</button>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Code</th><th>Type</th><th>Amount</th><th>Usage</th><th>Expires</th><th>Status</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="7">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl   = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl = viewEl.querySelector( '[data-yp-search]' );

		function load() {
			rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="7">Loading&hellip;</td></tr>';
			var query = 'per_page=100' + ( searchEl.value.trim() ? '&search=' + encodeURIComponent( searchEl.value.trim() ) : '' );
			YP.request( endpoint( 'coupons?' + query ) )
				.then( function ( response ) {
					allCoupons = response.coupons || [];
					renderRows( allCoupons );
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="7">Couldn’t load coupons: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows( coupons ) {
			if ( ! coupons.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="7">No coupons match.</td></tr>';
				return;
			}

			rowsEl.innerHTML = coupons.map( function ( coupon ) {
				return (
					'<tr>' +
						'<td><strong>' + YP.escapeHtml( coupon.code ) + '</strong></td>' +
						'<td>' + ( DISCOUNT_TYPES[ coupon.discount_type ] || YP.escapeHtml( coupon.discount_type ) ) + '</td>' +
						'<td>' + formatAmount( coupon ) + '</td>' +
						'<td>' + coupon.usage_count + ( coupon.usage_limit ? ' / ' + coupon.usage_limit : '' ) + '</td>' +
						'<td>' + ( coupon.expiry_date || '—' ) + '</td>' +
						'<td><span class="yp-pill yp-pill--' + ( coupon.active ? 'good' : 'neutral' ) + '">' + ( coupon.active ? 'Active' : 'Inactive' ) + '</span></td>' +
						'<td>' +
							'<button type="button" class="yp-row-action" data-yp-edit="' + coupon.id + '">Edit</button>' +
							'<button type="button" class="yp-row-action" data-yp-delete="' + coupon.id + '">Delete</button>' +
						'</td>' +
					'</tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-edit]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var id = parseInt( button.getAttribute( 'data-yp-edit' ), 10 );
					YP.request( endpoint( 'coupon/' + id ) ).then( openForm ).catch( function ( error ) {
						window.alert( 'Couldn’t load this coupon: ' + error.message );
					} );
				} );
			} );

			rowsEl.querySelectorAll( '[data-yp-delete]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var id = parseInt( button.getAttribute( 'data-yp-delete' ), 10 );
					var coupon = coupons.filter( function ( c ) { return c.id === id; } )[ 0 ];
					YP.confirmModal( {
						title: 'Delete this coupon?',
						message: 'Delete "' + ( coupon ? coupon.code : 'this coupon' ) + '"? This moves it to Trash — it can be restored from Marketing → Coupons → Trash in wp-admin if needed.',
						confirmLabel: 'Delete Coupon',
						danger: true,
						onConfirm: function () {
							YP.request( endpoint( 'coupon/' + id ), { method: 'DELETE' } )
								.then( load )
								.catch( function ( error ) {
									window.alert( 'Couldn’t delete: ' + error.message );
								} );
						}
					} );
				} );
			} );
		}

		viewEl.querySelector( '[data-yp-add]' ).addEventListener( 'click', function () { openForm( null ); } );

		searchEl.addEventListener( 'input', function () {
			window.clearTimeout( searchTimer );
			searchTimer = window.setTimeout( load, 350 );
		} );

		/* ---------- Add/Edit drawer ---------- */

		function openForm( coupon ) {
			var isEdit = !! coupon;
			coupon = coupon || {
				code: '', discount_type: 'fixed_cart', amount: 0, description: '', expiry_date: '',
				usage_limit: '', usage_limit_per_user: '', minimum_amount: '', maximum_amount: '',
				individual_use: false, free_shipping: false, email_restrictions: '', active: true
			};

			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--center';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="' + ( isEdit ? 'Edit Coupon' : 'Add Coupon' ) + '">' +
					'<div class="yp-drawer__header"><span>' + ( isEdit ? 'Edit Coupon' : 'Add Coupon' ) + '</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<form class="yp-form" data-yp-form>' +
							'<div data-yp-form-error></div>' +
							'<div class="yp-field"><label for="yp-coupon-code">Coupon code</label><input type="text" id="yp-coupon-code" name="code" required value="' + YP.escapeAttr( coupon.code ) + '" style="text-transform:uppercase;" /></div>' +
							'<div class="yp-form__row">' +
								'<div class="yp-field"><label for="yp-coupon-type">Discount type</label><select id="yp-coupon-type" name="discount_type">' +
									Object.keys( DISCOUNT_TYPES ).map( function ( key ) {
										return '<option value="' + key + '"' + ( coupon.discount_type === key ? ' selected' : '' ) + '>' + DISCOUNT_TYPES[ key ] + '</option>';
									} ).join( '' ) +
								'</select></div>' +
								'<div class="yp-field"><label for="yp-coupon-amount">Amount</label><input type="number" step="0.01" min="0" id="yp-coupon-amount" name="amount" value="' + YP.escapeAttr( coupon.amount ) + '" /></div>' +
							'</div>' +
							'<div class="yp-field"><label for="yp-coupon-desc">Description (staff-only)</label><textarea id="yp-coupon-desc" name="description">' + YP.escapeHtml( coupon.description || '' ) + '</textarea></div>' +
							'<div class="yp-form__row">' +
								'<div class="yp-field"><label for="yp-coupon-expiry">Expiry date</label><input type="date" id="yp-coupon-expiry" name="expiry_date" value="' + YP.escapeAttr( coupon.expiry_date || '' ) + '" /></div>' +
								'<div class="yp-field"><label for="yp-coupon-usage-limit">Usage limit</label><input type="number" min="0" id="yp-coupon-usage-limit" name="usage_limit" placeholder="Unlimited" value="' + YP.escapeAttr( coupon.usage_limit || '' ) + '" /></div>' +
							'</div>' +
							'<div class="yp-form__row">' +
								'<div class="yp-field"><label for="yp-coupon-usage-per-user">Usage limit per customer</label><input type="number" min="0" id="yp-coupon-usage-per-user" name="usage_limit_per_user" placeholder="Unlimited" value="' + YP.escapeAttr( coupon.usage_limit_per_user || '' ) + '" /></div>' +
							'</div>' +
							'<div class="yp-form__row">' +
								'<div class="yp-field"><label for="yp-coupon-min">Minimum spend</label><input type="number" step="0.01" min="0" id="yp-coupon-min" name="minimum_amount" placeholder="No minimum" value="' + YP.escapeAttr( coupon.minimum_amount || '' ) + '" /></div>' +
								'<div class="yp-field"><label for="yp-coupon-max">Maximum spend</label><input type="number" step="0.01" min="0" id="yp-coupon-max" name="maximum_amount" placeholder="No maximum" value="' + YP.escapeAttr( coupon.maximum_amount || '' ) + '" /></div>' +
							'</div>' +
							'<div class="yp-field"><label for="yp-coupon-emails">Allowed emails</label><input type="text" id="yp-coupon-emails" name="email_restrictions" placeholder="Comma-separated, supports * wildcards — leave blank for anyone" value="' + YP.escapeAttr( coupon.email_restrictions || '' ) + '" /></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-coupon-individual" name="individual_use"' + ( coupon.individual_use ? ' checked' : '' ) + ' /><label for="yp-coupon-individual">Individual use only (can’t be combined with other coupons)</label></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-coupon-shipping" name="free_shipping"' + ( coupon.free_shipping ? ' checked' : '' ) + ' /><label for="yp-coupon-shipping">Grants free shipping</label></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-coupon-active" name="active"' + ( coupon.active ? ' checked' : '' ) + ' /><label for="yp-coupon-active">Active</label></div>' +
							'<div class="yp-form__actions">' +
								'<button type="submit" class="wp-block-button__link is-style-accent" data-yp-save>' + ( isEdit ? 'Save changes' : 'Add coupon' ) + '</button>' +
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
				save( isEdit ? coupon.id : null, drawer );
			} );
		}

		function save( id, drawer ) {
			var form       = drawer.querySelector( '[data-yp-form]' );
			var errorEl    = drawer.querySelector( '[data-yp-form-error]' );
			var saveButton = drawer.querySelector( '[data-yp-save]' );
			var data       = new FormData( form );

			var body = {
				code: data.get( 'code' ),
				discount_type: data.get( 'discount_type' ),
				amount: data.get( 'amount' ),
				description: data.get( 'description' ),
				expiry_date: data.get( 'expiry_date' ),
				usage_limit: data.get( 'usage_limit' ),
				usage_limit_per_user: data.get( 'usage_limit_per_user' ),
				minimum_amount: data.get( 'minimum_amount' ),
				maximum_amount: data.get( 'maximum_amount' ),
				email_restrictions: data.get( 'email_restrictions' ),
				individual_use: form.querySelector( '[name="individual_use"]' ).checked,
				free_shipping: form.querySelector( '[name="free_shipping"]' ).checked,
				active: form.querySelector( '[name="active"]' ).checked
			};

			saveButton.disabled = true;
			saveButton.textContent = 'Saving…';
			errorEl.innerHTML = '';

			YP.request( endpoint( id ? 'coupon/' + id : 'coupon' ), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( body )
			} )
				.then( function () {
					YP.closeDrawer( drawer );
					load();
				} )
				.catch( function ( error ) {
					saveButton.disabled = false;
					saveButton.textContent = id ? 'Save changes' : 'Add coupon';
					errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		load();
	};
} )();
