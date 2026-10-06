/**
 * Filament Colors — the one shared list every 3D print's color choices
 * pick from (YeffoPrint_Print_Meta). Same CRUD pattern as
 * views/sticker-sizes.js (WP core's own `/wp/v2/yp_filament` route),
 * plus a one-click In stock switch right in the list: running out of a
 * color is the most common edit here, and it greys that color out on
 * every item at once.
 *
 * Each filament can carry a photo (usually the brand's own product
 * shot, pasted in by link) and a focus point + zoom, so the product
 * page's small picker tile shows the filament itself rather than a
 * whole spool with its label.
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
		inStock: '_yp_filament_in_stock',
		brand: '_yp_filament_brand',
		line: '_yp_filament_line',
		image: '_yp_filament_image',
		focus: '_yp_filament_focus'
	};

	/** Same keys and labels as YeffoPrint_Print_Meta::FINISHES. */
	var GROUPS = [
		[ 'solid', 'Solid' ],
		[ 'matte', 'Matte' ],
		[ 'silk', 'Silk' ],
		[ 'specialty', 'Specialty' ]
	];

	function groupLabel( key ) {
		for ( var i = 0; i < GROUPS.length; i++ ) {
			if ( GROUPS[ i ][ 0 ] === key ) {
				return GROUPS[ i ][ 1 ];
			}
		}
		return 'Solid';
	}

	function focusOf( focus ) {
		focus = focus || {};
		return {
			x: isFinite( parseFloat( focus.x ) ) ? parseFloat( focus.x ) : 50,
			y: isFinite( parseFloat( focus.y ) ) ? parseFloat( focus.y ) : 50,
			zoom: Math.max( 100, Math.min( 800, parseFloat( focus.zoom ) || 100 ) )
		};
	}

	function endpoint( path ) {
		return yeffoprintAdminApp.wpApiUrl + 'yp_filament' + ( path || '' );
	}

	/**
	 * Shared with views/prints.js so a filament looks the same in both
	 * screens: its photo zoomed into the focus point (same math as the
	 * product page's tile), or the hex dot when it has no photo.
	 */
	YP.filamentSwatch = function ( hex, finish, size, imageUrl, focus ) {
		var sizeStyle = size ? 'width:' + size + 'px;height:' + size + 'px;' : '';
		if ( imageUrl ) {
			return '<span class="yp-filament-tile" style="' + sizeStyle + 'background-color:' + YP.escapeAttr( hex || '#888888' ) + '">' + YP.filamentPhoto( imageUrl, focus ) + '</span>';
		}
		return '<span class="yp-filament-dot' + ( 'silk' === finish ? ' is-silk' : '' ) + '" style="' + sizeStyle + 'background-color:' + YP.escapeAttr( hex || '#888888' ) + '"></span>';
	};

	YP.filamentPhoto = function ( imageUrl, focus ) {
		var f = focusOf( focus );
		var origin = f.x + '% ' + f.y + '%';
		return '<img src="' + YP.escapeAttr( imageUrl ) + '" alt="" style="object-position:' + origin + ';transform-origin:' + origin + ';transform:scale(' + ( f.zoom / 100 ) + ')" />';
	};

	YP.views[ 'filament-colors' ] = function ( viewEl ) {
		var allColors = [];

		viewEl.innerHTML =
			'<p class="yp-app__intro">Every filament your 3D prints can use. Customers see each one\'s photo, name and brand in the filament picker, grouped as Solid, Matte, Silk or Specialty. Each item picks which of these it offers per part. Turn off In stock when you run out and that filament greys out on the site until you turn it back on.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search filaments&hellip;" />' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add Filament</button>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Filament</th><th>Group</th><th>Extra charge</th><th>In stock</th><th>Status</th><th></th>' +
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
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">Couldn’t load filaments: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows() {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? allColors.filter( function ( c ) {
					var meta = c.meta || {};
					return [ c.title.raw, meta[ META.brand ], meta[ META.line ], groupLabel( meta[ META.finish ] ) ].join( ' ' ).toLowerCase().indexOf( query ) !== -1;
				} )
				: allColors;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">' + ( allColors.length ? 'No filaments match your search.' : 'No filaments yet. Add the first one above.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( color, index ) {
				var meta = color.meta || {};
				var extra = parseFloat( meta[ META.extra ] ) || 0;
				var inStock = false !== meta[ META.inStock ];
				var isPublished = 'publish' === color.status;

				return (
					'<tr data-id="' + color.id + '">' +
						'<td><div class="yp-filament-name">' + YP.filamentSwatch( meta[ META.hex ], meta[ META.finish ], 40, color.filament_image_url, meta[ META.focus ] ) +
							'<div class="yp-record-name">' + YP.escapeHtml( color.title.raw ) + '<div class="yp-record-name__sub">' +
								YP.escapeHtml( [ meta[ META.brand ], meta[ META.line ] ].filter( Boolean ).join( ' · ' ) || meta[ META.hex ] || '' ) +
								( color.filament_image_url ? '' : ' <span class="yp-filament-nophoto">No photo</span>' ) +
							'</div></div></div></td>' +
						'<td>' + groupLabel( meta[ META.finish ] ) + '</td>' +
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
			if ( ! color || ! window.confirm( 'Delete "' + color.title.raw + '"? Items offering it will stop showing it. Past orders keep the filament name.' ) ) {
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
			var finish = meta[ META.finish ] || 'solid';
			var photo = { id: parseInt( meta[ META.image ], 10 ) || 0, url: ( color && color.filament_image_url ) || '' };
			var focus = focusOf( meta[ META.focus ] );
			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--center';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="' + ( isEdit ? 'Edit Filament' : 'Add Filament' ) + '">' +
					'<div class="yp-drawer__header"><span>' + ( isEdit ? 'Edit Filament' : 'Add Filament' ) + '</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<form class="yp-form" data-yp-form>' +
							'<div data-yp-form-error></div>' +
							'<div class="yp-field"><label for="yp-fc-name">Name</label><input type="text" id="yp-fc-name" name="name" required placeholder="Rose Gold Silk" value="' + ( isEdit ? YP.escapeAttr( color.title.raw ) : '' ) + '" /></div>' +
							'<div class="yp-form__row">' +
								'<div class="yp-field"><label for="yp-fc-brand">Brand</label><input type="text" id="yp-fc-brand" name="brand" placeholder="Bambu Lab" value="' + YP.escapeAttr( meta[ META.brand ] || '' ) + '" /></div>' +
								'<div class="yp-field"><label for="yp-fc-line">Type</label><input type="text" id="yp-fc-line" name="line" placeholder="PLA Silk+" value="' + YP.escapeAttr( meta[ META.line ] || '' ) + '" /></div>' +
							'</div>' +
							'<div class="yp-form__row">' +
								'<div class="yp-field"><label for="yp-fc-finish">Group</label><select id="yp-fc-finish" name="finish">' +
									GROUPS.map( function ( g ) {
										return '<option value="' + g[ 0 ] + '"' + ( g[ 0 ] === finish ? ' selected' : '' ) + '>' + g[ 1 ] + '</option>';
									} ).join( '' ) +
								'</select><p class="yp-field__hint">Where it sits in the customer\'s picker. Specialty is for galaxy, carbon fiber, rainbow and the like.</p></div>' +
								'<div class="yp-field"><label for="yp-fc-hex">Swatch color</label>' +
									'<div class="yp-filament-hex"><input type="color" id="yp-fc-hex" name="hex" value="' + YP.escapeAttr( hex ) + '" /><input type="text" name="hex_text" value="' + YP.escapeAttr( hex ) + '" maxlength="7" aria-label="Hex code" /></div>' +
									'<p class="yp-field__hint">Used when there\'s no photo, and for the little dot on the item photo.</p>' +
								'</div>' +
							'</div>' +
							'<div class="yp-field yp-filament-photo">' +
								'<label>Photo</label>' +
								'<div class="yp-filament-photo__row">' +
									'<div class="yp-filament-photo__full" data-yp-photo-full title="Click the spot that shows the filament best"></div>' +
									'<div class="yp-filament-photo__side">' +
										'<div class="yp-filament-photo__preview-label">Customers see</div>' +
										'<div class="yp-filament-photo__preview" data-yp-photo-preview></div>' +
										'<label class="yp-filament-photo__zoom">Zoom <input type="range" min="100" max="800" step="10" data-yp-zoom value="' + focus.zoom + '" /></label>' +
									'</div>' +
								'</div>' +
								'<p class="yp-field__hint" data-yp-photo-hint></p>' +
								'<div class="yp-filament-photo__link">' +
									'<input type="url" placeholder="Paste image link" data-yp-photo-url aria-label="Image link" />' +
									'<button type="button" class="wp-block-button__link is-style-outline" data-yp-photo-import>Get image</button>' +
								'</div>' +
								'<p class="yp-field__hint">On the brand\'s product page, right-click the filament photo and pick "Copy image address", then paste it here. Or <button type="button" class="yp-link-button" data-yp-photo-pick>upload one</button><span data-yp-photo-remove-wrap> &middot; <button type="button" class="yp-link-button" data-yp-photo-remove>remove photo</button></span>.</p>' +
							'</div>' +
							'<div class="yp-field"><label for="yp-fc-extra">Extra charge ($ per item)</label><input type="number" step="0.01" min="0" id="yp-fc-extra" name="extra" value="' + ( parseFloat( meta[ META.extra ] ) || 0 ) + '" />' +
								'<p class="yp-field__hint">Added to the item price each time a customer picks this filament for a part. Leave 0 for no charge.</p></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-fc-stock" name="in_stock"' + ( false !== meta[ META.inStock ] ? ' checked' : '' ) + ' /><label for="yp-fc-stock">In stock</label></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-fc-active" name="active"' + ( ! isEdit || 'publish' === color.status ? ' checked' : '' ) + ' /><label for="yp-fc-active">Active (offered to items)</label></div>' +
							'<div class="yp-form__actions">' +
								'<button type="submit" class="wp-block-button__link is-style-accent" data-yp-save>' + ( isEdit ? 'Save changes' : 'Add filament' ) + '</button>' +
								'<button type="button" class="wp-block-button__link is-style-outline" data-yp-drawer-close>Cancel</button>' +
							'</div>' +
						'</form>' +
					'</div>' +
				'</div>';

			document.body.appendChild( drawer );
			YP.initDrawer( drawer );
			YP.openDrawer( drawer );

			var form = drawer.querySelector( '[data-yp-form]' );
			var fullEl = drawer.querySelector( '[data-yp-photo-full]' );
			var previewEl = drawer.querySelector( '[data-yp-photo-preview]' );
			var zoomEl = drawer.querySelector( '[data-yp-zoom]' );
			var hintEl = drawer.querySelector( '[data-yp-photo-hint]' );
			var urlEl = drawer.querySelector( '[data-yp-photo-url]' );
			var importButton = drawer.querySelector( '[data-yp-photo-import]' );
			var errorEl = drawer.querySelector( '[data-yp-form-error]' );

			function renderPhoto() {
				var swatchHex = form.hex.value;
				if ( photo.url ) {
					fullEl.innerHTML = '<img src="' + YP.escapeAttr( photo.url ) + '" alt="" />' +
						'<span class="yp-filament-photo__marker" style="left:' + focus.x + '%;top:' + focus.y + '%"></span>';
					fullEl.classList.remove( 'is-empty' );
					hintEl.textContent = 'Click the photo on the spot that shows the filament best, then zoom in until the preview is mostly filament.';
				} else {
					fullEl.innerHTML = '<span>No photo yet</span>';
					fullEl.classList.add( 'is-empty' );
					hintEl.textContent = 'No photo: customers see the swatch color instead.';
				}
				previewEl.innerHTML = YP.filamentSwatch( swatchHex, form.finish.value, 0, photo.url, focus );
				zoomEl.disabled = ! photo.url;
				drawer.querySelector( '[data-yp-photo-remove-wrap]' ).hidden = ! photo.url;
			}

			// The marker sits on the whole photo, where the click landed.
			fullEl.addEventListener( 'click', function ( event ) {
				var img = fullEl.querySelector( 'img' );
				if ( ! img ) {
					return;
				}
				var rect = img.getBoundingClientRect();
				focus.x = Math.round( Math.max( 0, Math.min( 1, ( event.clientX - rect.left ) / rect.width ) ) * 1000 ) / 10;
				focus.y = Math.round( Math.max( 0, Math.min( 1, ( event.clientY - rect.top ) / rect.height ) ) * 1000 ) / 10;
				// A first click on a fresh photo usually wants a close-up.
				if ( focus.zoom <= 100 ) {
					focus.zoom = 300;
					zoomEl.value = 300;
				}
				renderPhoto();
			} );

			zoomEl.addEventListener( 'input', function () {
				focus.zoom = parseInt( zoomEl.value, 10 ) || 100;
				renderPhoto();
			} );

			function usePhoto( id, url ) {
				photo = { id: id, url: url };
				focus = { x: 50, y: 50, zoom: 100 };
				zoomEl.value = 100;
				renderPhoto();
			}

			importButton.addEventListener( 'click', function () {
				var link = urlEl.value.trim();
				if ( ! link ) {
					urlEl.focus();
					return;
				}
				importButton.disabled = true;
				importButton.textContent = 'Getting…';
				errorEl.innerHTML = '';
				YP.request( yeffoprintAdminApp.restUrl + 'admin/filament-image', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { url: link, name: [ form.brand.value, form.name.value ].filter( Boolean ).join( ' ' ) } )
				} )
					.then( function ( result ) {
						urlEl.value = '';
						usePhoto( result.id, result.url );
					} )
					.catch( function ( error ) {
						errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
					} )
					.then( function () {
						importButton.disabled = false;
						importButton.textContent = 'Get image';
					} );
			} );
			urlEl.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					importButton.click();
				}
			} );

			drawer.querySelector( '[data-yp-photo-pick]' ).addEventListener( 'click', function () {
				if ( typeof wp === 'undefined' || ! wp.media ) {
					return;
				}
				var frame = wp.media( { title: 'Filament photo', multiple: false, library: { type: 'image' }, button: { text: 'Use this photo' } } );
				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					var sized = attachment.sizes && ( attachment.sizes.medium_large || attachment.sizes.large || attachment.sizes.medium );
					usePhoto( attachment.id, ( sized && sized.url ) || attachment.url );
				} );
				frame.open();
			} );

			drawer.querySelector( '[data-yp-photo-remove]' ).addEventListener( 'click', function () {
				usePhoto( 0, '' );
			} );

			form.hex.addEventListener( 'input', function () {
				form.hex_text.value = form.hex.value;
				renderPhoto();
			} );
			form.hex_text.addEventListener( 'input', function () {
				if ( /^#[0-9a-f]{6}$/i.test( form.hex_text.value ) ) {
					form.hex.value = form.hex_text.value;
					renderPhoto();
				}
			} );
			form.finish.addEventListener( 'change', renderPhoto );
			renderPhoto();

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
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
				body.meta[ META.brand ] = form.brand.value.trim();
				body.meta[ META.line ] = form.line.value.trim();
				body.meta[ META.image ] = photo.id;
				body.meta[ META.focus ] = focus;
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
						saveButton.textContent = isEdit ? 'Save changes' : 'Add filament';
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
