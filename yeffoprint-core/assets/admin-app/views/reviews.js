/**
 * Reviews — customer reviews left from the Delivered email or My
 * Account (class-order-reviews.php, via class-admin-review-
 * controller.php). New reviews wait here for Publish unless "Publish
 * new reviews right away" is on; the settings sit below the list.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var TABS = [
		{ status: 'waiting', label: 'Needs approval' },
		{ status: 'published', label: 'Published' },
		{ status: 'all', label: 'All' }
	];

	function endpoint( path ) {
		return yeffoprintAdminApp.restUrl + 'admin/reviews' + ( path ? '/' + path : '' );
	}

	function stars( rating ) {
		var out = '';
		for ( var i = 1; i <= 5; i++ ) {
			out += '<span class="yp-review-admin__star' + ( i <= rating ? ' is-on' : '' ) + '">&#9733;</span>';
		}
		return '<span class="yp-review-admin__stars" aria-label="' + rating + ' out of 5 stars">' + out + '</span>';
	}

	YP.views.reviews = function ( viewEl ) {
		var status = 'waiting';

		viewEl.innerHTML =
			'<p class="yp-app__intro">Reviews customers leave from their Delivered email or My Account. Published reviews show on the homepage and on the product pages of what they ordered.</p>' +
			'<div data-yp-rv-stats></div>' +
			'<div class="yp-settings-tabs" role="tablist">' +
				TABS.map( function ( tab ) {
					return '<button type="button" class="yp-settings-tabs__tab' + ( tab.status === status ? ' is-active' : '' ) + '" data-yp-rv-tab="' + tab.status + '" role="tab" aria-selected="' + ( tab.status === status ? 'true' : 'false' ) + '">' + tab.label + '<span data-yp-rv-count="' + tab.status + '"></span></button>';
				} ).join( '' ) +
			'</div>' +
			'<div class="yp-review-admin__list" data-yp-rv-list><p class="yp-panel__hint">Loading&hellip;</p></div>' +
			'<div data-yp-rv-settings></div>';

		var listEl = viewEl.querySelector( '[data-yp-rv-list]' );

		viewEl.querySelectorAll( '[data-yp-rv-tab]' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				status = tab.getAttribute( 'data-yp-rv-tab' );
				viewEl.querySelectorAll( '[data-yp-rv-tab]' ).forEach( function ( other ) {
					var active = other === tab;
					other.classList.toggle( 'is-active', active );
					other.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				} );
				load();
			} );
		} );

		function load() {
			YP.request( endpoint() + '?status=' + encodeURIComponent( status ) )
				.then( render )
				.catch( function ( error ) {
					listEl.innerHTML = '<p class="yp-form__error">Couldn’t load reviews: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		function render( data ) {
			viewEl.querySelector( '[data-yp-rv-stats]' ).innerHTML =
				'<div class="yp-stat-tiles">' +
					[
						[ data.summary.count ? data.summary.average.toFixed( 1 ) + ' <span style="font-size:1rem;color:#f5a300;">&#9733;</span>' : '—', 'Average rating' ],
						[ String( data.counts.published ), 'Published' ],
						[ String( data.counts.waiting ), 'Needs approval' ]
					].map( function ( tile ) {
						return '<div class="yp-stat-tile"><span class="yp-stat-tile__count">' + tile[ 0 ] + '</span><span class="yp-stat-tile__label">' + tile[ 1 ] + '</span></div>';
					} ).join( '' ) +
				'</div>';

			viewEl.querySelector( '[data-yp-rv-count="waiting"]' ).textContent = data.counts.waiting ? ' (' + data.counts.waiting + ')' : '';

			renderList( data.reviews );
			renderSettings( data.settings );
		}

		function renderList( reviews ) {
			if ( ! reviews.length ) {
				listEl.innerHTML = '<div class="yp-record-card"><p class="yp-review-admin__empty">' +
					( 'waiting' === status ? 'Nothing waiting for approval.' : 'No reviews yet. Customers get asked in their Delivered email.' ) +
					'</p></div>';
				return;
			}

			listEl.innerHTML = reviews.map( function ( review ) {
				var photos = review.photos.map( function ( photo ) {
					return '<a href="' + YP.escapeAttr( photo.full ) + '" target="_blank" rel="noopener"><img src="' + YP.escapeAttr( photo.thumb ) + '" alt="Customer photo" loading="lazy" /></a>';
				} ).join( '' );

				var actions = [];
				actions.push( review.published
					? '<button type="button" class="yp-row-action" data-yp-rv-act="unpublish" data-id="' + review.id + '">Unpublish</button>'
					: '<button type="button" class="wp-block-button__link is-style-accent yp-review-admin__publish" data-yp-rv-act="publish" data-id="' + review.id + '">Publish</button>' );
				if ( review.order_id ) {
					actions.push( '<button type="button" class="yp-row-action" data-yp-open-order="' + review.order_id + '">Order ' + YP.escapeHtml( review.order_number ) + '</button>' );
				}
				actions.push( '<button type="button" class="yp-row-action" data-yp-rv-act="delete" data-id="' + review.id + '">Delete</button>' );

				return (
					'<article class="yp-record-card yp-review-admin">' +
						'<header class="yp-review-admin__head">' +
							stars( review.rating ) +
							'<strong>' + YP.escapeHtml( review.name ) + '</strong>' +
							'<span class="yp-field__hint">' + YP.escapeHtml( review.date ) + ( review.email ? ' · ' + YP.escapeHtml( review.email ) : '' ) + '</span>' +
							( review.published ? '<span class="yp-pill yp-pill--good">Published</span>' : '<span class="yp-pill yp-pill--warn">Needs approval</span>' ) +
						'</header>' +
						( review.text ? '<p class="yp-review-admin__text">' + YP.escapeHtml( review.text ) + '</p>' : '<p class="yp-field__hint">No written review, stars only.</p>' ) +
						( photos ? '<div class="yp-review-admin__photos">' + photos + '</div>' : '' ) +
						( review.items.length ? '<p class="yp-field__hint">Ordered: ' + review.items.map( YP.escapeHtml ).join( ', ' ) + '</p>' : '' ) +
						'<div class="yp-review-admin__actions">' + actions.join( ' ' ) + '</div>' +
					'</article>'
				);
			} ).join( '' );

			listEl.querySelectorAll( '[data-yp-open-order]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					YP.openOrder( parseInt( button.getAttribute( 'data-yp-open-order' ), 10 ) );
				} );
			} );

			listEl.querySelectorAll( '[data-yp-rv-act]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var action = button.getAttribute( 'data-yp-rv-act' );
					var id     = button.getAttribute( 'data-id' );
					if ( 'delete' !== action ) {
						act( id, action, button );
						return;
					}
					YP.confirmModal( {
						title: 'Delete this review?',
						message: 'The review and its photos are removed for good. The customer can then leave a new one from their email link.',
						confirmLabel: 'Delete review',
						onConfirm: function () {
							act( id, action, button );
						}
					} );
				} );
			} );
		}

		function act( id, action, button ) {
			button.disabled = true;
			YP.request( endpoint( id + '/' + action ), { method: 'POST' } )
				.then( load )
				.catch( function ( error ) {
					button.disabled = false;
					window.alert( 'Couldn’t do that: ' + error.message );
				} );
		}

		function checkbox( id, checked, label ) {
			return '<div class="yp-field--checkbox yp-field"><input type="checkbox" id="' + id + '"' + ( checked ? ' checked' : '' ) + ' /><label for="' + id + '">' + label + '</label></div>';
		}

		function renderSettings( settings ) {
			var el = viewEl.querySelector( '[data-yp-rv-settings]' );
			el.innerHTML =
				'<div class="yp-panel">' +
					'<div class="yp-panel__head"><h2>Review settings</h2></div>' +
					checkbox( 'yp-rv-enabled', settings.enabled, 'Ask for a review in the Delivered email and on My Account orders' ) +
					checkbox( 'yp-rv-auto', settings.auto_publish, 'Publish new reviews right away (skip approval)' ) +
					'<p class="yp-panel__hint">You get a Telegram message for every new review either way.</p>' +
					checkbox( 'yp-rv-request', settings.request_email, 'Email a review request after delivery' ) +
					'<div class="yp-field"><label for="yp-rv-days">Days after delivery</label><input type="number" min="1" max="30" id="yp-rv-days" value="' + YP.escapeAttr( String( settings.request_days ) ) + '" /></div>' +
					'<p class="yp-panel__hint">Sent once per order, only for the customer’s most recent delivered order, and never if they already left a review.</p>' +
					'<div class="yp-form__actions"><button type="button" class="wp-block-button__link is-style-accent" data-yp-rv-save>Save settings</button></div>' +
					'<div data-yp-rv-save-status></div>' +
				'</div>';

			el.querySelector( '[data-yp-rv-save]' ).addEventListener( 'click', function () {
				var button   = this;
				var statusEl = el.querySelector( '[data-yp-rv-save-status]' );
				button.disabled = true;
				statusEl.innerHTML = '';

				YP.request( endpoint( 'settings' ), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						enabled: el.querySelector( '#yp-rv-enabled' ).checked,
						auto_publish: el.querySelector( '#yp-rv-auto' ).checked,
						request_email: el.querySelector( '#yp-rv-request' ).checked,
						request_days: el.querySelector( '#yp-rv-days' ).value
					} )
				} )
					.then( function ( response ) {
						renderSettings( response.settings );
						viewEl.querySelector( '[data-yp-rv-save-status]' ).innerHTML = '<p class="yp-panel__hint">Saved.</p>';
					} )
					.catch( function ( error ) {
						button.disabled = false;
						statusEl.innerHTML = '<p class="yp-form__error">' + YP.escapeHtml( error.message ) + '</p>';
					} );
			} );
		}

		load();
	};
} )();
