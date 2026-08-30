/**
 * Materials — the first real CRUD screen (docs/ARCHITECTURE.md, Phase
 * 2). Reads/writes straight through WordPress core's own
 * `/wp/v2/yp_material` REST route rather than a new PHP controller —
 * the post type and every field used here already has `show_in_rest`
 * on (class-post-type-registry.php, class-commerce-record-meta.php),
 * so there's nothing this plugin needs to add server-side for this
 * screen specifically. Sizes (views/sizes.js) is the same pattern with
 * fewer fields.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var META = {
		priceAdjustment: '_yp_price_adjustment',
		hoverImage: '_yp_hover_image_id',
		thickness: '_yp_thickness_mil',
		scope: '_yp_material_scope',
			inStock: '_yp_in_stock',
		guideNote: '_yp_guide_note'
	};

	var SCOPES = {
		label: 'Labels',
		sticker: 'Stickers',
		both: 'Labels & Stickers'
	};

	function endpoint( path ) {
		return yeffoprintAdminApp.wpApiUrl + 'yp_material' + ( path || '' );
	}

	function formatMoney( amount ) {
		var value = parseFloat( amount ) || 0;
		return ( value >= 0 ? '+' : '' ) + '$' + value.toFixed( 2 );
	}

	YP.views.materials = function ( viewEl ) {
		var allMaterials = [];

		viewEl.innerHTML =
			'<p class="yp-app__intro">Materials offered for labels and stickers — swatch photo, price adjustment, and which product line each one applies to.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search materials&hellip;" />' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add Material</button>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Material</th><th>Scope</th><th>Thickness</th><th>Price adj.</th><th>Status</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="6">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl = viewEl.querySelector( '[data-yp-search]' );

		function load() {
			rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">Loading&hellip;</td></tr>';
			YP.request( endpoint( '?context=edit&status=publish,draft&per_page=100&orderby=menu_order&order=asc&_embed=1' ) )
				.then( function ( materials ) {
					allMaterials = materials || [];
					renderRows( allMaterials );
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">Couldn’t load materials: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows( materials ) {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? materials.filter( function ( m ) { return m.title.raw.toLowerCase().indexOf( query ) !== -1; } )
				: materials;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">' + ( materials.length ? 'No materials match your search.' : 'No materials yet — add the first one above.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( material, index ) {
				var thumb = material._embedded && material._embedded[ 'wp:featuredmedia' ] && material._embedded[ 'wp:featuredmedia' ][ 0 ]
					? material._embedded[ 'wp:featuredmedia' ][ 0 ].source_url
					: '';
				var scope = material.meta && material.meta[ META.scope ] ? material.meta[ META.scope ] : 'label';
				var thickness = material.meta ? parseFloat( material.meta[ META.thickness ] ) || 0 : 0;
				var priceAdjustment = material.meta ? parseFloat( material.meta[ META.priceAdjustment ] ) || 0 : 0;
				var isPublished = 'publish' === material.status;
				var isInStock = ! material.meta || false !== material.meta[ META.inStock ];

				return (
					'<tr data-id="' + material.id + '">' +
						'<td><div class="yp-record-name">' +
							( thumb ? '<img class="yp-swatch" src="' + YP.escapeAttr( thumb ) + '" alt="" />' : '<span class="yp-swatch"></span>' ) +
							YP.escapeHtml( material.title.raw ) +
						'</div></td>' +
						'<td><span class="yp-chip">' + YP.escapeHtml( SCOPES[ scope ] || scope ) + '</span></td>' +
						'<td><span class="yp-chip">' + ( thickness ? thickness + ' mil' : '&mdash;' ) + '</span></td>' +
						'<td><span class="yp-chip">' + ( priceAdjustment ? formatMoney( priceAdjustment ) : 'No adjustment' ) + '</span></td>' +
						'<td>' +
							'<span class="yp-pill ' + ( isPublished ? 'yp-pill--good' : 'yp-pill--neutral' ) + '">' + ( isPublished ? 'Active' : 'Draft' ) + '</span> ' +
							( isInStock ? '' : '<span class="yp-pill yp-pill--crit">Out of Stock</span>' ) +
						'</td>' +
						'<td class="yp-row-actions">' +
							'<button type="button" class="yp-row-action" data-yp-move-up="' + material.id + '" ' + ( 0 === index ? 'disabled' : '' ) + ' aria-label="Move up">&uarr;</button>' +
							'<button type="button" class="yp-row-action" data-yp-move-down="' + material.id + '" ' + ( index === filtered.length - 1 ? 'disabled' : '' ) + ' aria-label="Move down">&darr;</button>' +
							'<button type="button" class="yp-row-action" data-yp-edit="' + material.id + '" aria-label="Edit">Edit</button>' +
							'<button type="button" class="yp-row-action" data-yp-delete="' + material.id + '" aria-label="Delete">Delete</button>' +
						'</td>' +
					'</tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-edit]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { openForm( findById( button.getAttribute( 'data-yp-edit' ) ) ); } );
			} );
			rowsEl.querySelectorAll( '[data-yp-delete]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { deleteMaterial( findById( button.getAttribute( 'data-yp-delete' ) ) ); } );
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
			for ( var i = 0; i < allMaterials.length; i++ ) {
				if ( allMaterials[ i ].id === id ) {
					return allMaterials[ i ];
				}
			}
			return null;
		}

		/**
		 * Swapping two items' menu_order values silently does nothing once
		 * they're already equal — which every material created here was,
		 * since save() below never set menu_order on creation, defaulting
		 * new records to 0 (same bug, same fix, as web-design-packages.js's
		 * move()). Renumbering the whole list to match the new visual
		 * order avoids that failure mode entirely and self-heals any
		 * existing ties the moment a material is moved.
		 */
		function move( material, direction ) {
			var index = allMaterials.indexOf( material );
			var swapIndex = index + direction;
			if ( ! material || swapIndex < 0 || swapIndex >= allMaterials.length ) {
				return;
			}

			var reordered = allMaterials.slice();
			reordered.splice( index, 1 );
			reordered.splice( swapIndex, 0, material );

			var updates = [];
			reordered.forEach( function ( m, i ) {
				if ( m.menu_order !== i ) {
					updates.push( YP.request( endpoint( '/' + m.id ), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { menu_order: i } ) } ) );
				}
			} );

			Promise.all( updates ).then( load ).catch( function ( error ) {
				window.alert( 'Couldn’t reorder: ' + error.message );
			} );
		}

		function deleteMaterial( material ) {
			if ( ! material || ! window.confirm( 'Delete "' + material.title.raw + '"? This moves it to Trash — it can be restored from Materials → Trash in wp-admin if needed.' ) ) {
				return;
			}
			YP.request( endpoint( '/' + material.id ), { method: 'DELETE' } )
				.then( load )
				.catch( function ( error ) {
					window.alert( 'Couldn’t delete: ' + error.message );
				} );
		}

		/* ---------- Add/Edit drawer ---------- */

		function openForm( material ) {
			var isEdit = !! material;
			var meta = ( material && material.meta ) || {};
			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--center';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="' + ( isEdit ? 'Edit Material' : 'Add Material' ) + '">' +
					'<div class="yp-drawer__header"><span>' + ( isEdit ? 'Edit Material' : 'Add Material' ) + '</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<form class="yp-form" data-yp-form>' +
							'<div data-yp-form-error></div>' +
							'<div class="yp-field"><label for="yp-mat-name">Name</label><input type="text" id="yp-mat-name" name="name" required value="' + ( isEdit ? YP.escapeAttr( material.title.raw ) : '' ) + '" /></div>' +
							'<div class="yp-field"><label for="yp-mat-desc">Description</label><textarea id="yp-mat-desc" name="description" placeholder="Shown on the Material Guide (optional)">' + ( isEdit ? YP.escapeHtml( material.content.raw ) : '' ) + '</textarea></div>' +
							'<div class="yp-field"><label for="yp-mat-guide-note">Guide note</label><textarea id="yp-mat-guide-note" name="guide_note" placeholder="A short caution/logistics note shown under the description on the Material Guide, e.g. a shipping-delay warning (optional)">' + ( isEdit ? YP.escapeHtml( meta[ META.guideNote ] || '' ) : '' ) + '</textarea></div>' +
							'<div class="yp-form__row">' +
								'<div class="yp-field"><label for="yp-mat-scope">Scope</label><select id="yp-mat-scope" name="scope">' +
									Object.keys( SCOPES ).map( function ( key ) {
										return '<option value="' + key + '"' + ( ( meta[ META.scope ] || 'label' ) === key ? ' selected' : '' ) + '>' + SCOPES[ key ] + '</option>';
									} ).join( '' ) +
								'</select></div>' +
								'<div class="yp-field"><label for="yp-mat-thickness">Thickness (mil)</label><input type="number" step="0.01" min="0" id="yp-mat-thickness" name="thickness" value="' + ( meta[ META.thickness ] || '' ) + '" /></div>' +
							'</div>' +
							'<div class="yp-field"><label for="yp-mat-price">Price adjustment ($, per label)</label><input type="number" step="0.01" id="yp-mat-price" name="price_adjustment" value="' + ( meta[ META.priceAdjustment ] || '0' ) + '" /></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-mat-active" name="active"' + ( ! isEdit || 'publish' === material.status ? ' checked' : '' ) + ' /><label for="yp-mat-active">Active (visible to customers)</label></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-mat-in-stock" name="in_stock"' + ( ! isEdit || false !== meta[ META.inStock ] ? ' checked' : '' ) + ' /><label for="yp-mat-in-stock">In stock</label></div>' +
							'<p class="yp-field__hint">Unchecking this keeps the material visible everywhere it already shows (configurator, forms, guide) with an "Out of Stock" label, but customers can’t select it until it’s checked again. Different from Active above — Active/Draft removes it from the site entirely.</p>' +
							'<div class="yp-field"><label>Swatch image</label>' +
								'<div class="yp-media-field">' +
									'<div class="yp-media-field__preview" data-yp-swatch-preview>' + ( isEdit && material._embedded && material._embedded[ 'wp:featuredmedia' ] && material._embedded[ 'wp:featuredmedia' ][ 0 ] ? '<img src="' + YP.escapeAttr( material._embedded[ 'wp:featuredmedia' ][ 0 ].source_url ) + '" alt="" />' : '' ) + '</div>' +
									'<div class="yp-media-field__buttons">' +
										'<input type="hidden" name="featured_media" data-yp-swatch-id value="' + ( isEdit ? material.featured_media || '' : '' ) + '" />' +
										'<button type="button" class="wp-block-button__link is-style-outline" data-yp-swatch-select>Select image</button>' +
										'<button type="button" class="yp-row-action" data-yp-swatch-remove ' + ( isEdit && material.featured_media ? '' : 'hidden' ) + '>Remove</button>' +
									'</div>' +
								'</div>' +
							'</div>' +
							'<div class="yp-field"><label>Hover / on-vial image</label>' +
								'<p class="yp-field__hint">Shown on hover wherever this material’s swatch appears — a photo of the finish actually applied to a vial.</p>' +
								'<div class="yp-media-field">' +
									'<div class="yp-media-field__preview" data-yp-hover-preview></div>' +
									'<div class="yp-media-field__buttons">' +
										'<input type="hidden" name="hover_image" data-yp-hover-id value="' + ( meta[ META.hoverImage ] || '' ) + '" />' +
										'<button type="button" class="wp-block-button__link is-style-outline" data-yp-hover-select>Select image</button>' +
										'<button type="button" class="yp-row-action" data-yp-hover-remove ' + ( meta[ META.hoverImage ] ? '' : 'hidden' ) + '>Remove</button>' +
									'</div>' +
								'</div>' +
							'</div>' +
							'<div class="yp-form__actions">' +
								'<button type="submit" class="wp-block-button__link is-style-accent" data-yp-save>' + ( isEdit ? 'Save changes' : 'Add material' ) + '</button>' +
								'<button type="button" class="wp-block-button__link is-style-outline" data-yp-drawer-close>Cancel</button>' +
							'</div>' +
						'</form>' +
					'</div>' +
				'</div>';

			document.body.appendChild( drawer );
			YP.initDrawer( drawer );
			YP.openDrawer( drawer );

			YP.bindMediaPicker( {
				title: 'Select swatch image',
				selectButton: drawer.querySelector( '[data-yp-swatch-select]' ),
				removeButton: drawer.querySelector( '[data-yp-swatch-remove]' ),
				idInput: drawer.querySelector( '[data-yp-swatch-id]' ),
				preview: drawer.querySelector( '[data-yp-swatch-preview]' )
			} );
			YP.bindMediaPicker( {
				title: 'Select hover image',
				selectButton: drawer.querySelector( '[data-yp-hover-select]' ),
				removeButton: drawer.querySelector( '[data-yp-hover-remove]' ),
				idInput: drawer.querySelector( '[data-yp-hover-id]' ),
				preview: drawer.querySelector( '[data-yp-hover-preview]' )
			} );

			// The hover-image field only has a meta attachment ID to start
			// from, not a URL (unlike the swatch, which core REST embeds
			// via featured_media) — fetch that one attachment's URL to
			// populate the preview when opening an existing record that has
			// one set.
			var hoverId = parseInt( meta[ META.hoverImage ], 10 ) || 0;
			if ( hoverId ) {
				YP.request( yeffoprintAdminApp.wpApiUrl + 'media/' + hoverId )
					.then( function ( attachment ) {
						var preview = drawer.querySelector( '[data-yp-hover-preview]' );
						if ( preview && attachment && attachment.source_url ) {
							preview.innerHTML = '<img src="' + YP.escapeAttr( attachment.source_url ) + '" alt="" />';
						}
					} )
					.catch( function () {} );
			}

			drawer.querySelector( '[data-yp-form]' ).addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				save( material, drawer );
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
				content: form.description.value,
				status: form.active.checked ? 'publish' : 'draft',
				featured_media: parseInt( form.featured_media.value, 10 ) || 0,
				meta: {}
			};
			body.meta[ META.scope ] = form.scope.value;
			body.meta[ META.thickness ] = parseFloat( form.thickness.value ) || 0;
			body.meta[ META.priceAdjustment ] = parseFloat( form.price_adjustment.value ) || 0;
			body.meta[ META.hoverImage ] = parseInt( form.hover_image.value, 10 ) || 0;
			body.meta[ META.inStock ] = form.in_stock.checked;
			body.meta[ META.guideNote ] = form.guide_note.value;
			if ( ! existing ) {
				body.menu_order = allMaterials.length; // New materials land at the end of the list, not menu_order 0 (see move()'s docblock above).
			}

			var url = existing ? endpoint( '/' + existing.id ) : endpoint();

			YP.request( url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } )
				.then( function () {
					YP.closeDrawer( drawer );
					load();
				} )
				.catch( function ( error ) {
					saveButton.disabled = false;
					saveButton.textContent = existing ? 'Save changes' : 'Add material';
					errorEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		viewEl.querySelector( '[data-yp-add]' ).addEventListener( 'click', function () { openForm( null ); } );
		searchEl.addEventListener( 'input', function () { renderRows( allMaterials ); } );

		load();
	};
} )();
