/**
 * Shipping zones — direct request: change shipping prices without
 * WooCommerce's dashboard. Talks to WooCommerce's own REST API
 * (wc/v3/shipping/zones), which accepts the app's logged-in cookie and
 * nonce, so the rates here are exactly the ones checkout uses.
 *
 * Each zone is a card: its name and regions, then its methods (Flat
 * rate, Free shipping, Local pickup) with on/off, name and price. The
 * "Everywhere else" zone (id 0) has no regions of its own.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var METHOD_LABELS = {
		flat_rate: 'Flat rate',
		free_shipping: 'Free shipping',
		local_pickup: 'Local pickup'
	};

	var FREE_REQUIRES = {
		'': 'Always free',
		min_amount: 'Order total at least…',
		coupon: 'A free shipping coupon',
		either: 'Minimum total or a coupon',
		both: 'Minimum total and a coupon'
	};

	function wc( path ) {
		return ( yeffoprintAdminApp.wcApiUrl || '' ) + path;
	}

	function send( path, method, body ) {
		return YP.request( wc( path ), {
			method: method,
			headers: { 'Content-Type': 'application/json' },
			body: body ? JSON.stringify( body ) : undefined
		} );
	}

	function setting( method, key ) {
		var s = method.settings && method.settings[ key ];
		return s ? String( null == s.value ? '' : s.value ) : '';
	}

	/** Zone locations as the codes people type: US, US:OR, continent:NA, or a ZIP like 97*. */
	function locationsToText( locations ) {
		return locations.map( function ( loc ) {
			return 'continent' === loc.type ? 'continent:' + loc.code : loc.code;
		} ).join( ', ' );
	}

	function textToLocations( text ) {
		return text.split( /[,\n]+/ ).map( function ( part ) { return part.trim(); } ).filter( Boolean ).map( function ( part ) {
			if ( /^continent:/i.test( part ) ) {
				return { code: part.replace( /^continent:/i, '' ).toUpperCase(), type: 'continent' };
			}
			if ( /^[A-Za-z]{2}:[A-Za-z0-9-]+$/.test( part ) ) {
				return { code: part.toUpperCase(), type: 'state' };
			}
			if ( /^[A-Za-z]{2}$/.test( part ) ) {
				return { code: part.toUpperCase(), type: 'country' };
			}
			return { code: part.toUpperCase(), type: 'postcode' };
		} );
	}

	YP.views[ 'shipping-zones' ] = function ( viewEl ) {
		viewEl.innerHTML =
			'<p class="yp-app__intro">The shipping choices and prices customers see at checkout. A customer gets the first zone that matches their address, top to bottom.</p>' +
			'<div class="yp-list-toolbar"><span></span><button type="button" class="wp-block-button__link is-style-accent" data-yp-add-zone>+ Add zone</button></div>' +
			'<div data-yp-zones><p class="yp-field__hint">Loading&hellip;</p></div>';

		var zonesEl = viewEl.querySelector( '[data-yp-zones]' );

		function load() {
			YP.request( wc( 'shipping/zones' ) ).then( function ( zones ) {
				// "Everywhere else" (id 0) always applies last.
				zones.sort( function ( a, b ) {
					return ( 0 === a.id ? 1 : 0 ) - ( 0 === b.id ? 1 : 0 ) || a.order - b.order;
				} );
				return Promise.all( zones.map( function ( zone ) {
					return Promise.all( [
						0 === zone.id ? Promise.resolve( [] ) : YP.request( wc( 'shipping/zones/' + zone.id + '/locations' ) ),
						YP.request( wc( 'shipping/zones/' + zone.id + '/methods' ) )
					] ).then( function ( parts ) {
						zone.locations = parts[ 0 ];
						zone.methods = parts[ 1 ].sort( function ( a, b ) { return a.order - b.order; } );
						return zone;
					} );
				} ) );
			} ).then( render ).catch( function ( error ) {
				zonesEl.innerHTML = '<p class="yp-form__error">Couldn’t load shipping zones: ' + YP.escapeHtml( error.message ) + '</p>';
			} );
		}

		function methodHtml( zone, method ) {
			var known = !! METHOD_LABELS[ method.method_id ];
			var cost = 'free_shipping' === method.method_id ? '' : setting( method, 'cost' );
			return (
				'<div class="yp-ship-method" data-yp-method="' + method.instance_id + '" data-yp-zone="' + zone.id + '">' +
					'<label class="yp-ship-method__on"><input type="checkbox" data-yp-m-enabled' + ( method.enabled ? ' checked' : '' ) + '> On</label>' +
					'<label class="yp-ship-method__field"><span>' + YP.escapeHtml( METHOD_LABELS[ method.method_id ] || method.method_title || method.method_id ) + ' name</span>' +
						'<input type="text" data-yp-m-title value="' + YP.escapeAttr( setting( method, 'title' ) || method.title ) + '"></label>' +
					( 'free_shipping' === method.method_id
						? '<label class="yp-ship-method__field"><span>Free when</span><select data-yp-m-requires>' + Object.keys( FREE_REQUIRES ).map( function ( key ) {
								return '<option value="' + key + '"' + ( setting( method, 'requires' ) === key ? ' selected' : '' ) + '>' + YP.escapeHtml( FREE_REQUIRES[ key ] ) + '</option>';
							} ).join( '' ) + '</select></label>' +
							'<label class="yp-ship-method__field yp-ship-method__cost"><span>Minimum ($)</span><input type="number" min="0" step="0.01" inputmode="decimal" data-yp-m-min value="' + YP.escapeAttr( setting( method, 'min_amount' ) ) + '"></label>'
						: ( known
							? '<label class="yp-ship-method__field yp-ship-method__cost"><span>Price ($)</span><input type="text" inputmode="decimal" data-yp-m-cost value="' + YP.escapeAttr( cost ) + '"></label>'
							: '<span class="yp-field__hint">Price is set by its own plugin.</span>' ) ) +
					'<span class="yp-ship-method__actions">' +
						'<button type="button" class="wp-block-button__link is-style-outline" data-yp-m-save>Save</button>' +
						'<button type="button" class="yp-link-button" data-yp-m-remove>Remove</button>' +
					'</span>' +
					'<span class="yp-ship-method__msg" data-yp-m-msg></span>' +
				'</div>'
			);
		}

		function render( zones ) {
			zonesEl.innerHTML = zones.map( function ( zone ) {
				var everywhere = 0 === zone.id;
				return (
					'<section class="yp-panel yp-ship-zone" data-yp-zone-card="' + zone.id + '">' +
						'<div class="yp-ship-zone__head">' +
							'<div><h3>' + YP.escapeHtml( everywhere ? 'Everywhere else' : zone.name ) + '</h3>' +
								'<p class="yp-field__hint">' + ( everywhere ? 'Addresses no other zone covers.' : YP.escapeHtml( locationsToText( zone.locations ) || 'No regions yet, so nobody matches this zone.' ) ) + '</p></div>' +
							( everywhere ? '' : '<span><button type="button" class="yp-link-button" data-yp-zone-edit>Edit</button> &middot; <button type="button" class="yp-link-button" data-yp-zone-delete>Delete</button></span>' ) +
						'</div>' +
						'<div data-yp-zone-form hidden></div>' +
						( zone.methods.length
							? zone.methods.map( function ( method ) { return methodHtml( zone, method ); } ).join( '' )
							: '<p class="yp-field__hint">No shipping methods, so checkout shows no shipping for these addresses.</p>' ) +
						'<div class="yp-ship-zone__add"><select data-yp-new-method>' + Object.keys( METHOD_LABELS ).map( function ( id ) {
							return '<option value="' + id + '">' + METHOD_LABELS[ id ] + '</option>';
						} ).join( '' ) + '</select><button type="button" class="wp-block-button__link is-style-outline" data-yp-add-method>+ Add method</button></div>' +
					'</section>'
				);
			} ).join( '' );

			zones.forEach( function ( zone ) {
				zonesEl.querySelector( '[data-yp-zone-card="' + zone.id + '"]' ).yeffoZone = zone;
			} );
		}

		function zoneForm( formEl, zone ) {
			formEl.hidden = false;
			formEl.innerHTML =
				'<div class="yp-ship-zone__form">' +
					'<label class="yp-ship-method__field"><span>Zone name</span><input type="text" data-yp-zone-name value="' + YP.escapeAttr( zone ? zone.name : '' ) + '" placeholder="e.g. United States"></label>' +
					'<label class="yp-ship-method__field yp-ship-zone__regions"><span>Regions</span><input type="text" data-yp-zone-regions value="' + YP.escapeAttr( zone ? locationsToText( zone.locations ) : '' ) + '" placeholder="US, CA, US:OR, 97*"></label>' +
					'<p class="yp-field__hint">Separate with commas. Countries are two letters (US, CA, GB), states are country:state (US:OR, US:HI), ZIP codes can end in * (972*) or be a range (97000...97999).</p>' +
					'<span class="yp-ship-method__actions"><button type="button" class="wp-block-button__link is-style-accent" data-yp-zone-save>Save zone</button><button type="button" class="yp-link-button" data-yp-zone-cancel>Cancel</button></span>' +
					'<span data-yp-zone-msg></span>' +
				'</div>';
			formEl.querySelector( '[data-yp-zone-name]' ).focus();
		}

		function saveZone( formEl, zone ) {
			var name = formEl.querySelector( '[data-yp-zone-name]' ).value.trim();
			var locations = textToLocations( formEl.querySelector( '[data-yp-zone-regions]' ).value );
			var msg = formEl.querySelector( '[data-yp-zone-msg]' );
			var button = formEl.querySelector( '[data-yp-zone-save]' );
			if ( ! name ) {
				msg.innerHTML = '<p class="yp-form__error">Give the zone a name.</p>';
				return;
			}
			button.disabled = true;
			button.textContent = 'Saving…';
			( zone ? send( 'shipping/zones/' + zone.id, 'PUT', { name: name } ) : send( 'shipping/zones', 'POST', { name: name } ) )
				.then( function ( saved ) { return send( 'shipping/zones/' + saved.id + '/locations', 'PUT', locations ); } )
				.then( load )
				.catch( function ( error ) {
					button.disabled = false;
					button.textContent = 'Save zone';
					msg.innerHTML = '<p class="yp-form__error">Couldn’t save: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		function saveMethod( row ) {
			var zoneId = row.getAttribute( 'data-yp-zone' );
			var id = row.getAttribute( 'data-yp-method' );
			var msg = row.querySelector( '[data-yp-m-msg]' );
			var button = row.querySelector( '[data-yp-m-save]' );
			var settings = { title: row.querySelector( '[data-yp-m-title]' ).value.trim() };
			var cost = row.querySelector( '[data-yp-m-cost]' );
			if ( cost ) {
				settings.cost = cost.value.trim();
			}
			var requires = row.querySelector( '[data-yp-m-requires]' );
			if ( requires ) {
				settings.requires = requires.value;
				settings.min_amount = row.querySelector( '[data-yp-m-min]' ).value.trim();
			}
			button.disabled = true;
			msg.textContent = '';
			send( 'shipping/zones/' + zoneId + '/methods/' + id, 'PUT', { enabled: row.querySelector( '[data-yp-m-enabled]' ).checked, settings: settings } )
				.then( function () {
					button.disabled = false;
					msg.textContent = 'Saved';
					window.setTimeout( function () { msg.textContent = ''; }, 2500 );
				} )
				.catch( function ( error ) {
					button.disabled = false;
					msg.innerHTML = '<span class="yp-form__error">' + YP.escapeHtml( error.message ) + '</span>';
				} );
		}

		viewEl.querySelector( '[data-yp-add-zone]' ).addEventListener( 'click', function () {
			var existing = zonesEl.querySelector( '[data-yp-new-zone]' );
			if ( existing ) {
				existing.querySelector( '[data-yp-zone-name]' ).focus();
				return;
			}
			var card = document.createElement( 'section' );
			card.className = 'yp-panel yp-ship-zone';
			card.setAttribute( 'data-yp-new-zone', '' );
			card.innerHTML = '<div class="yp-ship-zone__head"><h3>New zone</h3></div><div data-yp-zone-form></div>';
			zonesEl.insertBefore( card, zonesEl.firstChild );
			zoneForm( card.querySelector( '[data-yp-zone-form]' ), null );
		} );

		zonesEl.addEventListener( 'click', function ( event ) {
			var card = event.target.closest( '[data-yp-zone-card], [data-yp-new-zone]' );
			if ( ! card ) {
				return;
			}
			var zone = card.yeffoZone || null;
			var row = event.target.closest( '[data-yp-method]' );

			if ( event.target.closest( '[data-yp-zone-edit]' ) ) {
				zoneForm( card.querySelector( '[data-yp-zone-form]' ), zone );
			} else if ( event.target.closest( '[data-yp-zone-cancel]' ) ) {
				if ( card.hasAttribute( 'data-yp-new-zone' ) ) {
					card.remove();
				} else {
					card.querySelector( '[data-yp-zone-form]' ).hidden = true;
				}
			} else if ( event.target.closest( '[data-yp-zone-save]' ) ) {
				saveZone( card.querySelector( '[data-yp-zone-form]' ), zone );
			} else if ( event.target.closest( '[data-yp-zone-delete]' ) ) {
				YP.confirmModal( {
					title: 'Delete the ' + zone.name + ' zone?',
					message: 'Its shipping methods go with it. Addresses it covered fall through to the next matching zone.',
					confirmLabel: 'Delete zone',
					danger: true,
					onConfirm: function () {
						send( 'shipping/zones/' + zone.id + '?force=true', 'DELETE' ).then( load ).catch( function ( error ) {
							window.alert( 'Couldn’t delete: ' + error.message );
						} );
					}
				} );
			} else if ( event.target.closest( '[data-yp-add-method]' ) ) {
				var methodId = card.querySelector( '[data-yp-new-method]' ).value;
				event.target.disabled = true;
				send( 'shipping/zones/' + zone.id + '/methods', 'POST', { method_id: methodId } ).then( load ).catch( function ( error ) {
					event.target.disabled = false;
					window.alert( 'Couldn’t add: ' + error.message );
				} );
			} else if ( row && event.target.closest( '[data-yp-m-save]' ) ) {
				saveMethod( row );
			} else if ( row && event.target.closest( '[data-yp-m-remove]' ) ) {
				YP.confirmModal( {
					title: 'Remove ' + row.querySelector( '[data-yp-m-title]' ).value + '?',
					message: 'Customers in this zone stop seeing it at checkout. To hide it for a while instead, untick On and save.',
					confirmLabel: 'Remove',
					danger: true,
					onConfirm: function () {
						send( 'shipping/zones/' + zone.id + '/methods/' + row.getAttribute( 'data-yp-method' ) + '?force=true', 'DELETE' ).then( load ).catch( function ( error ) {
							window.alert( 'Couldn’t remove: ' + error.message );
						} );
					}
				} );
			}
		} );

		load();
	};
} )();
