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
 * (field-schema-editor.js), shared with views/label-fields.js — this
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

	// Gallery filter/search facets on the Shop Labels page
	// (class-template-taxonomies.php) — reachable through WP core's own
	// /wp/v2/yp_template route the moment a taxonomy is registered
	// against a post type with show_in_rest: true (its terms come back
	// as a plain array of ids under a REST field named after the
	// taxonomy itself, since none of the 4 set a custom rest_base), so
	// no new admin controller endpoint is needed here — just fetch each
	// taxonomy's terms and read/write that same field name on the
	// Template object already being saved below.
	var TAXONOMIES = {
		yp_product_type: 'Product Type',
		yp_style: 'Style',
		yp_color: 'Color',
		yp_material_tag: 'Compatible Material'
	};

	// The category the Templates list filters and sorts by. Direct
	// request: "Can i get the ability to filter/sort templates on my
	// admin panel? Im starting to get a lot and it would be great to
	// sort by (Peptide, Cosmetic, Skincare, etc)." — those are exactly
	// this taxonomy's terms, already assigned per Template in the Tags
	// panel below and already driving the Shop Labels gallery's own
	// primary "Show:" row, so the list reuses them rather than adding a
	// second, admin-only category field that could drift out of sync.
	var CATEGORY_TAXONOMY = 'yp_product_type';

	// Term meta holding each category's tile image on the homepage "What
	// are you labeling?" section (class-product-type-image.php). Direct
	// request: every tile showed the same newest-template bottle, "give
	// me the ability to set the category image".
	var CATEGORY_IMAGE_META = 'yp_product_type_image';

	var SORTS = {
		'title-asc': 'Name A–Z',
		'title-desc': 'Name Z–A',
		'date-desc': 'Newest first',
		'date-asc': 'Oldest first',
		'popularity-desc': 'Most popular',
		category: 'Category'
	};

	// Sentinel filter value for Templates with no category term at all —
	// can't collide with a real term id, which is always a positive int.
	var UNCATEGORIZED = 'none';

	function endpoint( path ) {
		return yeffoprintAdminApp.wpApiUrl + 'yp_template' + ( path || '' );
	}

	function adminEndpoint( id ) {
		return yeffoprintAdminApp.restUrl + 'admin/template/' + id;
	}

	/** Read-only stand-in for the interactive field-schema-editor.js widget, shown instead of it whenever a shared default preset (Settings → Label Configurator) is active — see sharedPreset above. */
	function renderSharedFieldsReadOnly( container, preset ) {
		container.innerHTML =
			'<p class="yp-field__hint">Every template shares the same customization fields. Add, edit, or remove them on the Label Fields screen and the change applies here automatically. <a href="#/label-fields">Edit label fields &rarr;</a></p>' +
			( preset.fields.length
				? '<table class="yp-record-table"><thead><tr><th>Label</th><th>Type</th><th>Required</th></tr></thead><tbody>' +
					preset.fields.map( function ( field ) {
						return (
							'<tr>' +
								'<td>' + YP.escapeHtml( field.label ) + '</td>' +
								'<td>' + YP.escapeHtml( yeffoprintAdminApp.fieldSchema.types[ field.type ] || field.type ) + '</td>' +
								'<td>' + ( field.required ? 'Yes' : 'No' ) + '</td>' +
							'</tr>'
						);
					} ).join( '' ) +
				'</tbody></table>'
				: '<p class="yp-field__hint">The shared preset has no fields yet.</p>' );
	}

	/* ---------- Color choices (YeffoPrint_Label_Color_Meta) ---------- */

	var COLOR_TARGETS = [
		{ key: 'background', label: 'Background', hint: 'Fills behind the artwork' },
		{ key: 'text', label: 'Text', hint: 'Every label field’s text' },
		{ key: 'layer', label: 'Artwork part', hint: 'Upload a shape layer' }
	];
	var MAX_COLOR_CHOICES = 4;

	function hasDot( choice ) {
		return null !== choice.x && undefined !== choice.x && null !== choice.y && undefined !== choice.y;
	}

	/**
	 * The Template editor's "Color choices" panel — same shape as a 3D
	 * print's color choices (views/prints.js): one card per choice, and
	 * the artwork on the right to click each choice's dot onto.
	 */
	function createColorChoicesEditor( config ) {
		var container = config.container;
		var labelColors = config.labelColors || [];
		var previewUrl = config.previewUrl || '';
		var choices = ( config.choices || [] ).map( function ( c ) {
			return {
				name: c.name || '',
				hint: c.hint || '',
				target: c.target || 'background',
				layer_id: c.layer_id || 0,
				layer_url: c.layer_url || '',
				x: c.x,
				y: c.y,
				colors: ( c.colors || [] ).slice(),
				default_id: c.default_id || 0,
				any_color: !! c.any_color
			};
		} );
		var active = choices.length ? 0 : -1;

		container.innerHTML =
			'<p class="yp-field__hint">Parts of this label the customer can recolor. Each one gets a numbered dot on the product page. Every choice offers all your Label Colors plus an Any color picker. Leave empty and the label prints exactly as designed. Background needs artwork with a see-through background (PNG or SVG); Artwork part needs its shape uploaded as its own layer, the same size as the artwork.</p>' +
			'<div class="yp-print-editor yp-color-choices">' +
				'<div class="yp-print-editor__slots">' +
					'<div data-yp-choices></div>' +
					'<button type="button" class="yp-color-choices__add" data-yp-choice-add>+ Add color choice</button>' +
				'</div>' +
				'<div class="yp-print-editor__photo">' +
					'<h3 class="yp-print-editor__heading">Where each color goes</h3>' +
					'<p class="yp-print-editor__hint" data-yp-choice-hint></p>' +
					'<div class="yp-print-photo" data-yp-choice-photo></div>' +
					'<p class="yp-field__hint"><a href="#/label-colors">Edit Label Colors</a></p>' +
				'</div>' +
			'</div>';

		var listEl = container.querySelector( '[data-yp-choices]' );
		var addEl = container.querySelector( '[data-yp-choice-add]' );
		var photoEl = container.querySelector( '[data-yp-choice-photo]' );
		var hintEl = container.querySelector( '[data-yp-choice-hint]' );

		function colorById( id ) {
			for ( var i = 0; i < labelColors.length; i++ ) {
				if ( labelColors[ i ].id === id ) {
					return labelColors[ i ];
				}
			}
			return null;
		}

		function hexOf( color ) {
			return ( color.meta && color.meta._yp_label_color_hex ) || '#888888';
		}

		function renderList() {
			addEl.hidden = choices.length >= MAX_COLOR_CHOICES;

			if ( ! labelColors.length ) {
				listEl.innerHTML = '<p class="yp-form__error">No active Label Colors yet. <a href="#/label-colors">Add your colors</a> first, then come back.</p>';
				addEl.hidden = true;
				return;
			}

			if ( ! choices.length ) {
				listEl.innerHTML = '<p class="yp-field__hint">No color choices. Customers get this label exactly as designed.</p>';
				return;
			}

			listEl.innerHTML = choices.map( function ( choice, i ) {
				return (
					'<div class="yp-print-slot' + ( i === active ? ' is-active' : '' ) + '" data-yp-choice="' + i + '">' +
						'<div class="yp-print-slot__head">' +
							'<span class="yp-print-num">' + ( i + 1 ) + '</span>' +
							'<input type="text" data-yp-choice-field="name" placeholder="Name, e.g. Background" value="' + YP.escapeAttr( choice.name ) + '" aria-label="Choice name" />' +
							'<input type="text" data-yp-choice-field="hint" placeholder="Short hint for customers" value="' + YP.escapeAttr( choice.hint ) + '" aria-label="Hint" />' +
							'<button type="button" class="yp-print-where' + ( hasDot( choice ) ? ' is-set' : '' ) + '" data-yp-choice-place>' + ( hasDot( choice ) ? '&#10003; Dot placed' : 'Place dot' ) + '</button>' +
						'</div>' +
						'<p class="yp-color-choices__label">What it colors</p>' +
						'<div class="yp-color-choices__targets">' +
							COLOR_TARGETS.map( function ( t ) {
								return '<button type="button" class="yp-color-choices__target' + ( t.key === choice.target ? ' is-on' : '' ) + '" data-yp-choice-target="' + t.key + '" aria-pressed="' + ( t.key === choice.target ? 'true' : 'false' ) + '"><strong>' + t.label + '</strong><span>' + t.hint + '</span></button>';
							} ).join( '' ) +
						'</div>' +
						( 'layer' === choice.target
							? '<div class="yp-color-choices__layer">' +
								( choice.layer_url ? '<img src="' + YP.escapeAttr( choice.layer_url ) + '" alt="" />' : '<span>No layer yet. This choice stays hidden until one is uploaded.</span>' ) +
								'<button type="button" class="yp-row-action" data-yp-choice-layer>' + ( choice.layer_url ? 'Change layer' : 'Upload layer' ) + '</button>' +
							'</div>'
							: '' ) +
						'<div class="yp-print-slot__foot">' +
							'<label>Starts on <select data-yp-choice-default aria-label="Starting color">' +
								labelColors.map( function ( c ) {
									return '<option value="' + c.id + '"' + ( choice.default_id === c.id ? ' selected' : '' ) + '>' + YP.escapeHtml( c.title.raw ) + '</option>';
								} ).join( '' ) +
							'</select></label>' +
							'<button type="button" class="yp-row-action" data-yp-choice-remove>Remove choice</button>' +
						'</div>' +
					'</div>'
				);
			} ).join( '' );
		}

		function renderPhoto() {
			if ( ! previewUrl ) {
				photoEl.innerHTML = '<div class="yp-print-photo__empty">Set the artwork image above, then click it to place each color’s dot.</div>';
				hintEl.textContent = '';
				return;
			}

			photoEl.innerHTML = '<img src="' + YP.escapeAttr( previewUrl ) + '" alt="" draggable="false" />' +
				choices.map( function ( choice, i ) {
					return hasDot( choice )
						? '<span class="yp-print-dot' + ( i === active ? ' is-active' : '' ) + '" style="left:' + choice.x + '%;top:' + choice.y + '%">' + ( i + 1 ) + '</span>'
						: '';
				} ).join( '' );

			hintEl.innerHTML = active >= 0
				? 'Placing dot <strong>' + ( active + 1 ) + ( choices[ active ].name ? ' · ' + YP.escapeHtml( choices[ active ].name ) : '' ) + '</strong>. Click that part on the artwork.'
				: 'Add a color choice to place its dot.';
		}

		function render() {
			renderList();
			renderPhoto();
		}

		function pickLayer( choice ) {
			if ( typeof wp === 'undefined' || ! wp.media ) {
				return;
			}
			var frame = wp.media( { title: 'Select shape layer', library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				choice.layer_id = attachment.id;
				choice.layer_url = ( attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url );
				render();
			} );
			frame.open();
		}

		addEl.addEventListener( 'click', function () {
			if ( choices.length >= MAX_COLOR_CHOICES ) {
				return;
			}
			var colors = labelColors.map( function ( c ) { return c.id; } );
			var used = choices.map( function ( c ) { return c.target; } );
			var target = used.indexOf( 'background' ) === -1 ? 'background' : ( used.indexOf( 'text' ) === -1 ? 'text' : 'layer' );
			choices.push( {
				name: 'layer' === target ? '' : ( 'background' === target ? 'Background' : 'Text' ),
				hint: '',
				target: target,
				layer_id: 0,
				layer_url: '',
				x: null,
				y: null,
				colors: colors,
				default_id: colors[ 'text' === target && colors.length > 1 ? 1 : 0 ] || 0,
				any_color: false
			} );
			active = choices.length - 1;
			render();
		} );

		listEl.addEventListener( 'input', function ( event ) {
			var field = event.target.getAttribute( 'data-yp-choice-field' );
			var card = event.target.closest( '[data-yp-choice]' );
			if ( field && card ) {
				choices[ parseInt( card.getAttribute( 'data-yp-choice' ), 10 ) ][ field ] = event.target.value;
				if ( 'name' === field ) {
					renderPhoto();
				}
			}
		} );

		listEl.addEventListener( 'change', function ( event ) {
			var card = event.target.closest( '[data-yp-choice]' );
			if ( card && event.target.hasAttribute( 'data-yp-choice-default' ) ) {
				choices[ parseInt( card.getAttribute( 'data-yp-choice' ), 10 ) ].default_id = parseInt( event.target.value, 10 ) || 0;
			}
		} );

		listEl.addEventListener( 'focusin', function ( event ) {
			var card = event.target.closest( '[data-yp-choice]' );
			var index = card ? parseInt( card.getAttribute( 'data-yp-choice' ), 10 ) : -1;
			if ( card && index !== active ) {
				active = index;
				listEl.querySelectorAll( '[data-yp-choice]' ).forEach( function ( el ) {
					el.classList.toggle( 'is-active', el === card );
				} );
				renderPhoto();
			}
		} );

		listEl.addEventListener( 'click', function ( event ) {
			var card = event.target.closest( '[data-yp-choice]' );
			if ( ! card ) {
				return;
			}
			var index = parseInt( card.getAttribute( 'data-yp-choice' ), 10 );
			var choice = choices[ index ];
			active = index;

			var targetButton = event.target.closest( '[data-yp-choice-target]' );
			if ( targetButton ) {
				choice.target = targetButton.getAttribute( 'data-yp-choice-target' );
				render();
				return;
			}

			if ( event.target.closest( '[data-yp-choice-layer]' ) ) {
				pickLayer( choice );
				return;
			}

			if ( event.target.closest( '[data-yp-choice-remove]' ) ) {
				choices.splice( index, 1 );
				active = Math.min( index, choices.length - 1 );
				render();
				return;
			}

			if ( event.target.closest( '[data-yp-choice-place]' ) ) {
				render();
				photoEl.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
				return;
			}

			if ( ! event.target.closest( 'input, select' ) ) {
				render();
			}
		} );

		photoEl.addEventListener( 'click', function ( event ) {
			var img = photoEl.querySelector( 'img' );
			if ( ! img || active < 0 ) {
				return;
			}
			var rect = img.getBoundingClientRect();
			choices[ active ].x = Math.round( ( event.clientX - rect.left ) / rect.width * 1000 ) / 10;
			choices[ active ].y = Math.round( ( event.clientY - rect.top ) / rect.height * 1000 ) / 10;

			// Move on to the next choice still missing its dot, same as
			// views/prints.js.
			for ( var i = 1; i <= choices.length; i++ ) {
				var next = ( active + i ) % choices.length;
				if ( ! hasDot( choices[ next ] ) ) {
					active = next;
					break;
				}
			}
			render();
		} );

		render();

		return {
			getChoices: function () {
				return choices.map( function ( c ) {
					return {
						name: c.name,
						hint: c.hint,
						target: c.target,
						layer_id: c.layer_id,
						x: c.x,
						y: c.y,
						colors: c.colors,
						default_id: c.default_id,
						any_color: c.any_color
					};
				} );
			},
			setPreviewImage: function ( url ) {
				previewUrl = url || '';
				renderPhoto();
			}
		};
	}

	YP.views.templates = function ( viewEl ) {
		var allTemplates = [];
		var categoryTerms = [];
		var categoryNames = {};
		// Direct request: "I want to use the default template preset I
		// made as the template for all current and future labels. IF I
		// add a field there, it adds to all templates." Non-null means a
		// shared Field Preset is active site-wide (Settings → Label
		// Configurator) — every Template's editor below renders the fields
		// read-only instead of the interactive repeater, since editing them
		// per-Template would be silently discarded on save anyway
		// (class-admin-template-controller.php's own guard).
		var sharedPreset = yeffoprintAdminApp.defaultFieldPreset || null;

		viewEl.innerHTML =
			'<p class="yp-app__intro">Every label design customers can pick from — artwork, customization fields, and which Sizes/Materials each one supports.</p>' +
			'<div class="yp-list-toolbar">' +
				'<div class="yp-list-toolbar__filters">' +
					'<input type="text" class="yp-list-toolbar__search" data-yp-search placeholder="Search templates&hellip;" aria-label="Search templates" />' +
					'<select class="yp-list-toolbar__select" data-yp-category-filter aria-label="Filter by category"><option value="">All categories</option></select>' +
					'<select class="yp-list-toolbar__select" data-yp-sort aria-label="Sort templates">' +
						Object.keys( SORTS ).map( function ( key ) {
							return '<option value="' + key + '">Sort: ' + YP.escapeHtml( SORTS[ key ] ) + '</option>';
						} ).join( '' ) +
					'</select>' +
				'</div>' +
				'<button type="button" class="wp-block-button__link is-style-outline" data-yp-category-images>Category images</button>' +
				'<button type="button" class="wp-block-button__link is-style-accent" data-yp-add>+ Add Template</button>' +
			'</div>' +
			'<div class="yp-record-card"><table class="yp-record-table"><thead><tr>' +
				'<th>Template</th><th>Category</th><th>Badge</th><th>Featured</th><th>Popularity</th><th>Status</th><th></th>' +
			'</tr></thead><tbody data-yp-rows><tr class="yp-empty-row"><td colspan="7">Loading&hellip;</td></tr></tbody></table></div>';

		var rowsEl = viewEl.querySelector( '[data-yp-rows]' );
		var searchEl = viewEl.querySelector( '[data-yp-search]' );
		var categoryEl = viewEl.querySelector( '[data-yp-category-filter]' );
		var sortEl = viewEl.querySelector( '[data-yp-sort]' );

		/**
		 * Every Template, not just the first 100 — the list is growing
		 * past what one REST page holds, and filtering/sorting happens
		 * client-side over the whole set. Keeps asking for the next page
		 * until one comes back short.
		 */
		function fetchAllTemplates( page, collected ) {
			return YP.request( endpoint( '?context=edit&status=publish,draft&per_page=100&page=' + page + '&orderby=title&order=asc&_embed=1' ) )
				.then( function ( templates ) {
					templates = templates || [];
					collected = collected.concat( templates );
					return templates.length === 100 ? fetchAllTemplates( page + 1, collected ) : collected;
				} )
				.catch( function ( error ) {
					// Asking one page past the end (exactly a multiple of 100
					// Templates) is a 400 from core, not an empty array.
					if ( page > 1 && error.body && 'rest_post_invalid_page_number' === error.body.code ) {
						return collected;
					}
					throw error;
				} );
		}

		function load() {
			rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="7">Loading&hellip;</td></tr>';
			Promise.all( [
				fetchAllTemplates( 1, [] ),
				YP.request( yeffoprintAdminApp.wpApiUrl + CATEGORY_TAXONOMY + '?per_page=100&orderby=name&order=asc' )
			] )
				.then( function ( results ) {
					allTemplates = results[ 0 ];
					categoryTerms = results[ 1 ] || [];
					categoryNames = {};
					categoryTerms.forEach( function ( term ) { categoryNames[ term.id ] = term.name; } );
					renderCategoryOptions();
					renderRows( allTemplates );
				} )
				.catch( function ( error ) {
					rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="7">Couldn’t load templates: ' + YP.escapeHtml( error.message ) + '</td></tr>';
				} );
		}

		function templateCategoryIds( template ) {
			return Array.isArray( template[ CATEGORY_TAXONOMY ] ) ? template[ CATEGORY_TAXONOMY ] : [];
		}

		/** Category names for one Template, alphabetical — the same order the filter dropdown lists them in. */
		function templateCategoryNames( template ) {
			return templateCategoryIds( template )
				.map( function ( id ) { return categoryNames[ id ]; } )
				.filter( Boolean )
				.sort( function ( a, b ) { return a.localeCompare( b ); } );
		}

		/** Rebuilt after every load() so counts stay right after an add/edit/delete, keeping whatever was already selected. */
		function renderCategoryOptions() {
			var current = categoryEl.value;
			var counts = {};
			var uncategorized = 0;
			allTemplates.forEach( function ( template ) {
				var ids = templateCategoryIds( template ).filter( function ( id ) { return categoryNames[ id ]; } );
				if ( ! ids.length ) {
					uncategorized++;
				}
				ids.forEach( function ( id ) { counts[ id ] = ( counts[ id ] || 0 ) + 1; } );
			} );

			categoryEl.innerHTML =
				'<option value="">All categories (' + allTemplates.length + ')</option>' +
				categoryTerms.map( function ( term ) {
					return '<option value="' + term.id + '">' + YP.escapeHtml( term.name ) + ' (' + ( counts[ term.id ] || 0 ) + ')</option>';
				} ).join( '' ) +
				( uncategorized ? '<option value="' + UNCATEGORIZED + '">Uncategorized (' + uncategorized + ')</option>' : '' );

			categoryEl.value = current;
			if ( categoryEl.value !== current ) {
				categoryEl.value = '';
			}
		}

		function compareTemplates( sort ) {
			function byTitle( a, b ) { return a.title.raw.localeCompare( b.title.raw, undefined, { sensitivity: 'base', numeric: true } ); }
			function popularity( t ) { return t.meta ? parseInt( t.meta[ META.popularity ], 10 ) || 0 : 0; }

			switch ( sort ) {
				case 'title-desc':
					return function ( a, b ) { return byTitle( b, a ); };
				case 'date-desc':
					return function ( a, b ) { return b.date.localeCompare( a.date ) || byTitle( a, b ); };
				case 'date-asc':
					return function ( a, b ) { return a.date.localeCompare( b.date ) || byTitle( a, b ); };
				case 'popularity-desc':
					return function ( a, b ) { return popularity( b ) - popularity( a ) || byTitle( a, b ); };
				case 'category':
					// Grouped by (first) category name, uncategorized last,
					// then by name within each group.
					return function ( a, b ) {
						var ca = templateCategoryNames( a )[ 0 ];
						var cb = templateCategoryNames( b )[ 0 ];
						if ( ca !== cb ) {
							if ( ! ca ) { return 1; }
							if ( ! cb ) { return -1; }
							return ca.localeCompare( cb );
						}
						return byTitle( a, b );
					};
				default:
					return byTitle;
			}
		}

		function renderRows( templates ) {
			var query = ( searchEl.value || '' ).trim().toLowerCase();
			var category = categoryEl.value;
			var filtered = templates.filter( function ( t ) {
				if ( query && t.title.raw.toLowerCase().indexOf( query ) === -1 ) {
					return false;
				}
				if ( UNCATEGORIZED === category ) {
					return ! templateCategoryNames( t ).length;
				}
				if ( category ) {
					return templateCategoryIds( t ).indexOf( parseInt( category, 10 ) ) !== -1;
				}
				return true;
			} ).sort( compareTemplates( sortEl.value ) );

			if ( ! filtered.length ) {
				rowsEl.innerHTML = '<tr class="yp-empty-row"><td colspan="7">' + ( templates.length ? 'No templates match your search or category.' : 'No templates yet — add the first one above.' ) + '</td></tr>';
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
				var categories = templateCategoryNames( template );

				return (
					'<tr data-id="' + template.id + '">' +
						'<td><div class="yp-record-name">' +
							( thumb ? '<img class="yp-swatch" src="' + YP.escapeAttr( thumb ) + '" alt="" style="border-radius: var(--wp--custom--radius--control);" />' : '<span class="yp-swatch" style="border-radius: var(--wp--custom--radius--control);"></span>' ) +
							YP.escapeHtml( template.title.raw ) +
						'</div></td>' +
						'<td>' + ( categories.length ? categories.map( function ( name ) { return '<span class="yp-chip">' + YP.escapeHtml( name ) + '</span>'; } ).join( ' ' ) : '&mdash;' ) + '</td>' +
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

		/* ---------- Category images drawer ---------- */

		function categoryImageId( term ) {
			return term.meta ? parseInt( term.meta[ CATEGORY_IMAGE_META ], 10 ) || 0 : 0;
		}

		function openCategoryImages() {
			var drawer = document.createElement( 'div' );
			drawer.className = 'yp-drawer';
			drawer.setAttribute( 'aria-hidden', 'true' );
			drawer.innerHTML =
				'<div class="yp-drawer__backdrop"></div>' +
				'<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="Category images">' +
					'<div class="yp-drawer__header"><span>Category images</span>' +
						'<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="Close">&times;</button>' +
					'</div>' +
					'<div class="yp-drawer__body">' +
						'<form class="yp-form" data-yp-form>' +
							'<div data-yp-form-error></div>' +
							'<p class="yp-field__hint">The picture on each category’s tile in the homepage “What are you labeling?” section. Leave one empty and it uses a template from that category that isn’t already on another tile.</p>' +
							( categoryTerms.length
								? categoryTerms.map( function ( term ) {
									var imageId = categoryImageId( term );
									return (
										'<div class="yp-field"><label>' + YP.escapeHtml( term.name ) + '</label>' +
											'<div class="yp-media-field">' +
												'<div class="yp-media-field__preview" data-yp-cat-preview="' + term.id + '"></div>' +
												'<div class="yp-media-field__buttons">' +
													'<input type="hidden" data-yp-cat-id="' + term.id + '" value="' + ( imageId || '' ) + '" />' +
													'<button type="button" class="wp-block-button__link is-style-outline" data-yp-cat-select="' + term.id + '">Select image</button>' +
													'<button type="button" class="yp-row-action" data-yp-cat-remove="' + term.id + '"' + ( imageId ? '' : ' hidden' ) + '>Remove</button>' +
												'</div>' +
											'</div>' +
										'</div>'
									);
								} ).join( '' )
								: '<p class="yp-field__hint">No categories yet — tag a template with a Product Type first.</p>' ) +
							'<div class="yp-form__actions">' +
								'<button type="submit" class="wp-block-button__link is-style-accent" data-yp-save' + ( categoryTerms.length ? '' : ' disabled' ) + '>Save images</button>' +
								'<button type="button" class="wp-block-button__link is-style-outline" data-yp-drawer-close>Cancel</button>' +
							'</div>' +
						'</form>' +
					'</div>' +
				'</div>';

			document.body.appendChild( drawer );
			YP.initDrawer( drawer );
			YP.openDrawer( drawer );

			categoryTerms.forEach( function ( term ) {
				YP.bindMediaPicker( {
					title: 'Select image for ' + term.name,
					selectButton: drawer.querySelector( '[data-yp-cat-select="' + term.id + '"]' ),
					removeButton: drawer.querySelector( '[data-yp-cat-remove="' + term.id + '"]' ),
					idInput: drawer.querySelector( '[data-yp-cat-id="' + term.id + '"]' ),
					preview: drawer.querySelector( '[data-yp-cat-preview="' + term.id + '"]' )
				} );
			} );

			var setIds = categoryTerms.map( categoryImageId ).filter( Boolean );
			if ( setIds.length ) {
				YP.request( yeffoprintAdminApp.wpApiUrl + 'media?include=' + setIds.join( ',' ) + '&per_page=100' )
					.then( function ( attachments ) {
						( attachments || [] ).forEach( function ( attachment ) {
							categoryTerms.forEach( function ( term ) {
								var preview = drawer.querySelector( '[data-yp-cat-preview="' + term.id + '"]' );
								var input = drawer.querySelector( '[data-yp-cat-id="' + term.id + '"]' );
								// Skip one the admin already re-picked or removed while this was loading.
								if ( preview && input && parseInt( input.value, 10 ) === attachment.id && categoryImageId( term ) === attachment.id ) {
									preview.innerHTML = '<img src="' + YP.escapeAttr( attachment.source_url ) + '" alt="" />';
								}
							} );
						} );
					} )
					.catch( function () {} );
			}

			drawer.querySelector( '[data-yp-form]' ).addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				var errorEl = drawer.querySelector( '[data-yp-form-error]' );
				var saveButton = drawer.querySelector( '[data-yp-save]' );

				var changed = categoryTerms.filter( function ( term ) {
					var value = parseInt( drawer.querySelector( '[data-yp-cat-id="' + term.id + '"]' ).value, 10 ) || 0;
					return value !== categoryImageId( term );
				} );

				if ( ! changed.length ) {
					YP.closeDrawer( drawer );
					return;
				}

				errorEl.innerHTML = '';
				saveButton.disabled = true;
				saveButton.textContent = 'Saving…';

				Promise.all( changed.map( function ( term ) {
					var body = { meta: {} };
					body.meta[ CATEGORY_IMAGE_META ] = parseInt( drawer.querySelector( '[data-yp-cat-id="' + term.id + '"]' ).value, 10 ) || 0;
					return YP.request( yeffoprintAdminApp.wpApiUrl + CATEGORY_TAXONOMY + '/' + term.id, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } );
				} ) )
					.then( function () {
						YP.closeDrawer( drawer );
						load();
					} )
					.catch( function ( error ) {
						saveButton.disabled = false;
						saveButton.textContent = 'Save images';
						errorEl.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
					} );
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
							'<div class="yp-panel">' +
								'<div class="yp-panel__head"><h2>Color choices</h2></div>' +
								'<div data-yp-color-choices-container><p class="yp-field__hint">Loading&hellip;</p></div>' +
							'</div>' +
							'<div class="yp-panel">' +
								'<div class="yp-panel__head"><h2>Tags</h2></div>' +
								'<p class="yp-field__hint">Drive the filters on the Shop Labels gallery page &mdash; Product Type is the main "peptide vial labels vs. other product labels" split; Style/Color/Material are finer facets.</p>' +
								Object.keys( TAXONOMIES ).map( function ( taxonomy ) {
									return (
										'<div class="yp-field">' +
											'<label>' + YP.escapeHtml( TAXONOMIES[ taxonomy ] ) + '</label>' +
											'<div class="yp-admin-checklist" data-yp-taxonomy-checklist="' + taxonomy + '"><p class="yp-field__hint">Loading&hellip;</p></div>' +
										'</div>'
									);
								} ).join( '' ) +
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
			var colorChoicesEditor = null;
			var pendingPreviewUrl = featuredMediaUrl;

			YP.bindMediaPicker( {
				title: 'Select artwork',
				selectButton: drawer.querySelector( '[data-yp-art-select]' ),
				removeButton: drawer.querySelector( '[data-yp-art-remove]' ),
				idInput: drawer.querySelector( '[data-yp-art-id]' ),
				preview: drawer.querySelector( '[data-yp-art-preview]' ),
				onSelect: function ( attachment ) {
					pendingPreviewUrl = attachment.url;
					if ( fieldSchemaEditor && fieldSchemaEditor.setPreviewImage ) { fieldSchemaEditor.setPreviewImage( pendingPreviewUrl ); }
					if ( colorChoicesEditor ) { colorChoicesEditor.setPreviewImage( pendingPreviewUrl ); }
				},
				onRemove: function () {
					pendingPreviewUrl = '';
					if ( fieldSchemaEditor && fieldSchemaEditor.setPreviewImage ) { fieldSchemaEditor.setPreviewImage( '' ); }
					if ( colorChoicesEditor ) { colorChoicesEditor.setPreviewImage( '' ); }
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

			var taxonomyKeys = Object.keys( TAXONOMIES );

			var checklistPromises = [
				YP.request( yeffoprintAdminApp.wpApiUrl + 'yp_size?status=publish&per_page=100&orderby=menu_order&order=asc' ),
				YP.request( yeffoprintAdminApp.wpApiUrl + 'yp_material?status=publish&per_page=100&orderby=menu_order&order=asc' ),
				YP.request( yeffoprintAdminApp.wpApiUrl + 'yp_field_preset?status=publish&per_page=100&orderby=title&order=asc' )
			].concat( taxonomyKeys.map( function ( taxonomy ) {
				return YP.request( yeffoprintAdminApp.wpApiUrl + taxonomy + '?per_page=100&orderby=name&order=asc' );
			} ) ).concat( [
				YP.request( yeffoprintAdminApp.wpApiUrl + 'yp_label_color?context=edit&status=publish&per_page=100&orderby=menu_order&order=asc' )
			] );

			Promise.all( checklistPromises ).then( function ( results ) {
				var labelColors = results[ 3 + taxonomyKeys.length ] || [];
				var sizes = results[ 0 ] || [];
				var materials = results[ 1 ] || [];
				var presetPosts = results[ 2 ] || [];
				var taxonomyTerms = {};
				taxonomyKeys.forEach( function ( taxonomy, index ) {
					taxonomyTerms[ taxonomy ] = results[ 3 + index ] || [];
				} );

				var loadGap = isEdit
					? YP.request( adminEndpoint( template.id ) )
					: Promise.resolve( { compatible_sizes: [], compatible_materials: [], field_schema: [], color_choices: yeffoprintAdminApp.defaultColorChoices || [] } );

				var loadPresets = ( ! sharedPreset && presetPosts.length )
					? Promise.all( presetPosts.map( function ( p ) {
						return YP.request( yeffoprintAdminApp.restUrl + 'admin/field-preset/' + p.id ).then( function ( data ) {
							return { id: p.id, name: p.title.rendered, fields: data.field_schema || [] };
						} );
					} ) )
					: Promise.resolve( [] );

				return Promise.all( [ loadGap, loadPresets ] ).then( function ( results2 ) {
					renderChecklist( drawer.querySelector( '[data-yp-sizes-checklist]' ), sizes, results2[ 0 ].compatible_sizes, 'compat-size' );
					renderChecklist( drawer.querySelector( '[data-yp-materials-checklist]' ), materials, results2[ 0 ].compatible_materials, 'compat-material' );

					taxonomyKeys.forEach( function ( taxonomy ) {
						var selectedTermIds = isEdit && Array.isArray( template[ taxonomy ] ) ? template[ taxonomy ] : [];
						renderTermChecklist( drawer.querySelector( '[data-yp-taxonomy-checklist="' + taxonomy + '"]' ), taxonomyTerms[ taxonomy ], selectedTermIds, taxonomy );
					} );

					if ( sharedPreset ) {
						renderSharedFieldsReadOnly( drawer.querySelector( '[data-yp-field-schema-container]' ), sharedPreset );
						// Shape-compatible stand-in for the real editor's own
						// return value (save()'s getFields() callback below
						// doesn't know or care which one it's calling) — the
						// server ignores whatever field_schema this sends for
						// a Template anyway once a shared preset is active
						// (class-admin-template-controller.php), so sending
						// the correct, already-shared list back is just tidy,
						// not load-bearing.
						fieldSchemaEditor = { getFields: function () { return sharedPreset.fields; } };
					} else {
						fieldSchemaEditor = YP.createFieldSchemaEditor( {
							container: drawer.querySelector( '[data-yp-field-schema-container]' ),
							fields: results2[ 0 ].field_schema,
							types: yeffoprintAdminApp.fieldSchema.types,
							alignments: yeffoprintAdminApp.fieldSchema.alignments,
							formattingRules: yeffoprintAdminApp.fieldSchema.formattingRules,
							previewBehaviors: yeffoprintAdminApp.fieldSchema.previewBehaviors,
							qrMinMaxChars: yeffoprintAdminApp.fieldSchema.qrMinMaxChars,
							qrMaxChars: yeffoprintAdminApp.fieldSchema.qrMaxChars,
							cornerStyleOptions: yeffoprintAdminApp.fieldSchema.cornerStyleOptions,
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
					}

					colorChoicesEditor = createColorChoicesEditor( {
						container: drawer.querySelector( '[data-yp-color-choices-container]' ),
						choices: results2[ 0 ].color_choices || [],
						labelColors: labelColors,
						previewUrl: pendingPreviewUrl
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

			/** Same shape as renderChecklist() above, but for taxonomy terms (which have a plain `name`, not a `title.rendered`). */
			function renderTermChecklist( container, terms, selectedIds, taxonomy ) {
				if ( ! terms.length ) {
					container.innerHTML = '<p class="yp-field__hint">No terms yet — add one from the Shop Labels gallery filters, or via wp-admin.</p>';
					return;
				}
				container.innerHTML = terms.map( function ( term ) {
					var checked = selectedIds.indexOf( term.id ) !== -1;
					return '<label><input type="checkbox" data-taxonomy-term="' + taxonomy + '" value="' + term.id + '"' + ( checked ? ' checked' : '' ) + ' /> ' + YP.escapeHtml( term.name ) + '</label>';
				} ).join( '' );
			}

			drawer.querySelector( '[data-yp-form]' ).addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				save( template, drawer, function () { return fieldSchemaEditor.getFields(); }, function () { return colorChoicesEditor ? colorChoicesEditor.getChoices() : null; } );
			} );
		}

		function save( existing, drawer, getFields, getColorChoices ) {
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

			Object.keys( TAXONOMIES ).forEach( function ( taxonomy ) {
				coreBody[ taxonomy ] = Array.prototype.map.call(
					drawer.querySelectorAll( '[data-taxonomy-term="' + taxonomy + '"]:checked' ),
					function ( el ) { return parseInt( el.value, 10 ); }
				);
			} );

			var coreUrl = existing ? endpoint( '/' + existing.id ) : endpoint();

			YP.request( coreUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( coreBody ) } )
				.then( function ( saved ) {
					var gapBody = {
						compatible_sizes: Array.prototype.map.call( drawer.querySelectorAll( '[data-compat-size]:checked' ), function ( el ) { return parseInt( el.getAttribute( 'data-compat-size' ), 10 ); } ),
						compatible_materials: Array.prototype.map.call( drawer.querySelectorAll( '[data-compat-material]:checked' ), function ( el ) { return parseInt( el.getAttribute( 'data-compat-material' ), 10 ); } ),
						field_schema: getFields()
					};
					var colorChoices = getColorChoices();
					if ( colorChoices ) {
						gapBody.color_choices = colorChoices;
					}
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
		viewEl.querySelector( '[data-yp-category-images]' ).addEventListener( 'click', openCategoryImages );
		searchEl.addEventListener( 'input', function () { renderRows( allTemplates ); } );
		categoryEl.addEventListener( 'change', function () { renderRows( allTemplates ); } );
		sortEl.addEventListener( 'change', function () { renderRows( allTemplates ); } );

		load();
	};
} )();
