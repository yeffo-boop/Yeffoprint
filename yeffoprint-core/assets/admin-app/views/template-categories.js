/**
 * Template categories — direct request: add, rename and remove the
 * Product Types, Styles, Colors and Compatible Materials that Templates
 * are tagged with (and the shop filters by), without wp-admin. Uses
 * WordPress's own term routes (wp/v2/{taxonomy}); the taxonomies are
 * registered show_in_rest in class-template-taxonomies.php. Ticking
 * them on a Template still happens in the Template editor; tile images
 * for Product Types stay under Templates › Category images.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var GROUPS = [
		{ taxonomy: 'yp_product_type', label: 'Product types', hint: 'The main pills at the top of the shop (Peptide & Vial, Skincare…).' },
		{ taxonomy: 'yp_style', label: 'Styles', hint: 'The shop’s Style filter.' },
		{ taxonomy: 'yp_color', label: 'Colors', hint: 'The shop’s Color filter.' },
		{ taxonomy: 'yp_material_tag', label: 'Compatible materials', hint: 'The shop’s Material filter.' }
	];

	function termsUrl( taxonomy, path ) {
		return yeffoprintAdminApp.wpApiUrl + taxonomy + ( path || '' );
	}

	function decode( text ) {
		var el = document.createElement( 'textarea' );
		el.innerHTML = text;
		return el.value;
	}

	YP.views[ 'template-categories' ] = function ( viewEl ) {
		viewEl.innerHTML =
			'<p class="yp-app__intro">The categories Templates are tagged with. Changes show in the shop filters right away.</p>' +
			GROUPS.map( function ( group ) {
				return (
					'<section class="yp-panel yp-terms" data-yp-terms="' + group.taxonomy + '">' +
						'<h3>' + YP.escapeHtml( group.label ) + '</h3>' +
						'<p class="yp-field__hint">' + YP.escapeHtml( group.hint ) + '</p>' +
						'<div data-yp-term-list><p class="yp-field__hint">Loading&hellip;</p></div>' +
						'<form class="yp-terms__add" data-yp-term-add>' +
							'<input type="text" placeholder="New ' + YP.escapeAttr( group.label.toLowerCase().replace( /s$/, '' ) ) + '" aria-label="New ' + YP.escapeAttr( group.label ) + '" required>' +
							'<button type="submit" class="wp-block-button__link is-style-outline">+ Add</button>' +
						'</form>' +
						'<div data-yp-term-msg></div>' +
					'</section>'
				);
			} ).join( '' );

		function load( section ) {
			var taxonomy = section.getAttribute( 'data-yp-terms' );
			var listEl = section.querySelector( '[data-yp-term-list]' );
			YP.request( termsUrl( taxonomy, '?per_page=100&orderby=name&hide_empty=false&context=edit' ) ).then( function ( terms ) {
				listEl.innerHTML = terms.length
					? '<ul class="yp-terms__list">' + terms.map( function ( term ) {
						return (
							'<li data-yp-term="' + term.id + '" data-yp-count="' + term.count + '">' +
								'<input type="text" value="' + YP.escapeAttr( decode( term.name ) ) + '" aria-label="Name" data-yp-term-name>' +
								'<span class="yp-field__hint">' + term.count + ( 1 === term.count ? ' template' : ' templates' ) + '</span>' +
								'<button type="button" class="yp-link-button" data-yp-term-save hidden>Save</button>' +
								'<button type="button" class="yp-link-button" data-yp-term-delete>Delete</button>' +
							'</li>'
						);
					} ).join( '' ) + '</ul>'
					: '<p class="yp-field__hint">None yet.</p>';
			} ).catch( function ( error ) {
				listEl.innerHTML = '<p class="yp-form__error">Couldn’t load: ' + YP.escapeHtml( error.message ) + '</p>';
			} );
		}

		function showError( section, error ) {
			section.querySelector( '[data-yp-term-msg]' ).innerHTML = error ? '<p class="yp-form__error">' + YP.escapeHtml( error.message || error ) + '</p>' : '';
		}

		viewEl.querySelectorAll( '[data-yp-terms]' ).forEach( function ( section ) {
			var taxonomy = section.getAttribute( 'data-yp-terms' );

			section.querySelector( '[data-yp-term-add]' ).addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				var input = event.target.querySelector( 'input' );
				var button = event.target.querySelector( 'button' );
				if ( ! input.value.trim() ) {
					return;
				}
				button.disabled = true;
				showError( section );
				YP.request( termsUrl( taxonomy ), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { name: input.value.trim() } )
				} ).then( function () {
					input.value = '';
					load( section );
				} ).catch( function ( error ) {
					showError( section, error );
				} ).then( function () { button.disabled = false; } );
			} );

			section.addEventListener( 'input', function ( event ) {
				var row = event.target.closest( '[data-yp-term]' );
				if ( row ) {
					row.querySelector( '[data-yp-term-save]' ).hidden = false;
				}
			} );

			section.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key && event.target.matches( '[data-yp-term-name]' ) ) {
					event.preventDefault();
					event.target.closest( '[data-yp-term]' ).querySelector( '[data-yp-term-save]' ).click();
				}
			} );

			section.addEventListener( 'click', function ( event ) {
				var row = event.target.closest( '[data-yp-term]' );
				if ( ! row ) {
					return;
				}
				var id = row.getAttribute( 'data-yp-term' );
				var name = row.querySelector( '[data-yp-term-name]' ).value.trim();

				if ( event.target.closest( '[data-yp-term-save]' ) ) {
					if ( ! name ) {
						return;
					}
					event.target.disabled = true;
					showError( section );
					YP.request( termsUrl( taxonomy, '/' + id ), {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( { name: name } )
					} ).then( function () {
						event.target.hidden = true;
						event.target.disabled = false;
					} ).catch( function ( error ) {
						event.target.disabled = false;
						showError( section, error );
					} );
				} else if ( event.target.closest( '[data-yp-term-delete]' ) ) {
					var count = parseInt( row.getAttribute( 'data-yp-count' ), 10 ) || 0;
					YP.confirmModal( {
						title: 'Delete “' + name + '”?',
						message: count ? 'It comes off the ' + count + ( 1 === count ? ' template' : ' templates' ) + ' tagged with it. The templates themselves stay.' : 'No templates use it.',
						confirmLabel: 'Delete',
						danger: true,
						onConfirm: function () {
							YP.request( termsUrl( taxonomy, '/' + id + '?force=true' ), { method: 'DELETE' } ).then( function () {
								load( section );
							} ).catch( function ( error ) {
								showError( section, error );
							} );
						}
					} );
				}
			} );

			load( section );
		} );
	};
} )();
