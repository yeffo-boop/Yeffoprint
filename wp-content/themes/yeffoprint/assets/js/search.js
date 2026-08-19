/**
 * Predictive search for the Shop Labels gallery.
 *
 * Progressive enhancement over the plain <form role="search"> in the
 * header search drawer (parts/header.html): that form already works
 * with JS disabled (submits ?s= to the default WP search). With JS,
 * debounced keystrokes query the yp_template REST collection (which
 * yeffoprint-core extends to also match style/color/material tags —
 * see includes/search/class-template-search.php) and render a live
 * dropdown of matches, per PROJECT_SPEC §9.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintSearch === 'undefined' || ! yeffoprintSearch.restUrl ) {
		return;
	}

	var input = document.getElementById( 'yp-search-input' );
	var results = document.getElementById( 'yp-search-results' );

	if ( ! input || ! results ) {
		return;
	}

	var debounceTimer = null;
	var currentRequest = null;
	var MIN_CHARS = 2;
	var DEBOUNCE_MS = 250;

	function clearResults() {
		results.innerHTML = '';
	}

	function renderResults( templates ) {
		clearResults();

		if ( ! templates.length ) {
			var empty = document.createElement( 'p' );
			empty.className = 'yp-search-results__empty';
			empty.textContent = 'No designs found. Try a different search.';
			results.appendChild( empty );
			return;
		}

		var list = document.createElement( 'ul' );
		list.className = 'yp-search-results__list';

		templates.forEach( function ( template ) {
			var item = document.createElement( 'li' );
			var link = document.createElement( 'a' );
			link.className = 'yp-search-results__item';
			link.href = template.link;

			var title = document.createElement( 'span' );
			title.className = 'yp-search-results__title';
			title.textContent = template.title && template.title.rendered ? template.title.rendered : '';

			link.appendChild( title );
			item.appendChild( link );
			list.appendChild( item );
		} );

		results.appendChild( list );
	}

	function search( term ) {
		if ( currentRequest && typeof currentRequest.abort === 'function' ) {
			currentRequest.abort();
		}

		var controller = ( typeof AbortController !== 'undefined' ) ? new AbortController() : null;
		currentRequest = controller;

		var url = yeffoprintSearch.restUrl + '?search=' + encodeURIComponent( term ) + '&per_page=6&_fields=id,link,title';

		fetch( url, { signal: controller ? controller.signal : undefined } )
			.then( function ( response ) {
				return response.ok ? response.json() : [];
			} )
			.then( function ( templates ) {
				renderResults( Array.isArray( templates ) ? templates : [] );
			} )
			.catch( function ( error ) {
				if ( error && error.name === 'AbortError' ) {
					return;
				}
				clearResults();
			} );
	}

	input.addEventListener( 'input', function () {
		var term = input.value.trim();

		window.clearTimeout( debounceTimer );

		if ( term.length < MIN_CHARS ) {
			clearResults();
			return;
		}

		debounceTimer = window.setTimeout( function () {
			search( term );
		}, DEBOUNCE_MS );
	} );
} )();
