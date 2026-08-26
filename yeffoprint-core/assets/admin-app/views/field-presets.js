/**
 * Field Presets — reusable sets of customization fields an admin can
 * insert into any Template instead of recreating the same fields from
 * scratch each time (docs/ARCHITECTURE.md, Phase 5; direct request via
 * class-field-preset-editor.php: "creating them one by one every time
 * is a lot"). `yp_field_preset` itself (title, status) reads/writes
 * through WP core's own `/wp/v2/yp_field_preset` route; field_schema —
 * the only real content here — goes through the new
 * `/admin/field-preset/{id}` endpoint (class-admin-field-preset-controller.php),
 * same two-call save shape as views/templates.js.
 *
 * Reuses the exact same field-schema-editor.js repeater as Templates,
 * just with no previewImageUrl — a preset has no artwork of its own,
 * so the widget falls back to its existing "no preview" empty state
 * (position still gets set per-Template after inserting a preset,
 * against that Template's own artwork).
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	function endpoint( path ) {
		return yeffoprintAdminApp.wpApiUrl + 'yp_field_preset' + ( path || '' );
	}

	function adminEndpoint( id ) {
		return yeffoprintAdminApp.restUrl + 'admin/field-preset/' + id;
	}

	YP.views[ 'field-presets' ] = function ( viewEl ) {
		var allPresets = [];

		viewEl.innerHTML =
			'<p class="yp-app__intro">Reusable sets of customization fields — build one here, then insert it into any Template instead of recreating the same fields each time.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search presets&hellip;" />' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add Field Preset</button>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Preset</th><th>Status</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="3">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl = viewEl.querySelector( '[data-yp-search]' );

		function load() {
			rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="3">Loading&hellip;</td></tr>';
			YP.request( endpoint( '?context=edit&status=publish,draft&per_page=100&orderby=title&order=asc' ) )
				.then( function ( presets ) {
					allPresets = presets || [];
					renderRows( allPresets );
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="3">Couldn’t load field presets: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows( presets ) {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? presets.filter( function ( p ) { return p.title.raw.toLowerCase().indexOf( query ) !== -1; } )
				: presets;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="3">' + ( presets.length ? 'No presets match your search.' : 'No field presets yet — add the first one above.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( preset ) {
				var isPublished = 'publish' === preset.status;
				return (
					'<tr data-id="' + preset.id + '">' +
						'<td><div class="yp-record-name">' + YP.escapeHtml( preset.title.raw ) + '</div></td>' +
						'<td><span class="yp-pill ' + ( isPublished ? 'yp-pill--good' : 'yp-pill--neutral' ) + '">' + ( isPublished ? 'Active' : 'Draft' ) + '</span></td>' +
						'<td class="yp-row-actions">' +
							'<button type="button" class="yp-row-action" data-yp-edit="' + preset.id + '">Edit</button>' +
							'<button type="button" class="yp-row-action" data-yp-delete="' + preset.id + '">Delete</button>' +
						'</td>' +
					'</tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-edit]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { openForm( findById( button.getAttribute( 'data-yp-edit' ) ) ); } );
			} );
			rowsEl.querySelectorAll( '[data-yp-delete]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { deletePreset( findById( button.getAttribute( 'data-yp-delete' ) ) ); } );
			} );
		}

		function findById( id ) {
			id = parseInt( id, 10 );
			for ( var i = 0; i < allPresets.length; i++ ) {
				if ( allPresets[ i ].id === id ) {
					return allPresets[ i ];
				}
			}
			return null;
		}

		function deletePreset( preset ) {
			if ( ! preset || ! window.confirm( 'Delete "' + preset.title.raw + '"? This moves it to Trash — it can be restored from Field Presets → Trash in wp-admin if needed. Templates that already inserted this preset\'s fields keep their own copy and are unaffected.' ) ) {
				return;
			}
			YP.request( endpoint( '/' + preset.id ), { method: 'DELETE' } )
				.then( load )
				.catch( function ( error ) {
					window.alert( 'Couldn’t delete: ' + error.message );
				} );
		}

		/* ---------- Add/Edit drawer ---------- */

		function openForm( preset ) {
			var isEdit = !! preset;

			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--wide';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="' + ( isEdit ? 'Edit Field Preset' : 'Add Field Preset' ) + '">' +
					'<div class="yp-drawer__header"><span>' + ( isEdit ? 'Edit Field Preset' : 'Add Field Preset' ) + '</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<form class="yp-form" data-yp-form>' +
							'<div data-yp-form-error></div>' +
							'<div class="yp-field"><label for="yp-fp-name">Name</label><input type="text" id="yp-fp-name" required value="' + ( isEdit ? YP.escapeAttr( preset.title.raw ) : '' ) + '" /></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-fp-active"' + ( ! isEdit || 'publish' === preset.status ? ' checked' : '' ) + ' /><label for="yp-fp-active">Active (selectable from a Template’s “Insert Preset” list)</label></div>' +
							'<p class="yp-field__hint">Build the fields here (label, type, max length, alignment, font sizing, formatting, tooltip text) — everything except position, which is set per-Template since it depends on that Template’s own artwork.</p>' +
							'<div class="yp-panel">' +
								'<div class="yp-panel__head"><h2>Fields</h2></div>' +
								'<div data-yp-field-schema-container><p class="yp-field__hint">Loading&hellip;</p></div>' +
							'</div>' +
							'<div class="yp-form__actions">' +
								'<button type="submit" class="wp-block-button__link is-style-accent" data-yp-save disabled>' + ( isEdit ? 'Save changes' : 'Add preset' ) + '</button>' +
								'<button type="button" class="wp-block-button__link is-style-outline" data-yp-drawer-close>Cancel</button>' +
							'</div>' +
						'</form>' +
					'</div>' +
				'</div>';

			document.body.appendChild( drawer );
			YP.initDrawer( drawer );
			YP.openDrawer( drawer );

			var fieldSchemaEditor = null;
			var saveButtonEl = drawer.querySelector( '[data-yp-save]' );

			var loadFields = isEdit
				? YP.request( adminEndpoint( preset.id ) ).then( function ( data ) { return data.field_schema || []; } )
				: Promise.resolve( [] );

			loadFields.then( function ( fields ) {
				fieldSchemaEditor = YP.createFieldSchemaEditor( {
					container: drawer.querySelector( '[data-yp-field-schema-container]' ),
					fields: fields,
					types: yeffoprintAdminApp.fieldSchema.types,
					alignments: yeffoprintAdminApp.fieldSchema.alignments,
					formattingRules: yeffoprintAdminApp.fieldSchema.formattingRules,
					previewBehaviors: yeffoprintAdminApp.fieldSchema.previewBehaviors,
					qrMinMaxChars: yeffoprintAdminApp.fieldSchema.qrMinMaxChars,
					qrMaxChars: yeffoprintAdminApp.fieldSchema.qrMaxChars,
					previewImageUrl: '',
					presets: [],
					i18n: {
						empty: 'No fields in this preset yet. Add one below.',
						noPreview: 'Position isn’t part of a preset — it’s set per-Template after inserting these fields, against that Template’s own artwork.',
						dragHint: '',
						insertPreset: 'Insert Preset',
						selectPreset: '— Select a preset —'
					}
				} );
				saveButtonEl.disabled = false;
			} ).catch( function ( error ) {
				drawer.querySelector( '[data-yp-form-error]' ).innerHTML = '<p class="yp-form__error">Couldn’t load fields: ' + YP.escapeHtml( error.message ) + '</p>';
			} );

			drawer.querySelector( '[data-yp-form]' ).addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				save( preset, drawer, function () { return fieldSchemaEditor.getFields(); } );
			} );
		}

		function save( existing, drawer, getFields ) {
			var errorEl = drawer.querySelector( '[data-yp-form-error]' );
			var saveButton = drawer.querySelector( '[data-yp-save]' );
			var name = drawer.querySelector( '#yp-fp-name' ).value.trim();

			if ( ! name ) {
				errorEl.innerHTML = '<p class="yp-form__error">Name is required.</p>';
				return;
			}

			errorEl.innerHTML = '';
			saveButton.disabled = true;
			saveButton.textContent = 'Saving…';

			var coreUrl = existing ? endpoint( '/' + existing.id ) : endpoint();

			var status = drawer.querySelector( '#yp-fp-active' ).checked ? 'publish' : 'draft';

			YP.request( coreUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { title: name, status: status } ) } )
				.then( function ( saved ) {
					return YP.request( adminEndpoint( saved.id ), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { field_schema: getFields() } ) } );
				} )
				.then( function () {
					YP.closeDrawer( drawer );
					load();
				} )
				.catch( function ( error ) {
					saveButton.disabled = false;
					saveButton.textContent = existing ? 'Save changes' : 'Add preset';
					errorEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		viewEl.querySelector( '[data-yp-add]' ).addEventListener( 'click', function () { openForm( null ); } );
		searchEl.addEventListener( 'input', function () { renderRows( allPresets ); } );

		load();
	};
} )();
