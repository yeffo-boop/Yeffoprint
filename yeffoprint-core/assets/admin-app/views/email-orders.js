/**
 * Email Orders — customer order emails Jeff forwarded to the order
 * mailbox, read by Claude (includes/email-orders/class-email-orders.php,
 * via class-admin-email-orders-controller.php). Direct request: "forward
 * the email to a special email address and have the site create the
 * order based on the list in the email."
 *
 * Each card shows what was read, with anything unsure flagged. "Open in
 * Create Order" fills in views/manual-order.js (#/manual-order/email-{id});
 * nothing reaches the customer until Create Order is pressed there.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var TABS = [
		{ filter: 'open', label: 'To check' },
		{ filter: 'done', label: 'Done' },
		{ filter: 'all', label: 'All' }
	];

	var STATE_PILLS = {
		queued: [ 'yp-pill--neutral', 'Reading…' ],
		ready: [ 'yp-fb-pill--new', 'Ready to check' ],
		failed: [ 'yp-pill--warn', 'Couldn’t read' ],
		created: [ 'yp-pill--good', 'Order created' ],
		dismissed: [ 'yp-pill--neutral', 'Dismissed' ]
	};

	function endpoint( path ) {
		return yeffoprintAdminApp.restUrl + 'admin/email-orders' + ( path ? '/' + path : '' );
	}

	YP.views[ 'email-orders' ] = function ( viewEl ) {
		var filter = 'open';
		var page = 1;
		var names = { sizes: {}, materials: {} };

		viewEl.innerHTML =
			'<p class="yp-app__intro">Customer order emails you forwarded to the order mailbox. Claude reads each one into a filled-in order. Check it, fix anything flagged, then create it as usual. The mailbox is set up in Settings › Integrations › Email orders.</p>' +
			'<div class="yp-form__actions"><button type="button" class="wp-block-button__link is-style-outline" data-yp-eo-check>Check mailbox now</button><span class="yp-field__hint" data-yp-eo-check-status></span></div>' +
			'<div class="yp-settings-tabs" role="tablist">' +
				TABS.map( function ( tab ) {
					return '<button type="button" class="yp-settings-tabs__tab' + ( tab.filter === filter ? ' is-active' : '' ) + '" data-yp-eo-tab="' + tab.filter + '" role="tab" aria-selected="' + ( tab.filter === filter ? 'true' : 'false' ) + '">' + tab.label + '<span data-yp-eo-count="' + tab.filter + '"></span></button>';
				} ).join( '' ) +
			'</div>' +
			'<div class="yp-review-admin__list" data-yp-eo-list><p class="yp-panel__hint">Loading&hellip;</p></div>' +
			'<div class="yp-pagination" data-yp-eo-pages></div>';

		var listEl = viewEl.querySelector( '[data-yp-eo-list]' );
		var pagesEl = viewEl.querySelector( '[data-yp-eo-pages]' );

		viewEl.querySelectorAll( '[data-yp-eo-tab]' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				filter = tab.getAttribute( 'data-yp-eo-tab' );
				page = 1;
				viewEl.querySelectorAll( '[data-yp-eo-tab]' ).forEach( function ( other ) {
					var active = other === tab;
					other.classList.toggle( 'is-active', active );
					other.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				} );
				load();
			} );
		} );

		var checkButton = viewEl.querySelector( '[data-yp-eo-check]' );
		checkButton.addEventListener( 'click', function () {
			var statusEl = viewEl.querySelector( '[data-yp-eo-check-status]' );
			checkButton.disabled = true;
			checkButton.textContent = 'Checking…';
			statusEl.textContent = '';
			YP.request( endpoint( 'check' ), { method: 'POST' } )
				.then( function ( result ) {
					statusEl.textContent = result.message;
					load();
				} )
				.catch( function ( error ) {
					statusEl.textContent = 'Couldn’t check: ' + error.message;
				} )
				.then( function () {
					checkButton.disabled = false;
					checkButton.textContent = 'Check mailbox now';
				} );
		} );

		function load() {
			YP.request( endpoint() + '?filter=' + encodeURIComponent( filter ) + '&page=' + page )
				.then( render )
				.catch( function ( error ) {
					listEl.innerHTML = '<p class="yp-form__error">Couldn’t load emailed orders: ' + YP.escapeHtml( error.message ) + '</p>';
				} );
		}

		function itemsHtml( result ) {
			if ( ! result.items.length ) {
				return '<p class="yp-field__hint">No label lines found in this email.</p>';
			}
			return '<table class="yp-tier-table yp-stack-rows"><thead><tr><th>Label</th><th>Size</th><th>Material</th><th>Qty</th><th>Check</th></tr></thead><tbody>' +
				result.items.map( function ( item ) {
					var label = 'template' === item.kind
						? '<strong>' + YP.escapeHtml( item.template_title ) + '</strong>' + ( Object.keys( item.fields ).length ? '<br><span class="yp-field__hint">' + YP.escapeHtml( Object.keys( item.fields ).map( function ( key ) { return item.fields[ key ]; } ).join( ' · ' ) ) + '</span>' : '' )
						: 'custom_design' === item.kind
							? YP.escapeHtml( item.compound_strength || '(no text)' ) + '<br><span class="yp-field__hint">Custom design' + ( item.brand_name ? ' · ' + YP.escapeHtml( item.brand_name ) : '' ) + '</span>'
							: '<span class="yp-field__hint">Not added: </span>' + YP.escapeHtml( item.source_text || '' );
					var checks = ( item.flags || [] ).concat( item.note ? [ item.note ] : [] );
					var reused = ( item.from_previous || [] ).length ? '<span class="yp-pill yp-pill--neutral">Last order’s ' + YP.escapeHtml( item.from_previous.join( ', ' ) ) + '</span> ' : '';
					return '<tr>' +
						'<td data-label="Label">' + label + '</td>' +
						'<td data-label="Size">' + YP.escapeHtml( names.sizes[ item.size_id ] || ( 'unclear' === item.kind ? '' : '?' ) ) + '</td>' +
						'<td data-label="Material">' + YP.escapeHtml( names.materials[ item.material_id ] || ( 'unclear' === item.kind ? '' : '?' ) ) + '</td>' +
						'<td data-label="Qty">' + ( item.quantity || ( 'unclear' === item.kind ? '' : '?' ) ) + '</td>' +
						'<td data-label="Check">' + reused + ( checks.length ? '<span class="yp-pill yp-pill--warn">' + YP.escapeHtml( checks.join( '. ' ) ) + '</span>' : '' ) + '</td>' +
					'</tr>';
				} ).join( '' ) +
			'</tbody></table>';
		}

		function render( data ) {
			if ( ! data.email_orders.length && page > 1 ) {
				page = Math.max( 1, data.max_num_pages || 1 );
				load();
				return;
			}
			viewEl.querySelector( '[data-yp-eo-count="open"]' ).textContent = data.open_count ? ' (' + data.open_count + ')' : '';

			pagesEl.innerHTML = data.max_num_pages > 1
				? '<button type="button" class="wp-block-button__link is-style-outline" data-yp-eo-page="-1"' + ( page <= 1 ? ' disabled' : '' ) + '>&larr; Newer</button>' +
					'<span class="yp-pagination__status">Page ' + page + ' of ' + data.max_num_pages + '</span>' +
					'<button type="button" class="wp-block-button__link is-style-outline" data-yp-eo-page="1"' + ( page >= data.max_num_pages ? ' disabled' : '' ) + '>Older &rarr;</button>'
				: '';
			pagesEl.querySelectorAll( '[data-yp-eo-page]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					page += parseInt( button.getAttribute( 'data-yp-eo-page' ), 10 );
					load();
				} );
			} );

			if ( ! data.email_orders.length ) {
				listEl.innerHTML = '<div class="yp-record-card"><p class="yp-review-admin__empty">' +
					( ! data.enabled
						? 'Email orders are off. Turn them on and add the mailbox in <a href="#/settings/integrations">Settings › Integrations</a>.'
						: 'open' === filter ? 'Nothing to check. Forward a customer’s order email to the order mailbox and it shows up here within a few minutes.' : 'Nothing here yet.' ) +
					'</p></div>';
				return;
			}

			listEl.innerHTML = data.email_orders.map( function ( record ) {
				var result = record.result;
				var pill = STATE_PILLS[ record.state ] || STATE_PILLS.queued;
				var customer = result ? result.customer : null;
				var who = customer && ( customer.name || customer.email )
					? YP.escapeHtml( customer.name || customer.email ) + ( customer.name && customer.email ? ' <span class="yp-field__hint">' + YP.escapeHtml( customer.email ) + '</span>' : '' ) + ( record.customer_user ? ' <span class="yp-pill yp-pill--good">Existing customer</span>' : '' )
					: '<span class="yp-field__hint">Customer not found in the email</span>';

				var actions = [];
				if ( 'ready' === record.state ) {
					actions.push( '<a class="wp-block-button__link is-style-accent yp-review-admin__publish" href="#/manual-order/email-' + record.id + '">Open in Create Order</a>' );
				}
				if ( 'created' === record.state && record.order_id ) {
					actions.push( '<a class="wp-block-button__link is-style-outline" href="#" data-yp-eo-view-order="' + record.order_id + '">View order ' + YP.escapeHtml( String( record.order_number ) ) + '</a>' );
				}
				if ( 'failed' === record.state || 'ready' === record.state ) {
					actions.push( '<button type="button" class="yp-row-action" data-yp-eo-act="read" data-id="' + record.id + '">Read again</button>' );
				}
				if ( 'queued' === record.state ) {
					actions.push( '<button type="button" class="yp-row-action" data-yp-eo-act="read" data-id="' + record.id + '">Read now</button>' );
				}
				actions.push( 'created' === record.state || 'dismissed' === record.state
					? '<button type="button" class="yp-row-action" data-yp-eo-act="reopen" data-id="' + record.id + '">Reopen</button>'
					: '<button type="button" class="yp-row-action" data-yp-eo-act="dismiss" data-id="' + record.id + '">Dismiss</button>' );
				actions.push( '<button type="button" class="yp-row-action" data-yp-eo-act="delete" data-id="' + record.id + '">Delete</button>' );

				return (
					'<article class="yp-record-card yp-review-admin">' +
						'<header class="yp-review-admin__head">' +
							'<strong>' + YP.escapeHtml( record.subject ) + '</strong>' +
							'<span class="yp-field__hint">' + ( record.date ? YP.escapeHtml( new Date( record.date ).toLocaleString() ) + ' · ' : '' ) + 'forwarded by ' + YP.escapeHtml( record.forwarded_by ) + '</span>' +
							'<span class="yp-pill ' + pill[ 0 ] + '">' + pill[ 1 ] + '</span>' +
						'</header>' +
						( result ? '<p>' + who + '</p>' : '' ) +
						( record.error ? '<p class="yp-form__error">' + YP.escapeHtml( record.error ) + '</p>' : '' ) +
						( result && result.previous_design
							? '<p class="yp-panel__hint">' + ( result.reorder ? '<strong>Reorder</strong> of their custom design from ' : 'Returning customer. Blanks filled from their last custom design, ' ) + 'order ' + YP.escapeHtml( result.previous_design.order_number || 'from ' + result.previous_design.date ) + '.</p>'
							: '' ) +
						( result && result.notes_for_owner ? '<p class="yp-panel__hint"><strong>To check:</strong> ' + YP.escapeHtml( result.notes_for_owner ) + '</p>' : '' ) +
						( result ? itemsHtml( result ) : '' ) +
						'<details><summary class="yp-field__hint">Show the email</summary><p class="yp-review-admin__text" style="white-space:pre-line">' + YP.escapeHtml( record.text ) + '</p></details>' +
						'<div class="yp-review-admin__actions">' + actions.join( ' ' ) + '</div>' +
					'</article>'
				);
			} ).join( '' );

			listEl.querySelectorAll( '[data-yp-eo-view-order]' ).forEach( function ( link ) {
				link.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					if ( YP.openWcOrderDrawer ) {
						YP.openWcOrderDrawer( parseInt( link.getAttribute( 'data-yp-eo-view-order' ), 10 ) );
					}
				} );
			} );

			listEl.querySelectorAll( '[data-yp-eo-act]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var action = button.getAttribute( 'data-yp-eo-act' );
					var id     = button.getAttribute( 'data-id' );
					if ( 'delete' !== action ) {
						act( id, action, button );
						return;
					}
					YP.confirmModal( {
						title: 'Delete this emailed order?',
						message: 'It’s removed from the dashboard for good. The email stays in the mailbox, and any order you already created is kept.',
						confirmLabel: 'Delete',
						danger: true,
						onConfirm: function () {
							act( id, action, button );
						}
					} );
				} );
			} );
		}

		function act( id, action, button ) {
			button.disabled = true;
			if ( 'read' === action ) {
				button.textContent = 'Reading…';
			}
			YP.request( 'delete' === action ? endpoint( id ) : endpoint( id + '/' + action ), { method: 'delete' === action ? 'DELETE' : 'POST' } )
				.then( load )
				.catch( function ( error ) {
					button.disabled = false;
					window.alert( 'Couldn’t do that: ' + error.message );
				} );
		}

		// Size and material names for the item tables (the same lists Create Order uses).
		YP.request( yeffoprintAdminApp.restUrl + 'custom-orders/options' )
			.then( function ( options ) {
				( options.sizes || [] ).forEach( function ( size ) { names.sizes[ size.id ] = size.name; } );
				( options.materials || [] ).forEach( function ( material ) { names.materials[ material.id ] = material.name; } );
			} )
			.catch( function () {} )
			.then( load );
	};
} )();
