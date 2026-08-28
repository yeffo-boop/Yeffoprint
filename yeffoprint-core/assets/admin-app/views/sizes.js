/**
 * Sizes — same pattern as views/materials.js (WP core's own
 * `/wp/v2/yp_size` REST route, no new PHP), with fewer fields: `yp_size`
 * doesn't support 'editor' or 'thumbnail' (class-post-type-registry.php),
 * so there's no description or swatch image here, just name, print
 * dimensions, price adjustment, and active state.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var META = {
		priceAdjustment: '_yp_price_adjustment',
		widthMm: '_yp_print_width_mm',
		heightMm: '_yp_print_height_mm'
	};

	function endpoint( path ) {
		return yeffoprintAdminApp.wpApiUrl + 'yp_size' + ( path || '' );
	}

	function formatMoney( amount ) {
		var value = parseFloat( amount ) || 0;
		return ( value >= 0 ? '+' : '' ) + '$' + value.toFixed( 2 );
	}

	function formatMm( value ) {
		value = parseFloat( value ) || 0;
		return value ? ( Math.round( value * 100 ) / 100 ) : 0;
	}

	YP.views.sizes = function ( viewEl ) {
		var allSizes = [];

		viewEl.innerHTML =
			'<p class="yp-app__intro">Label sizes offered in the configurator — print dimensions and price adjustment for each.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search sizes&hellip;" />' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add Size</button>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Size</th><th>Dimensions</th><th>Price adj.</th><th>Status</th><th></th>' +
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
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">Couldn’t load sizes: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows( sizes ) {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? sizes.filter( function ( s ) { return s.title.raw.toLowerCase().indexOf( query ) !== -1; } )
				: sizes;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">' + ( sizes.length ? 'No sizes match your search.' : 'No sizes yet — add the first one above.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( size, index ) {
				var width = size.meta ? formatMm( size.meta[ META.widthMm ] ) : 0;
				var height = size.meta ? formatMm( size.meta[ META.heightMm ] ) : 0;
				var priceAdjustment = size.meta ? parseFloat( size.meta[ META.priceAdjustment ] ) || 0 : 0;
				var isPublished = 'publish' === size.status;

				return (
					'<tr data-id="' + size.id + '">' +
						'<td><div class="yp-record-name">' + YP.escapeHtml( size.title.raw ) + '</div></td>' +
						'<td><span class="yp-chip">' + ( width && height ? width + '&nbsp;&times;&nbsp;' + height + '&nbsp;mm' : 'Not set' ) + '</span></td>' +
						'<td><span class="yp-chip">' + ( priceAdjustment ? formatMoney( priceAdjustment ) : 'No adjustment' ) + '</span></td>' +
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
		 * they're already equal — which every size created here was, since
		 * save() below never set menu_order on creation, defaulting new
		 * records to 0 (same bug, same fix, as web-design-packages.js's
		 * move()). Renumbering the whole list to match the new visual
		 * order avoids that failure mode entirely and self-heals any
		 * existing ties the moment a size is moved.
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
			if ( ! size || ! window.confirm( 'Delete "' + size.title.raw + '"? This moves it to Trash — it can be restored from Sizes → Trash in wp-admin if needed.' ) ) {
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
			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--center';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="' + ( isEdit ? 'Edit Size' : 'Add Size' ) + '">' +
					'<div class="yp-drawer__header"><span>' + ( isEdit ? 'Edit Size' : 'Add Size' ) + '</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<form class="yp-form" data-yp-form>' +
							'<div data-yp-form-error></div>' +
							'<div class="yp-field"><label for="yp-size-name">Name</label><input type="text" id="yp-size-name" name="name" required value="' + ( isEdit ? YP.escapeAttr( size.title.raw ) : '' ) + '" placeholder="e.g. 2&quot; &times; 1&quot;" /></div>' +
							'<div class="yp-form__row">' +
								'<div class="yp-field"><label for="yp-size-width">Print width (mm)</label><input type="number" step="0.1" min="0" id="yp-size-width" name="width" value="' + ( meta[ META.widthMm ] || '' ) + '" /></div>' +
								'<div class="yp-field"><label for="yp-size-height">Print height (mm)</label><input type="number" step="0.1" min="0" id="yp-size-height" name="height" value="' + ( meta[ META.heightMm ] || '' ) + '" /></div>' +
							'</div>' +
							'<div class="yp-field"><label for="yp-size-price">Price adjustment ($, per label)</label><input type="number" step="0.01" id="yp-size-price" name="price_adjustment" value="' + ( meta[ META.priceAdjustment ] || '0' ) + '" /></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-size-active" name="active"' + ( ! isEdit || 'publish' === size.status ? ' checked' : '' ) + ' /><label for="yp-size-active">Active (visible to customers)</label></div>' +
							'<div class="yp-form__actions">' +
								'<button type="submit" class="wp-block-button__link is-style-accent" data-yp-save>' + ( isEdit ? 'Save changes' : 'Add size' ) + '</button>' +
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
			body.meta[ META.widthMm ] = parseFloat( form.width.value ) || 0;
			body.meta[ META.heightMm ] = parseFloat( form.height.value ) || 0;
			body.meta[ META.priceAdjustment ] = parseFloat( form.price_adjustment.value ) || 0;
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
					saveButton.textContent = existing ? 'Save changes' : 'Add size';
					errorEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		viewEl.querySelector( '[data-yp-add]' ).addEventListener( 'click', function () { openForm( null ); } );
		searchEl.addEventListener( 'input', function () { renderRows( allSizes ); } );

		load();
	};
} )();
