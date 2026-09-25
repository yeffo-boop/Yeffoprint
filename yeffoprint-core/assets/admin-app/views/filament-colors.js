/**
 * Filament Colors — the one shared list every 3D print's color choices
 * pick from (YeffoPrint_Print_Meta). Same CRUD pattern as
 * views/sticker-sizes.js (WP core's own `/wp/v2/yp_filament` route),
 * plus a one-click In stock switch right in the list: running out of a
 * color is the most common edit here, and it greys that color out on
 * every item at once.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var META = {
		hex: '_yp_filament_hex',
		finish: '_yp_filament_finish',
		extra: '_yp_filament_extra_charge',
		inStock: '_yp_filament_in_stock'
	};

	function endpoint( path ) {
		return yeffoprintAdminApp.wpApiUrl + 'yp_filament' + ( path || '' );
	}

	/** Shared with views/prints.js so a color looks the same in both screens. */
	YP.filamentSwatch = function ( hex, finish, size ) {
		return '<span class="yp-filament-dot' + ( 'silk' === finish ? ' is-silk' : '' ) + '" style="background-color:' + YP.escapeAttr( hex || '#888888' ) + ( size ? ';width:' + size + 'px;height:' + size + 'px' : '' ) + '"></span>';
	};

	YP.views[ 'filament-colors' ] = function ( viewEl ) {
		var allColors = [];

		viewEl.innerHTML =
			'<p class="yp-app__intro">Every filament color your 3D prints can use. Each item picks which of these it offers per part. Turn off In stock when you run out and that color greys out on the site until you turn it back on.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search colors&hellip;" />' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add Color</button>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Color</th><th>Finish</th><th>Extra charge</th><th>In stock</th><th>Status</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="6">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl = viewEl.querySelector( '[data-yp-search]' );

		function load() {
			YP.request( endpoint( '?context=edit&status=publish,draft&per_page=100&orderby=menu_order&order=asc' ) )
				.then( function ( colors ) {
					allColors = colors || [];
					renderRows();
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">Couldn’t load colors: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows() {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? allColors.filter( function ( c ) { return c.title.raw.toLowerCase().indexOf( query ) !== -1; } )
				: allColors;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">' + ( allColors.length ? 'No colors match your search.' : 'No colors yet. Add the first one above.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( color, index ) {
				var meta = color.meta || {};
				var extra = parseFloat( meta[ META.extra ] ) || 0;
				var inStock = false !== meta[ META.inStock ];
				var isPublished = 'publish' === color.status;

				return (
					'<tr data-id="' + color.id + '">' +
						'<td><div class="yp-filament-name">' + YP.filamentSwatch( meta[ META.hex ], meta[ META.finish ], 28 ) +
							'<div class="yp-record-name">' + YP.escapeHtml( color.title.raw ) + '<div class="yp-record-name__sub">' + YP.escapeHtml( meta[ META.hex ] || '' ) + '</div></div></div></td>' +
						'<td>' + ( 'silk' === meta[ META.finish ] ? 'Silk' : 'Matte' ) + '</td>' +
						'<td>' + ( extra > 0 ? '+$' + extra.toFixed( 2 ) : '&ndash;' ) + '</td>' +
						'<td><button type="button" class="yp-switch' + ( inStock ? ' is-on' : '' ) + '" role="switch" aria-checked="' + ( inStock ? 'true' : 'false' ) + '" aria-label="In stock" data-yp-stock="' + color.id + '"></button></td>' +
						'<td><span class="yp-pill ' + ( isPublished ? 'yp-pill--good' : 'yp-pill--neutral' ) + '">' + ( isPublished ? 'Active' : 'Draft' ) + '</span></td>' +
						'<td class="yp-row-actions">' +
							'<button type="button" class="yp-row-action" data-yp-move="-1" data-id="' + color.id + '" ' + ( 0 === index || query ? 'disabled' : '' ) + ' aria-label="Move up">&uarr;</button>' +
							'<button type="button" class="yp-row-action" data-yp-move="1" data-id="' + color.id + '" ' + ( index === filtered.length - 1 || query ? 'disabled' : '' ) + ' aria-label="Move down">&darr;</button>' +
							'<button type="button" class="yp-row-action" data-yp-edit="' + color.id + '">Edit</button>' +
							'<button type="button" class="yp-row-action" data-yp-delete="' + color.id + '">Delete</button>' +
						'</td>' +
					'</tr>'
				);
			} ).join( '' );
		}

		function findById( id ) {
			id = parseInt( id, 10 );
			for ( var i = 0; i < allColors.length; i++ ) {
				if ( allColors[ i ].id === id ) {
					return allColors[ i ];
				}
			}
			return null;
		}

		function post( id, body ) {
			return YP.request( endpoint( id ? '/' + id : '' ), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } );
		}

		function toggleStock( color, button ) {
			var next = ! button.classList.contains( 'is-on' );
			var body = { meta: {} };
			body.meta[ META.inStock ] = next;
			button.disabled = true;
			post( color.id, body )
				.then( function ( updated ) {
					var i = allColors.indexOf( color );
					if ( i !== -1 ) {
						allColors[ i ] = updated;
					}
					renderRows();
				} )
				.catch( function ( error ) {
					button.disabled = false;
					window.alert( 'Couldn’t update stock: ' + error.message );
				} );
		}

		/** Renumbers the whole list, same reason as views/sticker-sizes.js's move(). */
		function move( color, direction ) {
			var index = allColors.indexOf( color );
			var swapIndex = index + direction;
			if ( ! color || swapIndex < 0 || swapIndex >= allColors.length ) {
				return;
			}

			var reordered = allColors.slice();
			reordered.splice( index, 1 );
			reordered.splice( swapIndex, 0, color );

			Promise.all( reordered.map( function ( c, i ) {
				return c.menu_order === i ? null : post( c.id, { menu_order: i } );
			} ) ).then( load ).catch( function ( error ) {
				window.alert( 'Couldn’t reorder: ' + error.message );
			} );
		}

		function deleteColor( color ) {
			if ( ! color || ! window.confirm( 'Delete "' + color.title.raw + '"? Items offering it will stop showing it. Past orders keep the color name.' ) ) {
				return;
			}
			YP.request( endpoint( '/' + color.id ), { method: 'DELETE' } )
				.then( load )
				.catch( function ( error ) {
					window.alert( 'Couldn’t delete: ' + error.message );
				} );
		}

		function openForm( color ) {
			var isEdit = !! color;
			var meta = ( color && color.meta ) || {};
			var hex = meta[ META.hex ] || '#1d1d1f';
			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--center';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="' + ( isEdit ? 'Edit Color' : 'Add Color' ) + '">' +
					'<div class="yp-drawer__header"><span>' + ( isEdit ? 'Edit Color' : 'Add Color' ) + '</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<form class="yp-form" data-yp-form>' +
							'<div data-yp-form-error></div>' +
							'<div class="yp-field"><label for="yp-fc-name">Name</label><input type="text" id="yp-fc-name" name="name" required placeholder="Silk Gold" value="' + ( isEdit ? YP.escapeAttr( color.title.raw ) : '' ) + '" /></div>' +
							'<div class="yp-form__row">' +
								'<div class="yp-field"><label for="yp-fc-hex">Swatch color</label>' +
									'<div class="yp-filament-hex"><input type="color" id="yp-fc-hex" name="hex" value="' + YP.escapeAttr( hex ) + '" /><input type="text" name="hex_text" value="' + YP.escapeAttr( hex ) + '" maxlength="7" aria-label="Hex code" /></div>' +
								'</div>' +
								'<div class="yp-field"><label for="yp-fc-finish">Finish</label><select id="yp-fc-finish" name="finish">' +
									'<option value="matte"' + ( 'silk' !== meta[ META.finish ] ? ' selected' : '' ) + '>Matte (flat swatch)</option>' +
									'<option value="silk"' + ( 'silk' === meta[ META.finish ] ? ' selected' : '' ) + '>Silk (shiny swatch)</option>' +
								'</select></div>' +
							'</div>' +
							'<div class="yp-field"><label for="yp-fc-extra">Extra charge ($ per item)</label><input type="number" step="0.01" min="0" id="yp-fc-extra" name="extra" value="' + ( parseFloat( meta[ META.extra ] ) || 0 ) + '" />' +
								'<p class="yp-field__hint">Added to the item price each time a customer picks this color for a part. Leave 0 for no charge.</p></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-fc-stock" name="in_stock"' + ( false !== meta[ META.inStock ] ? ' checked' : '' ) + ' /><label for="yp-fc-stock">In stock</label></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-fc-active" name="active"' + ( ! isEdit || 'publish' === color.status ? ' checked' : '' ) + ' /><label for="yp-fc-active">Active (offered to items)</label></div>' +
							'<div class="yp-form__actions">' +
								'<button type="submit" class="wp-block-button__link is-style-accent" data-yp-save>' + ( isEdit ? 'Save changes' : 'Add color' ) + '</button>' +
								'<button type="button" class="wp-block-button__link is-style-outline" data-yp-drawer-close>Cancel</button>' +
							'</div>' +
						'</form>' +
					'</div>' +
				'</div>';

			document.body.appendChild( drawer );
			YP.initDrawer( drawer );
			YP.openDrawer( drawer );

			var form = drawer.querySelector( '[data-yp-form]' );
			form.hex.addEventListener( 'input', function () { form.hex_text.value = form.hex.value; } );
			form.hex_text.addEventListener( 'input', function () {
				if ( /^#[0-9a-f]{6}$/i.test( form.hex_text.value ) ) {
					form.hex.value = form.hex_text.value;
				}
			} );

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				var errorEl = drawer.querySelector( '[data-yp-form-error]' );
				var saveButton = drawer.querySelector( '[data-yp-save]' );
				var name = form.name.value.trim();

				if ( ! name ) {
					errorEl.innerHTML = '<p class="yp-form__error">Name is required.</p>';
					return;
				}

				var body = { title: name, status: form.active.checked ? 'publish' : 'draft', meta: {} };
				body.meta[ META.hex ] = form.hex.value;
				body.meta[ META.finish ] = form.finish.value;
				body.meta[ META.extra ] = Math.max( 0, parseFloat( form.extra.value ) || 0 );
				body.meta[ META.inStock ] = form.in_stock.checked;
				if ( ! isEdit ) {
					body.menu_order = allColors.length;
				}

				errorEl.innerHTML = '';
				saveButton.disabled = true;
				saveButton.textContent = 'Saving…';

				post( isEdit ? color.id : 0, body )
					.then( function () {
						YP.closeDrawer( drawer );
						load();
					} )
					.catch( function ( error ) {
						saveButton.disabled = false;
						saveButton.textContent = isEdit ? 'Save changes' : 'Add color';
						errorEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
					} );
			} );
		}

		rowsEl.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( 'button' );
			if ( ! button || button.disabled ) {
				return;
			}
			if ( button.hasAttribute( 'data-yp-stock' ) ) {
				toggleStock( findById( button.getAttribute( 'data-yp-stock' ) ), button );
			} else if ( button.hasAttribute( 'data-yp-edit' ) ) {
				openForm( findById( button.getAttribute( 'data-yp-edit' ) ) );
			} else if ( button.hasAttribute( 'data-yp-delete' ) ) {
				deleteColor( findById( button.getAttribute( 'data-yp-delete' ) ) );
			} else if ( button.hasAttribute( 'data-yp-move' ) ) {
				move( findById( button.getAttribute( 'data-id' ) ), parseInt( button.getAttribute( 'data-yp-move' ), 10 ) );
			}
		} );

		viewEl.querySelector( '[data-yp-add]' ).addEventListener( 'click', function () { openForm( null ); } );
		searchEl.addEventListener( 'input', renderRows );

		load();
	};
} )();
