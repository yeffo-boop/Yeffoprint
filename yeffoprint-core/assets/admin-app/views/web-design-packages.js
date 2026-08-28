/**
 * Web Design Packages — same CRUD pattern as views/materials.js, via
 * WP core's own `/wp/v2/yp_web_design_pkg` REST route. The one field
 * that needed a small data-layer change first: `_yp_features` (the
 * bullet list) wasn't REST-registered until this phase — see
 * class-web-design-package-meta.php.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var META = {
		price: '_yp_price',
		tagline: '_yp_tagline',
		featured: '_yp_featured',
		features: '_yp_features'
	};

	function endpoint( path ) {
		return yeffoprintAdminApp.wpApiUrl + 'yp_web_design_pkg' + ( path || '' );
	}

	YP.views[ 'web-design-packages' ] = function ( viewEl ) {
		var allPackages = [];

		viewEl.innerHTML =
			'<p class="yp-app__intro">Tiers on the Web Design page’s pricing table — price, tagline, and what’s included in each.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search packages&hellip;" />' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add Package</button>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Package</th><th>Price</th><th>Tagline</th><th>Featured</th><th>Status</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="6">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl = viewEl.querySelector( '[data-yp-search]' );

		function load() {
			rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">Loading&hellip;</td></tr>';
			YP.request( endpoint( '?context=edit&status=publish,draft&per_page=100&orderby=menu_order&order=asc' ) )
				.then( function ( packages ) {
					allPackages = packages || [];
					renderRows( allPackages );
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">Couldn’t load packages: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows( packages ) {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? packages.filter( function ( p ) { return p.title.raw.toLowerCase().indexOf( query ) !== -1; } )
				: packages;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">' + ( packages.length ? 'No packages match your search.' : 'No packages yet — add the first one above.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( pkg, index ) {
				var price = pkg.meta ? ( pkg.meta[ META.price ] || '&mdash;' ) : '&mdash;';
				var tagline = pkg.meta ? ( pkg.meta[ META.tagline ] || '' ) : '';
				var isFeatured = !! ( pkg.meta && pkg.meta[ META.featured ] );
				var isPublished = 'publish' === pkg.status;

				return (
					'<tr data-id="' + pkg.id + '">' +
						'<td><div class="yp-record-name">' + YP.escapeHtml( pkg.title.raw ) + '</div></td>' +
						'<td><span class="yp-chip">' + YP.escapeHtml( price ) + '</span></td>' +
						'<td>' + ( tagline ? YP.escapeHtml( tagline ) : '<span class="yp-chip">&mdash;</span>' ) + '</td>' +
						'<td>' + ( isFeatured ? '<span class="yp-pill yp-pill--good">Featured</span>' : '&mdash;' ) + '</td>' +
						'<td><span class="yp-pill ' + ( isPublished ? 'yp-pill--good' : 'yp-pill--neutral' ) + '">' + ( isPublished ? 'Active' : 'Draft' ) + '</span></td>' +
						'<td class="yp-row-actions">' +
							'<button type="button" class="yp-row-action" data-yp-move-up="' + pkg.id + '" ' + ( 0 === index ? 'disabled' : '' ) + ' aria-label="Move up">&uarr;</button>' +
							'<button type="button" class="yp-row-action" data-yp-move-down="' + pkg.id + '" ' + ( index === filtered.length - 1 ? 'disabled' : '' ) + ' aria-label="Move down">&darr;</button>' +
							'<button type="button" class="yp-row-action" data-yp-edit="' + pkg.id + '" aria-label="Edit">Edit</button>' +
							'<button type="button" class="yp-row-action" data-yp-delete="' + pkg.id + '" aria-label="Delete">Delete</button>' +
						'</td>' +
					'</tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-edit]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { openForm( findById( button.getAttribute( 'data-yp-edit' ) ) ); } );
			} );
			rowsEl.querySelectorAll( '[data-yp-delete]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { deletePackage( findById( button.getAttribute( 'data-yp-delete' ) ) ); } );
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
			for ( var i = 0; i < allPackages.length; i++ ) {
				if ( allPackages[ i ].id === id ) {
					return allPackages[ i ];
				}
			}
			return null;
		}

		/**
		 * Direct bug report: "the button is there, I can click it, but it
		 * doesn't actually move." Root cause: save() below never set
		 * menu_order when creating a package, so every package added
		 * through this screen defaulted to menu_order 0 — swapping two
		 * packages' menu_order values is a no-op once they're already
		 * equal, which silently succeeds (no error) but changes nothing.
		 * Renumbering the whole list to match the new visual order (every
		 * item's menu_order set to its own array index), rather than
		 * swapping two values, fixes that and self-heals any existing ties
		 * the moment a package is moved — no separate migration needed.
		 */
		function move( pkg, direction ) {
			var index = allPackages.indexOf( pkg );
			var swapIndex = index + direction;
			if ( ! pkg || swapIndex < 0 || swapIndex >= allPackages.length ) {
				return;
			}

			var reordered = allPackages.slice();
			reordered.splice( index, 1 );
			reordered.splice( swapIndex, 0, pkg );

			var updates = [];
			reordered.forEach( function ( p, i ) {
				if ( p.menu_order !== i ) {
					updates.push( YP.request( endpoint( '/' + p.id ), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { menu_order: i } ) } ) );
				}
			} );

			Promise.all( updates ).then( load ).catch( function ( error ) {
				window.alert( 'Couldn’t reorder: ' + error.message );
			} );
		}

		function deletePackage( pkg ) {
			if ( ! pkg || ! window.confirm( 'Delete "' + pkg.title.raw + '"? This moves it to Trash — it can be restored from Web Design Packages → Trash in wp-admin if needed.' ) ) {
				return;
			}
			YP.request( endpoint( '/' + pkg.id ), { method: 'DELETE' } )
				.then( load )
				.catch( function ( error ) {
					window.alert( 'Couldn’t delete: ' + error.message );
				} );
		}

		/* ---------- Add/Edit drawer ---------- */

		function openForm( pkg ) {
			var isEdit = !! pkg;
			var meta = ( pkg && pkg.meta ) || {};
			var features = ( meta[ META.features ] || [] ).join( '\n' );
			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--center';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="' + ( isEdit ? 'Edit Package' : 'Add Package' ) + '">' +
					'<div class="yp-drawer__header"><span>' + ( isEdit ? 'Edit Package' : 'Add Package' ) + '</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<form class="yp-form" data-yp-form>' +
							'<div data-yp-form-error></div>' +
							'<div class="yp-field"><label for="yp-pkg-name">Name</label><input type="text" id="yp-pkg-name" name="name" required value="' + ( isEdit ? YP.escapeAttr( pkg.title.raw ) : '' ) + '" placeholder="e.g. Growth" /></div>' +
							'<div class="yp-form__row">' +
								'<div class="yp-field"><label for="yp-pkg-price">Price</label><input type="text" id="yp-pkg-price" name="price" value="' + YP.escapeAttr( meta[ META.price ] || '' ) + '" placeholder="$1,500" /></div>' +
								'<div class="yp-field"><label for="yp-pkg-tagline">Tagline</label><input type="text" id="yp-pkg-tagline" name="tagline" value="' + YP.escapeAttr( meta[ META.tagline ] || '' ) + '" placeholder="Best for most businesses" /></div>' +
							'</div>' +
							'<div class="yp-field"><label for="yp-pkg-features">Features</label><textarea id="yp-pkg-features" name="features" placeholder="One per line">' + YP.escapeHtml( features ) + '</textarea>' +
								'<p class="yp-field__hint">One bullet per line — shown in this order on the pricing card.</p>' +
							'</div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-pkg-featured" name="featured"' + ( meta[ META.featured ] ? ' checked' : '' ) + ' /><label for="yp-pkg-featured">Featured ("Most Popular" tag)</label></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-pkg-active" name="active"' + ( ! isEdit || 'publish' === pkg.status ? ' checked' : '' ) + ' /><label for="yp-pkg-active">Active (visible to customers)</label></div>' +
							'<div class="yp-form__actions">' +
								'<button type="submit" class="wp-block-button__link is-style-accent" data-yp-save>' + ( isEdit ? 'Save changes' : 'Add package' ) + '</button>' +
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
				save( pkg, drawer );
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
			body.meta[ META.tagline ] = form.tagline.value.trim();
			body.meta[ META.featured ] = form.featured.checked;
			body.meta[ META.features ] = form.features.value.split( '\n' ).map( function ( line ) { return line.trim(); } ).filter( function ( line ) { return line.length; } );
			if ( ! existing ) {
				body.menu_order = allPackages.length; // New packages land at the end of the list, not menu_order 0 (see move()'s docblock above).
			}

			var url = existing ? endpoint( '/' + existing.id ) : endpoint();

			YP.request( url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } )
				.then( function () {
					YP.closeDrawer( drawer );
					load();
				} )
				.catch( function ( error ) {
					saveButton.disabled = false;
					saveButton.textContent = existing ? 'Save changes' : 'Add package';
					errorEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		viewEl.querySelector( '[data-yp-add]' ).addEventListener( 'click', function () { openForm( null ); } );
		searchEl.addEventListener( 'input', function () { renderRows( allPackages ); } );

		load();
	};
} )();
