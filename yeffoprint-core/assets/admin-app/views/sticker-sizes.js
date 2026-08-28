/**
 * Sticker Sizes — same CRUD pattern as views/materials.js and
 * views/sizes.js (WP core's own `/wp/v2/yp_sticker_size` REST route),
 * with one added wrinkle: exactly one tier may be the "custom size"
 * one (customer types exact dimensions, priced live from a $/sq in
 * rate instead of a fixed price — see YeffoPrint_Sticker_Size_Meta's
 * own docblock). That exclusivity is enforced server-side now
 * (class-sticker-size-meta.php's enforce_single_custom_tier(), added
 * alongside this screen) — this UI just reflects it and warns before
 * the fact, it isn't the thing making it true.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var META = {
		widthIn: '_yp_width_in',
		heightIn: '_yp_height_in',
		price: '_yp_price',
		isCustom: '_yp_is_custom_size'
	};

	function endpoint( path ) {
		return yeffoprintAdminApp.wpApiUrl + 'yp_sticker_size' + ( path || '' );
	}

	function formatIn( value ) {
		value = parseFloat( value ) || 0;
		return Math.round( value * 100 ) / 100;
	}

	YP.views[ 'sticker-sizes' ] = function ( viewEl ) {
		var allSizes = [];

		viewEl.innerHTML =
			'<p class="yp-app__intro">Preset sticker size tiers, plus the one "custom size" tier customers can type exact dimensions into. Only one tier can be the custom one.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search sticker sizes&hellip;" />' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add Sticker Size</button>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Size</th><th>Dimensions</th><th>Price</th><th>Status</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="5">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl = viewEl.querySelector( '[data-yp-search]' );

		function load() {
			rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">Loading&hellip;</td></tr>';
			YP.request( endpoint( '?context=edit&status=publish,draft&per_page=100&orderby=menu_order&order=asc' ) )
				.then( function ( sizes ) {
					allSizes = sizes || [];
					renderRows( allSizes );
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">Couldn’t load sticker sizes: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows( sizes ) {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? sizes.filter( function ( s ) { return s.title.raw.toLowerCase().indexOf( query ) !== -1; } )
				: sizes;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">' + ( sizes.length ? 'No sticker sizes match your search.' : 'No sticker sizes yet — add the first one above.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( size, index ) {
				var isCustom = !! ( size.meta && size.meta[ META.isCustom ] );
				var width = size.meta ? formatIn( size.meta[ META.widthIn ] ) : 0;
				var height = size.meta ? formatIn( size.meta[ META.heightIn ] ) : 0;
				var price = size.meta ? parseFloat( size.meta[ META.price ] ) || 0 : 0;
				var isPublished = 'publish' === size.status;

				return (
					'<tr data-id="' + size.id + '">' +
						'<td><div class="yp-record-name">' + YP.escapeHtml( size.title.raw ) +
							( isCustom ? ' <span class="yp-pill yp-pill--good">Custom tier</span>' : '' ) +
						'</div></td>' +
						'<td><span class="yp-chip">' + ( isCustom ? 'Customer-entered' : ( width + '&nbsp;&times;&nbsp;' + height + '&nbsp;in' ) ) + '</span></td>' +
						'<td><span class="yp-chip">' + ( isCustom ? '$/sq in rate' : '$' + price.toFixed( 2 ) ) + '</span></td>' +
						'<td><span class="yp-pill ' + ( isPublished ? 'yp-pill--good' : 'yp-pill--neutral' ) + '">' + ( isPublished ? 'Active' : 'Draft' ) + '</span></td>' +
						'<td class="yp-row-actions">' +
							'<button type="button" class="yp-row-action" data-yp-move-up="' + size.id + '" ' + ( 0 === index ? 'disabled' : '' ) + ' aria-label="Move up">&uarr;</button>' +
							'<button type="button" class="yp-row-action" data-yp-move-down="' + size.id + '" ' + ( index === filtered.length - 1 ? 'disabled' : '' ) + ' aria-label="Move down">&darr;</button>' +
							'<button type="button" class="yp-row-action" data-yp-edit="' + size.id + '" aria-label="Edit">Edit</button>' +
							'<button type="button" class="yp-row-action" data-yp-delete="' + size.id + '" aria-label="Delete">Delete</button>' +
						'</td>' +
					'</tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-edit]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { openForm( findById( button.getAttribute( 'data-yp-edit' ) ) ); } );
			} );
			rowsEl.querySelectorAll( '[data-yp-delete]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { deleteSize( findById( button.getAttribute( 'data-yp-delete' ) ) ); } );
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
			for ( var i = 0; i < allSizes.length; i++ ) {
				if ( allSizes[ i ].id === id ) {
					return allSizes[ i ];
				}
			}
			return null;
		}

		/**
		 * Swapping two items' menu_order values silently does nothing once
		 * they're already equal — which every sticker size created here
		 * was, since save() below never set menu_order on creation,
		 * defaulting new records to 0 (same bug, same fix, as
		 * web-design-packages.js's move()). Renumbering the whole list to
		 * match the new visual order avoids that failure mode entirely and
		 * self-heals any existing ties the moment a size is moved.
		 */
		function move( size, direction ) {
			var index = allSizes.indexOf( size );
			var swapIndex = index + direction;
			if ( ! size || swapIndex < 0 || swapIndex >= allSizes.length ) {
				return;
			}

			var reordered = allSizes.slice();
			reordered.splice( index, 1 );
			reordered.splice( swapIndex, 0, size );

			var updates = [];
			reordered.forEach( function ( s, i ) {
				if ( s.menu_order !== i ) {
					updates.push( YP.request( endpoint( '/' + s.id ), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { menu_order: i } ) } ) );
				}
			} );

			Promise.all( updates ).then( load ).catch( function ( error ) {
				window.alert( 'Couldn’t reorder: ' + error.message );
			} );
		}

		function deleteSize( size ) {
			if ( ! size || ! window.confirm( 'Delete "' + size.title.raw + '"? This moves it to Trash — it can be restored from Sticker Sizes → Trash in wp-admin if needed.' ) ) {
				return;
			}
			YP.request( endpoint( '/' + size.id ), { method: 'DELETE' } )
				.then( load )
				.catch( function ( error ) {
					window.alert( 'Couldn’t delete: ' + error.message );
				} );
		}

		/* ---------- Add/Edit drawer ---------- */

		function openForm( size ) {
			var isEdit = !! size;
			var meta = ( size && size.meta ) || {};
			var isCustom = !! meta[ META.isCustom ];
			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--center';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="' + ( isEdit ? 'Edit Sticker Size' : 'Add Sticker Size' ) + '">' +
					'<div class="yp-drawer__header"><span>' + ( isEdit ? 'Edit Sticker Size' : 'Add Sticker Size' ) + '</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<form class="yp-form" data-yp-form>' +
							'<div data-yp-form-error></div>' +
							'<div class="yp-field"><label for="yp-ss-name">Name</label><input type="text" id="yp-ss-name" name="name" required value="' + ( isEdit ? YP.escapeAttr( size.title.raw ) : '' ) + '" /></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-ss-custom" name="is_custom"' + ( isCustom ? ' checked' : '' ) + ' /><label for="yp-ss-custom">This is the custom-size tier</label></div>' +
							'<p class="yp-field__hint" data-yp-custom-hint' + ( isCustom ? '' : ' hidden' ) + '>Width, height, and price below are unused for this tier — price comes from the $/sq in rate on Pricing Rules instead. Marking this tier custom will un-mark whichever tier currently is.</p>' +
							'<div class="yp-form__row" data-yp-dimension-fields>' +
								'<div class="yp-field"><label for="yp-ss-width">Width (in)</label><input type="number" step="0.01" min="0" id="yp-ss-width" name="width" value="' + ( meta[ META.widthIn ] || '' ) + '" /></div>' +
								'<div class="yp-field"><label for="yp-ss-height">Height (in)</label><input type="number" step="0.01" min="0" id="yp-ss-height" name="height" value="' + ( meta[ META.heightIn ] || '' ) + '" /></div>' +
							'</div>' +
							'<div class="yp-field" data-yp-price-field><label for="yp-ss-price">Price ($, per sticker)</label><input type="number" step="0.01" min="0" id="yp-ss-price" name="price" value="' + ( meta[ META.price ] || '0' ) + '" /></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-ss-active" name="active"' + ( ! isEdit || 'publish' === size.status ? ' checked' : '' ) + ' /><label for="yp-ss-active">Active (visible to customers)</label></div>' +
							'<div class="yp-form__actions">' +
								'<button type="submit" class="wp-block-button__link is-style-accent" data-yp-save>' + ( isEdit ? 'Save changes' : 'Add sticker size' ) + '</button>' +
								'<button type="button" class="wp-block-button__link is-style-outline" data-yp-drawer-close>Cancel</button>' +
							'</div>' +
						'</form>' +
					'</div>' +
				'</div>';

			document.body.appendChild( drawer );
			YP.initDrawer( drawer );
			YP.openDrawer( drawer );

			var customCheckbox = drawer.querySelector( '#yp-ss-custom' );
			var hint = drawer.querySelector( '[data-yp-custom-hint]' );
			var dimensionFields = drawer.querySelector( '[data-yp-dimension-fields]' );
			var priceField = drawer.querySelector( '[data-yp-price-field]' );

			function toggleCustomFields() {
				var checked = customCheckbox.checked;
				hint.hidden = ! checked;
				dimensionFields.style.opacity = checked ? '0.5' : '1';
				priceField.style.opacity = checked ? '0.5' : '1';
			}
			customCheckbox.addEventListener( 'change', toggleCustomFields );
			toggleCustomFields();

			drawer.querySelector( '[data-yp-form]' ).addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				save( size, drawer );
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
			body.meta[ META.isCustom ] = form.is_custom.checked;
			body.meta[ META.widthIn ] = parseFloat( form.width.value ) || 0;
			body.meta[ META.heightIn ] = parseFloat( form.height.value ) || 0;
			body.meta[ META.price ] = parseFloat( form.price.value ) || 0;
			if ( ! existing ) {
				body.menu_order = allSizes.length; // New sizes land at the end of the list, not menu_order 0 (see move()'s docblock above).
			}

			var url = existing ? endpoint( '/' + existing.id ) : endpoint();

			YP.request( url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } )
				.then( function () {
					YP.closeDrawer( drawer );
					load();
				} )
				.catch( function ( error ) {
					saveButton.disabled = false;
					saveButton.textContent = existing ? 'Save changes' : 'Add sticker size';
					errorEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		viewEl.querySelector( '[data-yp-add]' ).addEventListener( 'click', function () { openForm( null ); } );
		searchEl.addEventListener( 'input', function () { renderRows( allSizes ); } );

		load();
	};
} )();
