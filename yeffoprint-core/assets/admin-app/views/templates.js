/**
 * Templates — the hardest screen in the plan (docs/ARCHITECTURE.md,
 * Phase 5). `yp_template` itself (title, content, status, featured_media,
 * and the simple meta: featured/popularity/vial_mockup/badge/preview_font)
 * reads/writes through WP core's own `/wp/v2/yp_template` REST route,
 * same pattern as Materials. The three fields core can't reach —
 * compatible_sizes, compatible_materials, field_schema — go through
 * the new `/admin/template/{id}` endpoint (class-admin-template-controller.php).
 *
 * Saving is therefore always two sequential REST calls: core first (so
 * a brand new Template gets an id), then the gap endpoint. Nothing is
 * saved to the database until the admin actually clicks Save — unlike
 * classic wp-admin's auto-draft, opening the "Add Template" drawer
 * creates nothing on its own.
 *
 * The field-schema repeater itself is YP.createFieldSchemaEditor()
 * (field-schema-editor.js), shared with views/field-presets.js — this
 * file's only extra responsibility is keeping that editor's drag
 * preview in sync with whichever image is currently the featured
 * image, via bindMediaPicker's onSelect/onRemove hooks.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var META = {
		featured: '_yp_featured',
		popularity: '_yp_popularity',
		vialMockup: '_yp_vial_mockup_id',
		badge: '_yp_badge',
		previewFont: '_yp_preview_font'
	};

	function endpoint( path ) {
		return yeffoprintAdminApp.wpApiUrl + 'yp_template' + ( path || '' );
	}

	function adminEndpoint( id ) {
		return yeffoprintAdminApp.restUrl + 'admin/template/' + id;
	}

	YP.views.templates = function ( viewEl ) {
		var allTemplates = [];

		viewEl.innerHTML =
			'<p class="yp-app__intro">Every label design customers can pick from — artwork, customization fields, and which Sizes/Materials each one supports.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search templates&hellip;" />' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add Template</button>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Template</th><th>Badge</th><th>Featured</th><th>Popularity</th><th>Status</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="6">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl = viewEl.querySelector( '[data-yp-search]' );

		function load() {
			rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">Loading&hellip;</td></tr>';
			YP.request( endpoint( '?context=edit&status=publish,draft&per_page=100&orderby=title&order=asc&_embed=1' ) )
				.then( function ( templates ) {
					allTemplates = templates || [];
					renderRows( allTemplates );
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">Couldn’t load templates: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function renderRows( templates ) {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? templates.filter( function ( t ) { return t.title.raw.toLowerCase().indexOf( query ) !== -1; } )
				: templates;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="6">' + ( templates.length ? 'No templates match your search.' : 'No templates yet — add the first one above.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( template ) {
				var thumb = template._embedded && template._embedded[ 'wp:featuredmedia' ] && template._embedded[ 'wp:featuredmedia' ][ 0 ]
					? template._embedded[ 'wp:featuredmedia' ][ 0 ].source_url
					: '';
				var badge = template.meta ? template.meta[ META.badge ] : '';
				var isFeatured = !! ( template.meta && template.meta[ META.featured ] );
				var popularity = template.meta ? parseInt( template.meta[ META.popularity ], 10 ) || 0 : 0;
				var isPublished = 'publish' === template.status;
				var badgeLabel = badge && yeffoprintAdminApp.badges ? yeffoprintAdminApp.badges[ badge ] : '';

				return (
					'<tr data-id="' + template.id + '">' +
						'<td><div class="yp-record-name">' +
							( thumb ? '<img class="yp-swatch" src="' + YP.escapeAttr( thumb ) + '" alt="" style="border-radius: var(--wp--custom--radius--control);" />' : '<span class="yp-swatch" style="border-radius: var(--wp--custom--radius--control);"></span>' ) +
							YP.escapeHtml( template.title.raw ) +
						'</div></td>' +
						'<td>' + ( badgeLabel ? '<span class="yp-chip">' + YP.escapeHtml( badgeLabel ) + '</span>' : '&mdash;' ) + '</td>' +
						'<td>' + ( isFeatured ? '<span class="yp-pill yp-pill--good">Featured</span>' : '&mdash;' ) + '</td>' +
						'<td><span class="yp-chip">' + popularity + '</span></td>' +
						'<td><span class="yp-pill ' + ( isPublished ? 'yp-pill--good' : 'yp-pill--neutral' ) + '">' + ( isPublished ? 'Active' : 'Draft' ) + '</span></td>' +
						'<td class="yp-row-actions">' +
							'<button type="button" class="yp-row-action" data-yp-edit="' + template.id + '">Edit</button>' +
							'<button type="button" class="yp-row-action" data-yp-delete="' + template.id + '">Delete</button>' +
						'</td>' +
					'</tr>'
				);
			} ).join( '' );

			rowsEl.querySelectorAll( '[data-yp-edit]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { openForm( findById( button.getAttribute( 'data-yp-edit' ) ) ); } );
			} );
			rowsEl.querySelectorAll( '[data-yp-delete]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () { deleteTemplate( findById( button.getAttribute( 'data-yp-delete' ) ) ); } );
			} );
		}

		function findById( id ) {
			id = parseInt( id, 10 );
			for ( var i = 0; i < allTemplates.length; i++ ) {
				if ( allTemplates[ i ].id === id ) {
					return allTemplates[ i ];
				}
			}
			return null;
		}

		function deleteTemplate( template ) {
			if ( ! template || ! window.confirm( 'Delete "' + template.title.raw + '"? This moves it to Trash — it can be restored from Templates → Trash in wp-admin if needed.' ) ) {
				return;
			}
			YP.request( endpoint( '/' + template.id ), { method: 'DELETE' } )
				.then( load )
				.catch( function ( error ) {
					window.alert( 'Couldn’t delete: ' + error.message );
				} );
		}

		/* ---------- Add/Edit drawer ---------- */

		function openForm( template ) {
			var isEdit = !! template;
			var meta = ( template && template.meta ) || {};
			var featuredMediaUrl = isEdit && template._embedded && template._embedded[ 'wp:featuredmedia' ] && template._embedded[ 'wp:featuredmedia' ][ 0 ]
				? template._embedded[ 'wp:featuredmedia' ][ 0 ].source_url
				: '';

			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--wide';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="' + ( isEdit ? 'Edit Template' : 'Add Template' ) + '">' +
					'<div class="yp-drawer__header"><span>' + ( isEdit ? 'Edit Template' : 'Add Template' ) + '</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<form class="yp-form" data-yp-form>' +
							'<div data-yp-form-error></div>' +
							'<div class="yp-field"><label for="yp-tpl-name">Name</label><input type="text" id="yp-tpl-name" required value="' + ( isEdit ? YP.escapeAttr( template.title.raw ) : '' ) + '" /></div>' +
							'<div class="yp-field"><label for="yp-tpl-desc">Description</label><textarea id="yp-tpl-desc" placeholder="Shown on the Template’s own page (optional)">' + ( isEdit ? YP.escapeHtml( template.content.raw ) : '' ) + '</textarea></div>' +
							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-tpl-active"' + ( ! isEdit || 'publish' === template.status ? ' checked' : '' ) + ' /><label for="yp-tpl-active">Active (visible to customers)</label></div>' +
							'<div class="yp-form__row">' +
								'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-tpl-featured"' + ( meta[ META.featured ] ? ' checked' : '' ) + ' /><label for="yp-tpl-featured">Featured</label></div>' +
								'<div class="yp-field"><label for="yp-tpl-popularity">Popularity score</label><input type="number" min="0" id="yp-tpl-popularity" value="' + ( meta[ META.popularity ] || 0 ) + '" /></div>' +
							'</div>' +
							'<div class="yp-field"><label for="yp-tpl-badge">Badge</label><select id="yp-tpl-badge">' +
								Object.keys( yeffoprintAdminApp.badges || {} ).map( function ( key ) {
									return '<option value="' + YP.escapeAttr( key ) + '"' + ( ( meta[ META.badge ] || '' ) === key ? ' selected' : '' ) + '>' + YP.escapeHtml( yeffoprintAdminApp.badges[ key ] ) + '</option>';
								} ).join( '' ) +
							'</select></div>' +
							'<div class="yp-field"><label for="yp-tpl-font">Preview font</label>' +
								'<input type="text" id="yp-tpl-font" list="yp-tpl-font-list" placeholder="Default (site font)" value="' + YP.escapeAttr( meta[ META.previewFont ] || '' ) + '" />' +
								'<datalist id="yp-tpl-font-list">' + ( yeffoprintAdminApp.previewFontSuggestions || [] ).map( function ( f ) { return '<option value="' + YP.escapeAttr( f ) + '"></option>'; } ).join( '' ) + '</datalist>' +
								'<p class="yp-field__hint">Any Google Fonts family name — sets what the configurator renders the customer’s live text in.</p>' +
							'</div>' +
							'<div class="yp-field"><label>Artwork (featured image)</label>' +
								'<p class="yp-field__hint">Rectangular, 15:7 (e.g. 900&times;420px) — Label View and the gallery card. Also what customization fields are drag-positioned against below.</p>' +
								'<div class="yp-media-field">' +
									'<div class="yp-media-field__preview" data-yp-art-preview>' + ( featuredMediaUrl ? '<img src="' + YP.escapeAttr( featuredMediaUrl ) + '" alt="" />' : '' ) + '</div>' +
									'<div class="yp-media-field__buttons">' +
										'<input type="hidden" data-yp-art-id value="' + ( isEdit ? template.featured_media || '' : '' ) + '" />' +
										'<button type="button" class="wp-block-button__link is-style-outline" data-yp-art-select>Select image</button>' +
										'<button type="button" class="yp-row-action" data-yp-art-remove ' + ( isEdit && template.featured_media ? '' : 'hidden' ) + '>Remove</button>' +
									'</div>' +
								'</div>' +
							'</div>' +
							'<div class="yp-field"><label>Vial mockup image</label>' +
								'<p class="yp-field__hint">Square (e.g. 800&times;800px) for Vial View and the gallery card hover-swap.</p>' +
								'<div class="yp-media-field">' +
									'<div class="yp-media-field__preview" data-yp-vial-preview></div>' +
									'<div class="yp-media-field__buttons">' +
										'<input type="hidden" data-yp-vial-id value="' + ( meta[ META.vialMockup ] || '' ) + '" />' +
										'<button type="button" class="wp-block-button__link is-style-outline" data-yp-vial-select>Select image</button>' +
										'<button type="button" class="yp-row-action" data-yp-vial-remove ' + ( meta[ META.vialMockup ] ? '' : 'hidden' ) + '>Remove</button>' +
									'</div>' +
								'</div>' +
							'</div>' +
							'<div class="yp-panel">' +
								'<div class="yp-panel__head"><h2>Compatible Sizes</h2></div>' +
								'<div class="yp-admin-checklist" data-yp-sizes-checklist><p class="yp-field__hint">Loading&hellip;</p></div>' +
							'</div>' +
							'<div class="yp-panel">' +
								'<div class="yp-panel__head"><h2>Compatible Materials</h2></div>' +
								'<div class="yp-admin-checklist" data-yp-materials-checklist><p class="yp-field__hint">Loading&hellip;</p></div>' +
							'</div>' +
							'<div class="yp-panel">' +
								'<div class="yp-panel__head"><h2>Customization Fields</h2></div>' +
								'<div data-yp-field-schema-container><p class="yp-field__hint">Loading&hellip;</p></div>' +
							'</div>' +
							'<div class="yp-form__actions">' +
								'<button type="submit" class="wp-block-button__link is-style-accent" data-yp-save>' + ( isEdit ? 'Save changes' : 'Add template' ) + '</button>' +
								'<button type="button" class="wp-block-button__link is-style-outline" data-yp-drawer-close>Cancel</button>' +
							'</div>' +
						'</form>' +
					'</div>' +
				'</div>';

			document.body.appendChild( drawer );
			YP.initDrawer( drawer );
			YP.openDrawer( drawer );

			// The field-schema editor widget is only ever created once, after
			// the checklist/preset/gap-field data below has loaded — an
			// editor created here as an immediate placeholder and replaced
			// once data arrives would leave its document-level drag
			// listeners (field-schema-editor.js's mousemove/touchmove
			// handlers) attached forever, doubling up every time this drawer
			// is reopened. Until then, an artwork pick just remembers the
			// URL so the editor opens already pointed at it.
			var fieldSchemaEditor = null;
			var pendingPreviewUrl = featuredMediaUrl;

			YP.bindMediaPicker( {
				title: 'Select artwork',
				selectButton: drawer.querySelector( '[data-yp-art-select]' ),
				removeButton: drawer.querySelector( '[data-yp-art-remove]' ),
				idInput: drawer.querySelector( '[data-yp-art-id]' ),
				preview: drawer.querySelector( '[data-yp-art-preview]' ),
				onSelect: function ( attachment ) {
					pendingPreviewUrl = attachment.url;
					if ( fieldSchemaEditor ) { fieldSchemaEditor.setPreviewImage( pendingPreviewUrl ); }
				},
				onRemove: function () {
					pendingPreviewUrl = '';
					if ( fieldSchemaEditor ) { fieldSchemaEditor.setPreviewImage( '' ); }
				}
			} );
			YP.bindMediaPicker( {
				title: 'Select vial mockup',
				selectButton: drawer.querySelector( '[data-yp-vial-select]' ),
				removeButton: drawer.querySelector( '[data-yp-vial-remove]' ),
				idInput: drawer.querySelector( '[data-yp-vial-id]' ),
				preview: drawer.querySelector( '[data-yp-vial-preview]' )
			} );

			var vialId = parseInt( meta[ META.vialMockup ], 10 ) || 0;
			if ( vialId ) {
				YP.request( yeffoprintAdminApp.wpApiUrl + 'media/' + vialId )
					.then( function ( attachment ) {
						var preview = drawer.querySelector( '[data-yp-vial-preview]' );
						if ( preview && attachment && attachment.source_url ) {
							preview.innerHTML = '<img src="' + YP.escapeAttr( attachment.source_url ) + '" alt="" />';
						}
					} )
					.catch( function () {} );
			}

			/* ---------- Load checklists, presets, and (if editing) the gap fields ---------- */

			var saveButtonEl = drawer.querySelector( '[data-yp-save]' );
			saveButtonEl.disabled = true;

			var checklistPromises = [
				YP.request( yeffoprintAdminApp.wpApiUrl + 'yp_size?status=publish&per_page=100&orderby=menu_order&order=asc' ),
				YP.request( yeffoprintAdminApp.wpApiUrl + 'yp_material?status=publish&per_page=100&orderby=menu_order&order=asc' ),
				YP.request( yeffoprintAdminApp.wpApiUrl + 'yp_field_preset?status=publish&per_page=100&orderby=title&order=asc' )
			];

			Promise.all( checklistPromises ).then( function ( results ) {
				var sizes = results[ 0 ] || [];
				var materials = results[ 1 ] || [];
				var presetPosts = results[ 2 ] || [];

				var loadGap = isEdit
					? YP.request( adminEndpoint( template.id ) )
					: Promise.resolve( { compatible_sizes: [], compatible_materials: [], field_schema: [] } );

				var loadPresets = presetPosts.length
					? Promise.all( presetPosts.map( function ( p ) {
						return YP.request( yeffoprintAdminApp.restUrl + 'admin/field-preset/' + p.id ).then( function ( data ) {
							return { id: p.id, name: p.title.rendered, fields: data.field_schema || [] };
						} );
					} ) )
					: Promise.resolve( [] );

				return Promise.all( [ loadGap, loadPresets ] ).then( function ( results2 ) {
					renderChecklist( drawer.querySelector( '[data-yp-sizes-checklist]' ), sizes, results2[ 0 ].compatible_sizes, 'compat-size' );
					renderChecklist( drawer.querySelector( '[data-yp-materials-checklist]' ), materials, results2[ 0 ].compatible_materials, 'compat-material' );

					fieldSchemaEditor = YP.createFieldSchemaEditor( {
						container: drawer.querySelector( '[data-yp-field-schema-container]' ),
						fields: results2[ 0 ].field_schema,
						types: yeffoprintAdminApp.fieldSchema.types,
						alignments: yeffoprintAdminApp.fieldSchema.alignments,
						formattingRules: yeffoprintAdminApp.fieldSchema.formattingRules,
						previewBehaviors: yeffoprintAdminApp.fieldSchema.previewBehaviors,
						qrMinMaxChars: yeffoprintAdminApp.fieldSchema.qrMinMaxChars,
						qrMaxChars: yeffoprintAdminApp.fieldSchema.qrMaxChars,
						previewImageUrl: pendingPreviewUrl,
						presets: results2[ 1 ],
						i18n: {
							empty: 'No customization fields yet. Add one below.',
							noPreview: 'Set an artwork image above to preview and drag-position fields here.',
							dragHint: 'Drag a label to reposition it on the artwork, or set exact percentages below. Click a label first, then use the arrow keys to nudge it precisely (hold Shift for bigger steps).',
							insertPreset: 'Insert Preset',
							selectPreset: '— Select a preset —'
						}
					} );

					saveButtonEl.disabled = false;
				} );
			} ).catch( function ( error ) {
				drawer.querySelector( '[data-yp-form-error]' ).innerHTML = '<p class="yp-form__error">Couldn’t load supporting data: ' + YP.escapeHtml( error.message ) + '</p>';
			} );

			function renderChecklist( container, records, selectedIds, name ) {
				if ( ! records.length ) {
					container.innerHTML = '<p class="yp-field__hint">None yet.</p>';
					return;
				}
				container.innerHTML = records.map( function ( record ) {
					var checked = selectedIds.indexOf( record.id ) !== -1;
					return '<label><input type="checkbox" data-' + name + '="' + record.id + '"' + ( checked ? ' checked' : '' ) + ' /> ' + YP.escapeHtml( record.title.rendered ) + '</label>';
				} ).join( '' );
			}

			drawer.querySelector( '[data-yp-form]' ).addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				save( template, drawer, function () { return fieldSchemaEditor.getFields(); } );
			} );
		}

		function save( existing, drawer, getFields ) {
			var form = drawer.querySelector( '[data-yp-form]' );
			var errorEl = drawer.querySelector( '[data-yp-form-error]' );
			var saveButton = drawer.querySelector( '[data-yp-save]' );
			var name = drawer.querySelector( '#yp-tpl-name' ).value.trim();

			if ( ! name ) {
				errorEl.innerHTML = '<p class="yp-form__error">Name is required.</p>';
				return;
			}

			errorEl.innerHTML = '';
			saveButton.disabled = true;
			saveButton.textContent = 'Saving…';

			var coreBody = {
				title: name,
				content: drawer.querySelector( '#yp-tpl-desc' ).value,
				status: drawer.querySelector( '#yp-tpl-active' ).checked ? 'publish' : 'draft',
				featured_media: parseInt( drawer.querySelector( '[data-yp-art-id]' ).value, 10 ) || 0,
				meta: {}
			};
			coreBody.meta[ META.featured ] = drawer.querySelector( '#yp-tpl-featured' ).checked;
			coreBody.meta[ META.popularity ] = parseInt( drawer.querySelector( '#yp-tpl-popularity' ).value, 10 ) || 0;
			coreBody.meta[ META.badge ] = drawer.querySelector( '#yp-tpl-badge' ).value;
			coreBody.meta[ META.previewFont ] = drawer.querySelector( '#yp-tpl-font' ).value;
			coreBody.meta[ META.vialMockup ] = parseInt( drawer.querySelector( '[data-yp-vial-id]' ).value, 10 ) || 0;

			var coreUrl = existing ? endpoint( '/' + existing.id ) : endpoint();

			YP.request( coreUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( coreBody ) } )
				.then( function ( saved ) {
					var gapBody = {
						compatible_sizes: Array.prototype.map.call( drawer.querySelectorAll( '[data-compat-size]:checked' ), function ( el ) { return parseInt( el.getAttribute( 'data-compat-size' ), 10 ); } ),
						compatible_materials: Array.prototype.map.call( drawer.querySelectorAll( '[data-compat-material]:checked' ), function ( el ) { return parseInt( el.getAttribute( 'data-compat-material' ), 10 ); } ),
						field_schema: getFields()
					};
					return YP.request( adminEndpoint( saved.id ), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( gapBody ) } );
				} )
				.then( function () {
					YP.closeDrawer( drawer );
					load();
				} )
				.catch( function ( error ) {
					saveButton.disabled = false;
					saveButton.textContent = existing ? 'Save changes' : 'Add template';
					errorEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		viewEl.querySelector( '[data-yp-add]' ).addEventListener( 'click', function () { openForm( null ); } );
		searchEl.addEventListener( 'input', function () { renderRows( allTemplates ); } );

		load();
	};
} )();
