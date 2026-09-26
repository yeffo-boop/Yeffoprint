/**
 * Compound List — the names the storefront's compound spell-check
 * compares against (YeffoPrint_Compound_List, theme assets/js/label-
 * proofing.js). One option holding the whole list, so every add, edit
 * or delete here saves the list back in one POST.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	function endpoint() {
		return yeffoprintAdminApp.restUrl + 'admin/compound-list';
	}

	function save( body ) {
		return YP.request( endpoint(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } );
	}

	YP.views[ 'compound-list' ] = function ( viewEl ) {
		var data = null;

		viewEl.innerHTML = '<p class="yp-app__intro">Loading&hellip;</p>';

		function load() {
			YP.request( endpoint() )
				.then( function ( response ) {
					data = response;
					render();
				} )
				.catch( function ( error ) {
					viewEl.innerHTML = '<p class="yp-app__intro">Couldn’t load the compound list: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		function render() {
			viewEl.innerHTML =
				'<p class="yp-app__intro">When a customer types a compound name on a template label or the custom label form, it’s checked against this list. A likely misspelling gets “Did you mean …?”, and so does a name like hGH or hCG typed with the wrong capitals. Customers can always keep what they typed. Add “also typed as” spellings (HGH, BPC157) to have them corrected to the right one.</p>' +
				'<div class="yp-field--checkbox yp-field" style="margin-bottom:1rem;"><input type="checkbox" id="yp-cl-enabled"' + ( data.enabled ? ' checked' : '' ) + ' /><label for="yp-cl-enabled">Spell-check compound names on the site</label></div>' +
				'<div class="yp-list-toolbar">' +
					'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search ' + data.compounds.length + ' compounds&hellip;" />' +
					( data.missing_starter > 0 ? '<button type="button" class="wp-block-button__link is-style-outline" data-yp-starter>Add ' + data.missing_starter + ' missing starter names</button>' : '' ) +
					'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add Compound</button>' +
				'</div>' +
				'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
					'<th>Correct spelling</th><th>Also typed as</th><th>Group</th><th></th>' +
				'</tr></thead><tbody data-yp-rows></tbody></table></div>';

			var searchEl = viewEl.querySelector( '[data-yp-search]' );
			searchEl.addEventListener( 'input', function () { renderRows( searchEl.value ); } );
			viewEl.querySelector( '[data-yp-add]' ).addEventListener( 'click', function () { openForm( -1 ); } );

			var starterButton = viewEl.querySelector( '[data-yp-starter]' );
			if ( starterButton ) {
				starterButton.addEventListener( 'click', function () {
					starterButton.disabled = true;
					save( { add_missing_starter: true } ).then( function ( response ) {
						data = response;
						render();
					} ).catch( function ( error ) {
						starterButton.disabled = false;
						window.alert( 'Couldn’t add them: ' + error.message );
					} );
				} );
			}

			var enabledEl = viewEl.querySelector( '#yp-cl-enabled' );
			enabledEl.addEventListener( 'change', function () {
				enabledEl.disabled = true;
				save( { enabled: enabledEl.checked } ).then( function ( response ) {
					data.enabled = response.enabled;
					enabledEl.disabled = false;
				} ).catch( function ( error ) {
					enabledEl.checked = ! enabledEl.checked;
					enabledEl.disabled = false;
					window.alert( 'Couldn’t save: ' + error.message );
				} );
			} );

			viewEl.querySelector( '[data-yp-rows]' ).addEventListener( 'click', function ( event ) {
				var button = event.target.closest( 'button' );
				if ( ! button ) {
					return;
				}
				var index = parseInt( button.getAttribute( 'data-index' ), 10 );
				if ( button.hasAttribute( 'data-yp-edit' ) ) {
					openForm( index );
				} else if ( button.hasAttribute( 'data-yp-delete' ) ) {
					remove( index );
				}
			} );

			renderRows( '' );
		}

		function renderRows( query ) {
			var rowsEl = viewEl.querySelector( '[data-yp-rows]' );
			query = ( query || '' ).trim().toLowerCase();

			var matches = data.compounds.map( function ( row, index ) {
				return { row: row, index: index };
			} ).filter( function ( item ) {
				return ! query || [ item.row.name, item.row.group ].concat( item.row.aliases ).join( ' ' ).toLowerCase().indexOf( query ) !== -1;
			} );

			if ( ! matches.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="4">' + ( data.compounds.length ? 'No compounds match your search.' : 'No compounds yet. Add the first one above.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = matches.map( function ( item ) {
				return (
					'<tr>' +
						'<td><div class="yp-record-name">' + YP.escapeHtml( item.row.name ) + '</div></td>' +
						'<td>' + ( item.row.aliases.length
							? item.row.aliases.map( function ( alias ) { return '<span class="yp-chip" style="display:inline-block;margin:2px 4px 2px 0;">' + YP.escapeHtml( alias ) + '</span>'; } ).join( '' )
							: '<span class="yp-record-name__sub">&mdash;</span>' ) + '</td>' +
						'<td>' + YP.escapeHtml( item.row.group || '' ) + '</td>' +
						'<td class="yp-row-actions">' +
							'<button type="button" class="yp-row-action" data-yp-edit data-index="' + item.index + '">Edit</button>' +
							'<button type="button" class="yp-row-action" data-yp-delete data-index="' + item.index + '">Delete</button>' +
						'</td>' +
					'</tr>'
				);
			} ).join( '' );
		}

		function saveList( compounds ) {
			return save( { compounds: compounds } ).then( function ( response ) {
				data = response;
				render();
			} );
		}

		function remove( index ) {
			var row = data.compounds[ index ];
			if ( ! row || ! window.confirm( 'Delete "' + row.name + '" from the compound list?' ) ) {
				return;
			}
			var compounds = data.compounds.slice();
			compounds.splice( index, 1 );
			saveList( compounds ).catch( function ( error ) {
				window.alert( 'Couldn’t delete: ' + error.message );
			} );
		}

		function openForm( index ) {
			var row = data.compounds[ index ] || null;
			var isEdit = !! row;
			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--center';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="' + ( isEdit ? 'Edit Compound' : 'Add Compound' ) + '">' +
					'<div class="yp-drawer__header"><span>' + ( isEdit ? 'Edit Compound' : 'Add Compound' ) + '</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<form class="yp-form" data-yp-form>' +
							'<div data-yp-form-error></div>' +
							'<div class="yp-field"><label for="yp-cl-name">Correct spelling</label><input type="text" id="yp-cl-name" name="name" required placeholder="hGH" value="' + ( isEdit ? YP.escapeAttr( row.name ) : '' ) + '" />' +
								'<p class="yp-field__hint">Exactly how it should be printed, capitals and dashes included.</p></div>' +
							'<div class="yp-field"><label for="yp-cl-aliases">Also typed as <span class="yp-field__hint">(optional)</span></label><input type="text" id="yp-cl-aliases" name="aliases" placeholder="HGH, Hgh" value="' + ( isEdit ? YP.escapeAttr( row.aliases.join( ', ' ) ) : '' ) + '" />' +
								'<p class="yp-field__hint">Separate with commas. Customers who type one of these are asked if they meant the correct spelling. Close misspellings are caught without listing them.</p></div>' +
							'<div class="yp-field"><label for="yp-cl-group">Group</label><input type="text" id="yp-cl-group" name="group" list="yp-cl-groups" value="' + ( isEdit ? YP.escapeAttr( row.group || '' ) : '' ) + '" />' +
								'<datalist id="yp-cl-groups">' + data.groups.map( function ( group ) { return '<option value="' + YP.escapeAttr( group ) + '"></option>'; } ).join( '' ) + '</datalist></div>' +
							'<div class="yp-form__actions">' +
								'<button type="submit" class="wp-block-button__link is-style-accent" data-yp-save>' + ( isEdit ? 'Save changes' : 'Add compound' ) + '</button>' +
								'<button type="button" class="wp-block-button__link is-style-outline" data-yp-drawer-close>Cancel</button>' +
							'</div>' +
						'</form>' +
					'</div>' +
				'</div>';

			document.body.appendChild( drawer );
			YP.initDrawer( drawer );
			YP.openDrawer( drawer );

			var form = drawer.querySelector( '[data-yp-form]' );
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				var errorEl = drawer.querySelector( '[data-yp-form-error]' );
				var saveButton = drawer.querySelector( '[data-yp-save]' );
				var name = form.name.value.trim();

				if ( ! name ) {
					errorEl.innerHTML = '<p class="yp-form__error">Correct spelling is required.</p>';
					return;
				}

				var duplicate = data.compounds.some( function ( other, otherIndex ) {
					return otherIndex !== index && other.name.toLowerCase() === name.toLowerCase();
				} );
				if ( duplicate ) {
					errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( name ) + ' is already on the list.</p>';
					return;
				}

				var entry = {
					name: name,
					group: form.group.value.trim(),
					aliases: form.aliases.value.split( ',' ).map( function ( alias ) { return alias.trim(); } ).filter( Boolean )
				};
				var compounds = data.compounds.slice();
				if ( isEdit ) {
					compounds[ index ] = entry;
				} else {
					compounds.push( entry );
				}

				errorEl.innerHTML = '';
				saveButton.disabled = true;
				saveButton.textContent = 'Saving…';

				saveList( compounds )
					.then( function () {
						YP.closeDrawer( drawer );
					} )
					.catch( function ( error ) {
						saveButton.disabled = false;
						saveButton.textContent = isEdit ? 'Save changes' : 'Add compound';
						errorEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
					} );
			} );
		}

		load();
	};
} )();
