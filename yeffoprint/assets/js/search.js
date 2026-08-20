/**
 * Predictive search for the Shop Labels gallery.
 *
 * Progressive enhancement over each plain <form role="search">: those
 * forms already work with JS disabled (submit ?s= to the default WP
 * search). With JS, debounced keystrokes query the yp_template REST
 * collection (which yeffoprint-core extends to also match style/
 * color/material tags — see includes/search/class-template-search.php)
 * and render a live dropdown of matches, per PROJECT_SPEC §9.
 *
 * Runs once per `[data-yp-search-scope]` found on the page — as of the
 * persistent search bar under the header (parts/header.html), that's
 * two independent instances (the bar itself, and the header's search
 * drawer), each with its own input/results pair and its own debounce/
 * in-flight-request state, so typing in one never clobbers the other.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintSearch === 'undefined' || ! yeffoprintSearch.restUrl ) {
		return;
	}

	var MIN_CHARS = 2;
	var DEBOUNCE_MS = 250;

	function initSearchInstance( input, results ) {
		var debounceTimer = null;
		var currentRequest = null;

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
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-yp-search-scope]' ), function ( scope ) {
		var input = scope.querySelector( '[data-yp-search-input]' );
		var results = scope.querySelector( '[data-yp-search-results]' );

		if ( input && results ) {
			initSearchInstance( input, results );
		}
	} );
} )();
