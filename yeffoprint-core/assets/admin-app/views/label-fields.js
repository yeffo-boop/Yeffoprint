/**
 * Label Fields — the one set of customization fields every Template
 * shares (Compound Name, Strength, Color, Corner Finish, …). Direct
 * request: "I would like the ability to add/edit/remove form fields
 * somewhere in the admin dashboard, but that just needs to be in one
 * place since we're using global fields."
 *
 * Replaces the old Field Presets list + Settings dropdown pair: the
 * fields still live on one yp_field_preset (so every existing read path,
 * cart validation and order snapshot keeps working unchanged), but this
 * screen goes straight to its editor. `/admin/label-fields`
 * (class-admin-field-preset-controller.php) finds or creates that preset
 * itself, so there's nothing to pick first.
 *
 * Reuses the same field-schema-editor.js repeater as Templates. No
 * previewImageUrl: these fields are shared across every Template's
 * artwork, so there is no single image to position them against.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	function endpoint() {
		return yeffoprintAdminApp.restUrl + 'admin/label-fields';
	}

	YP.views[ 'label-fields' ] = function ( viewEl ) {
		viewEl.innerHTML =
			'<p class="yp-app__intro">The customization fields customers fill in on every label design. Add, edit, reorder, or remove a field here and it changes on every Template at once, immediately after you save.</p>' +
			'<div class="yp-panel">' +
				'<div class="yp-panel__head"><h2>Fields</h2></div>' +
				'<p class="yp-panel__hint">Required fields appear first on the product page, under “On the label”; the rest appear under “Optional details”. Renaming a field keeps it attached to past orders and saved designs.</p>' +
				'<div data-yp-field-schema-container><p class="yp-field__hint">Loading&hellip;</p></div>' +
			'</div>' +
			'<div data-yp-save-status></div>' +
			'<button type="button" class="wp-block-button__link is-style-accent" data-yp-save disabled>Save fields</button>';

		var containerEl = viewEl.querySelector( '[data-yp-field-schema-container]' );
		var statusEl = viewEl.querySelector( '[data-yp-save-status]' );
		var saveButton = viewEl.querySelector( '[data-yp-save]' );
		var editor = null;

		function mountEditor( fields ) {
			containerEl.innerHTML = '';
			editor = YP.createFieldSchemaEditor( {
				container: containerEl,
				fields: fields,
				types: yeffoprintAdminApp.fieldSchema.types,
				alignments: yeffoprintAdminApp.fieldSchema.alignments,
				formattingRules: yeffoprintAdminApp.fieldSchema.formattingRules,
				previewBehaviors: yeffoprintAdminApp.fieldSchema.previewBehaviors,
				qrMinMaxChars: yeffoprintAdminApp.fieldSchema.qrMinMaxChars,
				qrMaxChars: yeffoprintAdminApp.fieldSchema.qrMaxChars,
				cornerStyleOptions: yeffoprintAdminApp.fieldSchema.cornerStyleOptions,
				previewImageUrl: '',
				presets: [],
				i18n: {
					empty: 'No fields yet. Add the first one below.',
					noPreview: 'These fields are shared by every Template, so there’s no single artwork to position them on here.',
					dragHint: '',
					insertPreset: 'Insert Preset',
					selectPreset: '— Select a preset —'
				}
			} );
			saveButton.disabled = false;
		}

		YP.request( endpoint() )
			.then( function ( data ) { mountEditor( data.field_schema || [] ); } )
			.catch( function ( error ) {
				containerEl.innerHTML = '<p class="yp-form__error">Couldn’t load fields: ' + YP.escapeHtml( error.message ) + '</p>';
			} );

		saveButton.addEventListener( 'click', function () {
			if ( ! editor ) {
				return;
			}

			saveButton.disabled = true;
			saveButton.textContent = 'Saving…';
			statusEl.innerHTML = '';

			YP.request( endpoint(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { field_schema: editor.getFields() } ) } )
				.then( function ( data ) {
					var fields = data.field_schema || [];
					// Keep the Templates screen's read-only copy in step
					// without a page reload.
					if ( yeffoprintAdminApp.defaultFieldPreset ) {
						yeffoprintAdminApp.defaultFieldPreset.fields = fields;
					}
					mountEditor( fields );
					saveButton.textContent = 'Save fields';
					statusEl.innerHTML = '<p class="yp-panel__hint">Saved — live on every label design now.</p>';
				} )
				.catch( function ( error ) {
					saveButton.disabled = false;
					saveButton.textContent = 'Save fields';
					statusEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		} );
	};
} )();
