/**
 * Label Colors — the one shared list every label Template's color
 * choices pick from (YeffoPrint_Label_Color_Meta). Same CRUD pattern as
 * views/filament-colors.js (WP core's own `/wp/v2/yp_label_color`
 * route), minus stock and extra charges: a label color is printed ink,
 * so it never runs out or costs more.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var HEX_META = '_yp_label_color_hex';

	function endpoint( path ) {
		return yeffoprintAdminApp.wpApiUrl + 'yp_label_color' + ( path || '' );
	}

	YP.views[ 'label-colors' ] = function ( viewEl ) {
		var allColors = [];

		viewEl.innerHTML =
			'<p class="yp-app__intro">Every color customers can pick for a label’s background, text or artwork parts. Every Template’s color choices offer all of these, plus an Any color picker. A Draft color stops showing everywhere at once.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search colors&hellip;" />' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add Color</button>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Color</th><th>Status</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="3">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl = viewEl.querySelector( '[data-yp-search]' );

		function load() {
			YP.request( endpoint( '?context=edit&status=publish,draft&per_page=100&orderby=menu_order&order=asc' ) )
				.then( function ( colors ) {
					allColors = colors || [];
					renderRows();
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="3">Couldn’t load colors: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows() {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? allColors.filter( function ( c ) { return c.title.raw.toLowerCase().indexOf( query ) !== -1; } )
				: allColors;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="3">' + ( allColors.length ? 'No colors match your search.' : 'No colors yet. Add the first one above.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( color, index ) {
				var hex = ( color.meta || {} )[ HEX_META ] || '';
				var isPublished = 'publish' === color.status;

				return (
					'<tr data-id="' + color.id + '">' +
						'<td><div class="yp-filament-name">' + YP.filamentSwatch( hex, 'matte', 28 ) +
							'<div class="yp-record-name">' + YP.escapeHtml( color.title.raw ) + '<div class="yp-record-name__sub">' + YP.escapeHtml( hex ) + '</div></div></div></td>' +
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
			if ( ! color || ! window.confirm( 'Delete "' + color.title.raw + '"? Templates offering it will stop showing it. Past orders keep the color name.' ) ) {
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
			var hex = ( ( color && color.meta ) || {} )[ HEX_META ] || '#141414';
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
							'<div class="yp-field"><label for="yp-lc-name">Name</label><input type="text" id="yp-lc-name" name="name" required placeholder="Gold" value="' + ( isEdit ? YP.escapeAttr( color.title.raw ) : '' ) + '" /></div>' +
							'<div class="yp-field"><label for="yp-lc-hex">Color</label>' +
								'<div class="yp-filament-hex"><input type="color" id="yp-lc-hex" name="hex" value="' + YP.escapeAttr( hex.toLowerCase() ) + '" /><input type="text" name="hex_text" value="' + YP.escapeAttr( hex ) + '" maxlength="7" aria-label="Hex code" /></div>' +
							'</div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-lc-active" name="active"' + ( ! isEdit || 'publish' === color.status ? ' checked' : '' ) + ' /><label for="yp-lc-active">Active (offered to templates)</label></div>' +
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
			form.hex.addEventListener( 'input', function () { form.hex_text.value = form.hex.value.toUpperCase(); } );
			form.hex_text.addEventListener( 'input', function () {
				if ( /^#[0-9a-f]{6}$/i.test( form.hex_text.value ) ) {
					form.hex.value = form.hex_text.value.toLowerCase();
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
				body.meta[ HEX_META ] = form.hex.value;
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
			if ( button.hasAttribute( 'data-yp-edit' ) ) {
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
