/**
 * 3D Prints — one record per printed item (WP core's own `/wp/v2/
 * yp_print` route), direct request: "I will need some way to designate
 * how many color choices a customer can have per item and where on the
 * print that color will go."
 *
 * The editor's Color choices section is that answer: a +/− count, then
 * one card per choice (part name, hint, which Filament Colors it offers,
 * its default). "Where it goes" is a numbered dot placed by clicking the
 * item's main photo while that choice is selected; the product page
 * shows the same dots in the same spots (x/y stored as percentages of
 * the photo, so they hold at any image size). Saved as the one
 * `_yp_print_color_slots` array — see YeffoPrint_Print_Meta.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var META = {
		price: '_yp_print_price',
		shipsIn: '_yp_print_ships_in',
		slots: '_yp_print_color_slots'
	};

	var MAX_SLOTS = 8;

	function endpoint( path ) {
		return yeffoprintAdminApp.wpApiUrl + 'yp_print' + ( path || '' );
	}

	function post( id, body ) {
		return YP.request( endpoint( id ? '/' + id : '' ), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } );
	}

	function swatch( color, size ) {
		var meta = color.meta || {};
		return YP.filamentSwatch
			? YP.filamentSwatch( meta._yp_filament_hex, meta._yp_filament_finish, size )
			: '';
	}

	YP.views.prints = function ( viewEl ) {
		var allPrints = [];
		var filaments = [];

		viewEl.innerHTML =
			'<p class="yp-app__intro">Items in the 3D Prints section of the shop. Each one sets how many colors the customer picks and where each color goes on the print. Colors come from <a href="#/filament-colors">Filament Colors</a>.</p>' +
			'<div class="yp-list-toolbar">' +
				'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search 3D prints&hellip;" />' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add 3D Print</button>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Item</th><th>Price</th><th>Color choices</th><th>Status</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="5">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl = viewEl.querySelector( '[data-yp-search]' );

		function load() {
			Promise.all( [
				YP.request( endpoint( '?context=edit&status=publish,draft&per_page=100&orderby=menu_order&order=asc&_embed=wp:featuredmedia' ) ),
				YP.request( yeffoprintAdminApp.wpApiUrl + 'yp_filament?context=edit&status=publish&per_page=100&orderby=menu_order&order=asc' )
			] )
				.then( function ( results ) {
					allPrints = results[ 0 ] || [];
					filaments = results[ 1 ] || [];
					renderRows();
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">Couldn’t load 3D prints: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function imageUrl( print ) {
			var media = print._embedded && print._embedded[ 'wp:featuredmedia' ] && print._embedded[ 'wp:featuredmedia' ][ 0 ];
			if ( ! media || ! media.source_url ) {
				return '';
			}
			var sizes = media.media_details && media.media_details.sizes;
			return ( sizes && sizes.large && sizes.large.source_url ) || media.source_url;
		}

		function renderRows() {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var filtered = query
				? allPrints.filter( function ( p ) { return p.title.raw.toLowerCase().indexOf( query ) !== -1; } )
				: allPrints;

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="5">' + ( allPrints.length ? 'No items match your search.' : 'No 3D prints yet. Add the first one above.' ) + '</td></tr>';
				return;
			}

			rowsEl.innerHTML = filtered.map( function ( print ) {
				var meta = print.meta || {};
				var slots = meta[ META.slots ] || [];
				var price = parseFloat( meta[ META.price ] ) || 0;
				var isPublished = 'publish' === print.status;
				var img = imageUrl( print );

				return (
					'<tr data-id="' + print.id + '">' +
						'<td><div class="yp-filament-name">' +
							( img ? '<img class="yp-swatch" src="' + YP.escapeAttr( img ) + '" alt="" />' : '<span class="yp-swatch"></span>' ) +
							'<div class="yp-record-name">' + YP.escapeHtml( print.title.raw || '(untitled)' ) + '</div></div></td>' +
						'<td><span class="yp-chip">$' + price.toFixed( 2 ) + '</span></td>' +
						'<td>' + ( slots.length ? slots.map( function ( s, i ) { return '<span class="yp-chip">' + ( i + 1 ) + ' · ' + YP.escapeHtml( s.name || 'Unnamed' ) + '</span>'; } ).join( ' ' ) : 'One color, no choice' ) + '</td>' +
						'<td><span class="yp-pill ' + ( isPublished ? 'yp-pill--good' : 'yp-pill--neutral' ) + '">' + ( isPublished ? 'Published' : 'Draft' ) + '</span></td>' +
						'<td class="yp-row-actions">' +
							( isPublished && print.link ? '<a class="yp-row-action" href="' + YP.escapeAttr( print.link ) + '" target="_blank" rel="noopener">View</a>' : '' ) +
							'<button type="button" class="yp-row-action" data-yp-edit="' + print.id + '">Edit</button>' +
							'<button type="button" class="yp-row-action" data-yp-delete="' + print.id + '">Delete</button>' +
						'</td>' +
					'</tr>'
				);
			} ).join( '' );
		}

		function findById( id ) {
			id = parseInt( id, 10 );
			for ( var i = 0; i < allPrints.length; i++ ) {
				if ( allPrints[ i ].id === id ) {
					return allPrints[ i ];
				}
			}
			return null;
		}

		function deletePrint( print ) {
			if ( ! print || ! window.confirm( 'Delete "' + print.title.raw + '"? This moves it to Trash and takes it off the site.' ) ) {
				return;
			}
			YP.request( endpoint( '/' + print.id ), { method: 'DELETE' } )
				.then( load )
				.catch( function ( error ) {
					window.alert( 'Couldn’t delete: ' + error.message );
				} );
		}

		/* ---------- Editor ---------- */

		function openForm( print ) {
			var isEdit = !! print;
			var meta = ( print && print.meta ) || {};
			var slots = ( meta[ META.slots ] || [] ).map( function ( s ) {
				return { name: s.name || '', hint: s.hint || '', x: s.x, y: s.y, colors: ( s.colors || [] ).slice(), default_id: s.default_id || 0 };
			} );
			var activeSlot = slots.length ? 0 : -1;
			var photoUrl = isEdit ? imageUrl( print ) : '';

			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer yp-drawer--center yp-drawer--wide';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="' + ( isEdit ? 'Edit 3D Print' : 'Add 3D Print' ) + '">' +
					'<div class="yp-drawer__header"><span>' + ( isEdit ? 'Edit 3D Print' : 'Add 3D Print' ) + '</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<form class="yp-form" data-yp-form>' +
							'<div data-yp-form-error></div>' +
							'<div class="yp-form__row yp-form__row--three">' +
								'<div class="yp-field"><label for="yp-pr-name">Name</label><input type="text" id="yp-pr-name" name="name" required value="' + ( isEdit ? YP.escapeAttr( print.title.raw ) : '' ) + '" /></div>' +
								'<div class="yp-field"><label for="yp-pr-price">Base price ($)</label><input type="number" step="0.01" min="0" id="yp-pr-price" name="price" value="' + ( parseFloat( meta[ META.price ] ) || '' ) + '" /></div>' +
								'<div class="yp-field"><label for="yp-pr-ships">Ships in</label><input type="text" id="yp-pr-ships" name="ships_in" placeholder="3 to 5 days" value="' + YP.escapeAttr( meta[ META.shipsIn ] || '' ) + '" /></div>' +
							'</div>' +
							'<div class="yp-field"><label for="yp-pr-desc">Description</label><textarea id="yp-pr-desc" name="description" rows="3">' + YP.escapeHtml( isEdit ? ( print.content.raw || '' ).replace( /<[^>]+>/g, '' ).trim() : '' ) + '</textarea></div>' +

							'<div class="yp-print-editor">' +
								'<div class="yp-print-editor__slots">' +
									'<h3 class="yp-print-editor__heading">Color choices</h3>' +
									'<p class="yp-field__hint">Each choice is one part of the print the customer picks a color for. Name the part, click the colors it offers, then click the photo to show where it is. Extra charges come from Filament Colors.</p>' +
									'<div class="yp-print-count">' +
										'<div><strong>How many colors can the customer pick?</strong><span>Set 0 for a single-color item with no choice.</span></div>' +
										'<div class="yp-print-count__stepper">' +
											'<button type="button" data-yp-count="-1" aria-label="One fewer color choice">&minus;</button>' +
											'<output data-yp-count-value>0</output>' +
											'<button type="button" data-yp-count="1" aria-label="One more color choice">+</button>' +
										'</div>' +
									'</div>' +
									'<div data-yp-slots></div>' +
								'</div>' +
								'<div class="yp-print-editor__photo">' +
									'<h3 class="yp-print-editor__heading">Where each color goes</h3>' +
									'<p class="yp-print-editor__hint" data-yp-photo-hint></p>' +
									'<div class="yp-print-photo" data-yp-photo></div>' +
									'<div class="yp-media-field__buttons yp-print-photo__buttons">' +
										'<input type="hidden" data-yp-photo-id value="' + ( isEdit ? print.featured_media || '' : '' ) + '" />' +
										'<span hidden data-yp-photo-preview></span>' +
										'<button type="button" class="yp-row-action" data-yp-photo-select>' + ( photoUrl ? 'Change photo' : 'Select main photo' ) + '</button>' +
										'<button type="button" class="yp-row-action" data-yp-photo-remove ' + ( photoUrl ? '' : 'hidden' ) + '>Remove photo</button>' +
									'</div>' +
								'</div>' +
							'</div>' +

							'<div class="yp-field--checkbox yp-field"><input type="checkbox" id="yp-pr-active" name="active"' + ( isEdit && 'publish' === print.status ? ' checked' : '' ) + ' /><label for="yp-pr-active">Published (shows in the 3D Prints section)</label></div>' +
							'<div class="yp-form__actions">' +
								'<button type="submit" class="wp-block-button__link is-style-accent" data-yp-save>' + ( isEdit ? 'Save item' : 'Add item' ) + '</button>' +
								'<button type="button" class="wp-block-button__link is-style-outline" data-yp-drawer-close>Cancel</button>' +
							'</div>' +
						'</form>' +
					'</div>' +
				'</div>';

			document.body.appendChild( drawer );
			YP.initDrawer( drawer );
			YP.openDrawer( drawer );

			var form = drawer.querySelector( '[data-yp-form]' );
			var slotsEl = drawer.querySelector( '[data-yp-slots]' );
			var countEl = drawer.querySelector( '[data-yp-count-value]' );
			var photoEl = drawer.querySelector( '[data-yp-photo]' );
			var photoHintEl = drawer.querySelector( '[data-yp-photo-hint]' );

			function defaultColors() {
				return filaments.map( function ( f ) { return f.id; } );
			}

			function setCount( n ) {
				n = Math.max( 0, Math.min( MAX_SLOTS, n ) );
				while ( slots.length < n ) {
					var colors = defaultColors();
					slots.push( { name: '', hint: '', x: null, y: null, colors: colors, default_id: colors[ 0 ] || 0 } );
				}
				slots.length = n;
				if ( activeSlot >= n ) {
					activeSlot = n - 1;
				}
				if ( activeSlot < 0 && n ) {
					activeSlot = 0;
				}
				render();
			}

			function renderSlots() {
				countEl.textContent = slots.length;

				if ( ! filaments.length ) {
					slotsEl.innerHTML = '<p class="yp-form__error">No active Filament Colors yet. <a href="#/filament-colors">Add your colors</a> first, then come back.</p>';
					return;
				}

				slotsEl.innerHTML = slots.map( function ( slot, i ) {
					var placed = null !== slot.x && undefined !== slot.x && null !== slot.y && undefined !== slot.y;
					return (
						'<div class="yp-print-slot' + ( i === activeSlot ? ' is-active' : '' ) + '" data-yp-slot="' + i + '">' +
							'<div class="yp-print-slot__head">' +
								'<span class="yp-print-num">' + ( i + 1 ) + '</span>' +
								'<input type="text" data-yp-slot-field="name" placeholder="Part name, e.g. Base" value="' + YP.escapeAttr( slot.name ) + '" aria-label="Part name" />' +
								'<input type="text" data-yp-slot-field="hint" placeholder="Short hint, e.g. Bottom platform" value="' + YP.escapeAttr( slot.hint ) + '" aria-label="Hint" />' +
								'<button type="button" class="yp-print-where' + ( placed ? ' is-set' : '' ) + '" data-yp-slot-place>' + ( placed ? '&#10003; Dot placed' : 'Place dot' ) + '</button>' +
							'</div>' +
							'<div class="yp-print-chips">' +
								filaments.map( function ( f ) {
									var on = slot.colors.indexOf( f.id ) !== -1;
									return '<button type="button" class="yp-print-chip' + ( on ? ' is-on' : '' ) + '" data-yp-chip="' + f.id + '" aria-pressed="' + ( on ? 'true' : 'false' ) + '">' +
										swatch( f, 18 ) + YP.escapeHtml( f.title.raw ) +
									'</button>';
								} ).join( '' ) +
							'</div>' +
							'<div class="yp-print-slot__foot">' +
								'<label>Starts on <select data-yp-slot-default aria-label="Default color">' +
									filaments.filter( function ( f ) { return slot.colors.indexOf( f.id ) !== -1; } ).map( function ( f ) {
										return '<option value="' + f.id + '"' + ( slot.default_id === f.id ? ' selected' : '' ) + '>' + YP.escapeHtml( f.title.raw ) + '</option>';
									} ).join( '' ) +
								'</select></label>' +
								'<button type="button" class="yp-row-action" data-yp-slot-remove>Remove choice</button>' +
							'</div>' +
						'</div>'
					);
				} ).join( '' );
			}

			function renderPhoto() {
				if ( ! photoUrl ) {
					photoEl.innerHTML = '<div class="yp-print-photo__empty">Add the item’s main photo, then click it to place each color’s dot.</div>';
					photoHintEl.textContent = '';
					return;
				}

				photoEl.innerHTML = '<img src="' + YP.escapeAttr( photoUrl ) + '" alt="" draggable="false" />' +
					slots.map( function ( slot, i ) {
						if ( null === slot.x || undefined === slot.x || null === slot.y || undefined === slot.y ) {
							return '';
						}
						return '<span class="yp-print-dot' + ( i === activeSlot ? ' is-active' : '' ) + '" style="left:' + slot.x + '%;top:' + slot.y + '%">' + ( i + 1 ) + '</span>';
					} ).join( '' );

				photoHintEl.innerHTML = activeSlot >= 0
					? 'Placing dot <strong>' + ( activeSlot + 1 ) + ( slots[ activeSlot ].name ? ' · ' + YP.escapeHtml( slots[ activeSlot ].name ) : '' ) + '</strong>. Click that part on the photo.'
					: 'Add a color choice to place its dot.';
			}

			function render() {
				renderSlots();
				renderPhoto();
			}

			slotsEl.addEventListener( 'input', function ( event ) {
				var field = event.target.getAttribute( 'data-yp-slot-field' );
				var card = event.target.closest( '[data-yp-slot]' );
				if ( field && card ) {
					slots[ parseInt( card.getAttribute( 'data-yp-slot' ), 10 ) ][ field ] = event.target.value;
					if ( 'name' === field ) {
						renderPhoto();
					}
				}
			} );

			slotsEl.addEventListener( 'change', function ( event ) {
				var card = event.target.closest( '[data-yp-slot]' );
				if ( card && event.target.hasAttribute( 'data-yp-slot-default' ) ) {
					slots[ parseInt( card.getAttribute( 'data-yp-slot' ), 10 ) ].default_id = parseInt( event.target.value, 10 ) || 0;
				}
			} );

			slotsEl.addEventListener( 'focusin', function ( event ) {
				var card = event.target.closest( '[data-yp-slot]' );
				var index = card ? parseInt( card.getAttribute( 'data-yp-slot' ), 10 ) : -1;
				if ( card && index !== activeSlot ) {
					activeSlot = index;
					slotsEl.querySelectorAll( '[data-yp-slot]' ).forEach( function ( el ) {
						el.classList.toggle( 'is-active', el === card );
					} );
					renderPhoto();
				}
			} );

			slotsEl.addEventListener( 'click', function ( event ) {
				var card = event.target.closest( '[data-yp-slot]' );
				if ( ! card ) {
					return;
				}
				var index = parseInt( card.getAttribute( 'data-yp-slot' ), 10 );
				var slot = slots[ index ];
				var chip = event.target.closest( '[data-yp-chip]' );
				activeSlot = index;

				if ( chip ) {
					var id = parseInt( chip.getAttribute( 'data-yp-chip' ), 10 );
					var at = slot.colors.indexOf( id );
					if ( -1 === at ) {
						slot.colors.push( id );
					} else {
						slot.colors.splice( at, 1 );
					}
					if ( slot.colors.indexOf( slot.default_id ) === -1 ) {
						slot.default_id = slot.colors[ 0 ] || 0;
					}
					render();
					return;
				}

				if ( event.target.closest( '[data-yp-slot-remove]' ) ) {
					slots.splice( index, 1 );
					activeSlot = Math.min( index, slots.length - 1 );
					render();
					return;
				}

				if ( event.target.closest( 'select' ) ) {
					return;
				}

				if ( event.target.closest( '[data-yp-slot-place]' ) ) {
					render();
					photoEl.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
					return;
				}

				if ( ! event.target.closest( 'input' ) ) {
					render();
				}
			} );

			photoEl.addEventListener( 'click', function ( event ) {
				var img = photoEl.querySelector( 'img' );
				if ( ! img || activeSlot < 0 ) {
					return;
				}
				var rect = img.getBoundingClientRect();
				slots[ activeSlot ].x = Math.round( ( event.clientX - rect.left ) / rect.width * 1000 ) / 10;
				slots[ activeSlot ].y = Math.round( ( event.clientY - rect.top ) / rect.height * 1000 ) / 10;

				// Move on to the next choice still missing its dot, so placing
				// every dot is just clicking around the photo in order.
				for ( var i = 1; i <= slots.length; i++ ) {
					var next = ( activeSlot + i ) % slots.length;
					if ( null === slots[ next ].x || undefined === slots[ next ].x ) {
						activeSlot = next;
						break;
					}
				}
				render();
			} );

			drawer.querySelectorAll( '[data-yp-count]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					setCount( slots.length + parseInt( button.getAttribute( 'data-yp-count' ), 10 ) );
				} );
			} );

			var photoIdInput = drawer.querySelector( '[data-yp-photo-id]' );
			var photoSelect = drawer.querySelector( '[data-yp-photo-select]' );
			YP.bindMediaPicker( {
				title: 'Select main photo',
				selectButton: photoSelect,
				removeButton: drawer.querySelector( '[data-yp-photo-remove]' ),
				idInput: photoIdInput,
				preview: drawer.querySelector( '[data-yp-photo-preview]' ),
				onSelect: function ( attachment ) {
					photoUrl = ( attachment.sizes && attachment.sizes.large && attachment.sizes.large.url ) || attachment.url;
					photoSelect.textContent = 'Change photo';
					renderPhoto();
				},
				onRemove: function () {
					photoUrl = '';
					photoSelect.textContent = 'Select main photo';
					renderPhoto();
				}
			} );

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				var errorEl = drawer.querySelector( '[data-yp-form-error]' );
				var saveButton = drawer.querySelector( '[data-yp-save]' );
				var name = form.name.value.trim();
				var problem = '';

				if ( ! name ) {
					problem = 'Name is required.';
				} else {
					slots.forEach( function ( slot, i ) {
						if ( ! problem && ! slot.name.trim() ) {
							problem = 'Give color choice ' + ( i + 1 ) + ' a part name.';
						} else if ( ! problem && ! slot.colors.length ) {
							problem = 'Pick at least one color for ' + slot.name + '.';
						}
					} );
				}

				if ( problem ) {
					errorEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( problem ) + '</p>';
					errorEl.scrollIntoView( { block: 'nearest' } );
					return;
				}

				var body = {
					title: name,
					content: form.description.value.trim(),
					status: form.active.checked ? 'publish' : 'draft',
					featured_media: parseInt( photoIdInput.value, 10 ) || 0,
					meta: {}
				};
				body.meta[ META.price ] = Math.max( 0, parseFloat( form.price.value ) || 0 );
				body.meta[ META.shipsIn ] = form.ships_in.value.trim();
				body.meta[ META.slots ] = slots.map( function ( slot ) {
					return {
						name: slot.name.trim(),
						hint: slot.hint.trim(),
						x: null === slot.x || undefined === slot.x ? null : slot.x,
						y: null === slot.y || undefined === slot.y ? null : slot.y,
						colors: slot.colors,
						default_id: slot.default_id || 0
					};
				} );
				if ( ! isEdit ) {
					body.menu_order = allPrints.length;
				}

				errorEl.innerHTML = '';
				saveButton.disabled = true;
				saveButton.textContent = 'Saving…';

				post( isEdit ? print.id : 0, body )
					.then( function () {
						YP.closeDrawer( drawer );
						load();
					} )
					.catch( function ( error ) {
						saveButton.disabled = false;
						saveButton.textContent = isEdit ? 'Save item' : 'Add item';
						errorEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
					} );
			} );

			render();
		}

		rowsEl.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( 'button' );
			if ( ! button ) {
				return;
			}
			if ( button.hasAttribute( 'data-yp-edit' ) ) {
				openForm( findById( button.getAttribute( 'data-yp-edit' ) ) );
			} else if ( button.hasAttribute( 'data-yp-delete' ) ) {
				deletePrint( findById( button.getAttribute( 'data-yp-delete' ) ) );
			}
		} );

		viewEl.querySelector( '[data-yp-add]' ).addEventListener( 'click', function () { openForm( null ); } );
		searchEl.addEventListener( 'input', renderRows );

		load();
	};
} )();
