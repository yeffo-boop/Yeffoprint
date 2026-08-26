/**
 * Pricing Rules — the first screen backed by a genuinely new admin
 * REST endpoint (`/admin/pricing-rule`, includes/rest/admin/class-admin-pricing-controller.php)
 * rather than WP core's own `/wp/v2/{type}` routes: `yp_pricing_rule`
 * has no REST-registered meta at all, so there was nothing core could
 * expose. Unlike every earlier screen, this edits "the one active
 * record" directly in the page (no list, no drawer, no id in the
 * URL) — there's only ever one thing to edit.
 *
 * Two independent bulk-discount tier repeaters (label + sticker) are
 * built the same way: tier rows are read straight from their table's
 * live DOM inputs at save time, not mirrored into a parallel JS array
 * — add/remove only ever appends or removes a `<tr>`, so there's
 * nothing to keep in sync between "what's on screen" and "what gets
 * saved."
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	function endpoint() {
		return yeffoprintAdminApp.restUrl + 'admin/pricing-rule';
	}

	function tierRowHtml( tier, tierTypes ) {
		tier = tier || { threshold: '', type: Object.keys( tierTypes )[ 0 ], value: '' };
		return (
			'<tr>' +
				'<td><input type="number" min="1" step="1" data-tier-threshold value="' + YP.escapeAttr( tier.threshold ) + '" /></td>' +
				'<td><select data-tier-type>' +
					Object.keys( tierTypes ).map( function ( key ) {
						return '<option value="' + key + '"' + ( tier.type === key ? ' selected' : '' ) + '>' + YP.escapeHtml( tierTypes[ key ] ) + '</option>';
					} ).join( '' ) +
				'</select></td>' +
				'<td><input type="number" min="0" step="0.01" data-tier-value value="' + YP.escapeAttr( tier.value ) + '" /></td>' +
				'<td><button type="button" class="yp-row-action" data-yp-remove-row aria-label="Remove tier">&times;</button></td>' +
			'</tr>'
		);
	}

	function wireRemoveButtons( tbody ) {
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

	function readTierRows( tbody ) {
		return Array.prototype.map.call( tbody.querySelectorAll( 'tr' ), function ( row ) {
			return {
				threshold: parseInt( row.querySelector( '[data-tier-threshold]' ).value, 10 ) || 0,
				type: row.querySelector( '[data-tier-type]' ).value,
				value: parseFloat( row.querySelector( '[data-tier-value]' ).value ) || 0
			};
		} ).filter( function ( tier ) { return tier.threshold > 0; } );
	}

	function adjustmentFieldsHtml( idPrefix, map, labels ) {
		return Object.keys( labels ).map( function ( key ) {
			return (
				'<div class="yp-field">' +
					'<label for="' + idPrefix + '-' + key + '">' + YP.escapeHtml( labels[ key ] ) + '</label>' +
					'<input type="number" step="0.01" id="' + idPrefix + '-' + key + '" data-adjustment="' + key + '" value="' + YP.escapeAttr( map[ key ] || 0 ) + '" />' +
				'</div>'
			);
		} ).join( '' );
	}

	function readAdjustments( containerEl ) {
		var result = {};
		containerEl.querySelectorAll( '[data-adjustment]' ).forEach( function ( input ) {
			result[ input.getAttribute( 'data-adjustment' ) ] = parseFloat( input.value ) || 0;
		} );
		return result;
	}

	YP.views.pricing = function ( viewEl ) {
		viewEl.innerHTML = '<p class="yp-app__intro">Loading pricing&hellip;</p>';

		YP.request( endpoint() )
			.then( function ( schema ) {
				render( schema );
			} )
			.catch( function ( error ) {
				viewEl.innerHTML = '<p class="yp-app__intro">Couldn’t load pricing: ' + YP.escapeHtml( error.message ) + '</p>';
			} );

		function render( schema ) {
			viewEl.innerHTML =
				'<p class="yp-app__intro">Base pricing, bulk-discount tiers, and Custom Sticker rates. Changes apply to the storefront immediately.</p>' +
				'<div data-yp-save-status></div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Base Pricing</h2></div>' +
					'<div class="yp-form__row">' +
						'<div class="yp-field"><label for="yp-base-price">Base price per label ($)</label><input type="number" step="0.01" min="0" id="yp-base-price" value="' + YP.escapeAttr( schema.base_unit_price ) + '" /></div>' +
						'<div class="yp-field"><label for="yp-design-fee">Custom design fee ($)</label><input type="number" step="0.01" min="0" id="yp-design-fee" value="' + YP.escapeAttr( schema.custom_design_fee ) + '" /></div>' +
					'</div>' +
					'<p class="yp-panel__hint">Material and size price adjustments are set on each Material/Size record (Materials, Sizes).</p>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Bulk Discount Tiers</h2><button type="button" class="wp-block-button__link is-style-outline" data-yp-add-tier>+ Add tier</button></div>' +
					'<table class="yp-tier-table"><thead><tr><th>Min. quantity</th><th>Discount type</th><th>Value</th><th></th></tr></thead>' +
						'<tbody data-yp-label-tiers>' + schema.tiers.map( function ( t ) { return tierRowHtml( t, schema.tier_types ); } ).join( '' ) + '</tbody>' +
					'</table>' +
					'<p class="yp-panel__hint">The highest threshold at or below the customer’s combined label count applies to their whole order. The discount only ever applies to the base price — material/size upcharges are always added on top afterward, at full price.</p>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Sticker Pricing</h2></div>' +
					'<div class="yp-field"><label for="yp-sticker-rate">Custom size rate ($ per sq. inch)</label><input type="number" step="0.01" min="0" id="yp-sticker-rate" value="' + YP.escapeAttr( schema.sticker.custom_rate_per_sq_in ) + '" /></div>' +
					'<p class="yp-panel__hint">Used only for the Sticker Size marked “Custom size” — price = this rate &times; width &times; height. Every other tier uses its own fixed price (Sticker Sizes screen).</p>' +
					'<p><strong>Sticker type adjustment ($, added to size price)</strong></p>' +
					'<div class="yp-form__row" data-yp-type-adjustments>' + adjustmentFieldsHtml( 'yp-sticker-type', schema.sticker.type_adjustments, schema.sticker.types ) + '</div>' +
					'<p><strong>Shape adjustment ($, added to size price)</strong></p>' +
					'<div class="yp-form__row" data-yp-shape-adjustments>' + adjustmentFieldsHtml( 'yp-sticker-shape', schema.sticker.shape_adjustments, schema.sticker.shapes ) + '</div>' +
				'</div>' +

				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Sticker Bulk Discount Tiers</h2><button type="button" class="wp-block-button__link is-style-outline" data-yp-add-sticker-tier>+ Add tier</button></div>' +
					'<table class="yp-tier-table"><thead><tr><th>Min. quantity</th><th>Discount type</th><th>Value</th><th></th></tr></thead>' +
						'<tbody data-yp-sticker-tiers>' + schema.sticker.tiers.map( function ( t ) { return tierRowHtml( t, schema.tier_types ); } ).join( '' ) + '</tbody>' +
					'</table>' +
					'<p class="yp-panel__hint">Same rules as the label tiers above, evaluated separately against the customer’s combined sticker quantity — never mixed with label orders.</p>' +
				'</div>' +

				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-save>Save Pricing</button>';

			var labelTierBody = viewEl.querySelector( '[data-yp-label-tiers]' );
			var stickerTierBody = viewEl.querySelector( '[data-yp-sticker-tiers]' );
			var typeAdjustmentsEl = viewEl.querySelector( '[data-yp-type-adjustments]' );
			var shapeAdjustmentsEl = viewEl.querySelector( '[data-yp-shape-adjustments]' );
			var statusEl = viewEl.querySelector( '[data-yp-save-status]' );

			wireRemoveButtons( labelTierBody );
			wireRemoveButtons( stickerTierBody );

			viewEl.querySelector( '[data-yp-add-tier]' ).addEventListener( 'click', function () {
				labelTierBody.insertAdjacentHTML( 'beforeend', tierRowHtml( null, schema.tier_types ) );
				wireRemoveButtons( labelTierBody );
			} );
			viewEl.querySelector( '[data-yp-add-sticker-tier]' ).addEventListener( 'click', function () {
				stickerTierBody.insertAdjacentHTML( 'beforeend', tierRowHtml( null, schema.tier_types ) );
				wireRemoveButtons( stickerTierBody );
			} );

			viewEl.querySelector( '[data-yp-save]' ).addEventListener( 'click', function () {
				save( {
					saveButton: viewEl.querySelector( '[data-yp-save]' ),
					statusEl: statusEl,
					labelTierBody: labelTierBody,
					stickerTierBody: stickerTierBody,
					typeAdjustmentsEl: typeAdjustmentsEl,
					shapeAdjustmentsEl: shapeAdjustmentsEl
				} );
			} );
		}

		function save( refs ) {
			var body = {
				base_unit_price: parseFloat( viewEl.querySelector( '#yp-base-price' ).value ) || 0,
				custom_design_fee: parseFloat( viewEl.querySelector( '#yp-design-fee' ).value ) || 0,
				tiers: readTierRows( refs.labelTierBody ),
				sticker: {
					custom_rate_per_sq_in: parseFloat( viewEl.querySelector( '#yp-sticker-rate' ).value ) || 0,
					type_adjustments: readAdjustments( refs.typeAdjustmentsEl ),
					shape_adjustments: readAdjustments( refs.shapeAdjustmentsEl ),
					tiers: readTierRows( refs.stickerTierBody )
				}
			};

			refs.saveButton.disabled = true;
			refs.saveButton.textContent = 'Saving…';
			refs.statusEl.innerHTML = '';

			YP.request( endpoint(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } )
				.then( function ( schema ) {
					refs.saveButton.disabled = false;
					refs.saveButton.textContent = 'Save Pricing';
					refs.statusEl.innerHTML = '<p class="yp-panel__hint">Saved — live on the storefront now (rule version ' + schema.rule_version + ').</p>';
				} )
				.catch( function ( error ) {
					refs.saveButton.disabled = false;
					refs.saveButton.textContent = 'Save Pricing';
					refs.statusEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}
	};
} )();
