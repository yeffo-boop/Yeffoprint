/**
 * Disputes (`#/disputes`, `#/disputes/{id}`) — card chargebacks from
 * WooPayments (class-admin-disputes-controller.php). Direct request: run
 * the business from the dashboard without wp-admin; a dispute has a
 * deadline, and answering one used to mean WooPayments' own screens.
 *
 * The response form fills empty fields from the order (what they bought,
 * who and where it shipped, the Shippo tracking number). Save keeps a
 * draft; Submit sends everything to the bank, after which nothing can be
 * changed. Accept gives up the dispute.
 */

( function () {
	'use strict';

	var YP = window.YPAdminApp;
	if ( ! YP ) {
		return;
	}

	var esc = YP.escapeHtml;
	var escAttr = YP.escapeAttr;

	var FIELDS = [
		[ 'uncategorized_text', 'Your side of the story', 'textarea', 'What happened, in plain words: what they ordered, that you made and shipped it, any messages with the customer, why the charge is valid.' ],
		[ 'product_description', 'What they ordered', 'textarea', '' ],
		[ 'customer_name', 'Customer name', 'text', '' ],
		[ 'customer_email_address', 'Customer email', 'text', '' ],
		[ 'billing_address', 'Billing address', 'text', '' ],
		[ 'shipping_address', 'Shipping address', 'text', '' ],
		[ 'shipping_carrier', 'Carrier', 'text', '' ],
		[ 'shipping_tracking_number', 'Tracking number', 'text', '' ],
		[ 'shipping_date', 'Date shipped (YYYY-MM-DD)', 'text', '' ]
	];

	var FILES = [
		[ 'receipt', 'Receipt or invoice' ],
		[ 'shipping_documentation', 'Proof of shipping or delivery' ],
		[ 'customer_communication', 'Messages with the customer' ],
		[ 'uncategorized_file', 'Anything else (photos of the labels, proof approval)' ]
	];

	function api( path ) {
		return yeffoprintAdminApp.restUrl + 'admin/' + path;
	}

	function money( value ) {
		return '$' + Number( value || 0 ).toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	function day( iso ) {
		return iso ? new Date( iso ).toLocaleDateString( undefined, { weekday: 'short', month: 'short', day: 'numeric' } ) : '—';
	}

	function dueText( iso ) {
		if ( ! iso ) {
			return '';
		}
		var days = Math.ceil( ( new Date( iso ).getTime() - Date.now() ) / 86400000 );
		return days < 0 ? 'past due' : ( days <= 1 ? 'due within a day' : days + ' days left' );
	}

	function statusPill( d ) {
		var cls = d.needs_response ? 'ypn-pill--red' : ( 'won' === d.status ? 'ypn-pill--grn' : ( 'lost' === d.status ? '' : 'ypn-pill--yel' ) );
		return '<span class="ypn-pill ' + cls + '">' + esc( d.status_label ) + '</span>';
	}

	YP.views.disputes = function ( viewEl, subId ) {
		if ( subId ) {
			detail( viewEl, subId );
			return;
		}

		viewEl.innerHTML = '<p class="yp-field__hint">Loading&hellip;</p>';

		function load( refresh ) {
			YP.request( api( 'disputes' + ( refresh ? '?refresh=1' : '' ) ) ).then( draw ).catch( function ( error ) {
				viewEl.innerHTML = '<p class="yp-form__error">Couldn’t load disputes: ' + esc( error.message ) + '</p>';
			} );
		}

		function draw( data ) {
			if ( ! document.body.contains( viewEl ) ) {
				return;
			}
			if ( ! data.available ) {
				viewEl.innerHTML = '<div class="ypn-card"><div class="ypn-empty"><b>Disputes aren’t available</b><span>' + esc( data.error || 'WooPayments didn’t answer.' ) + '</span></div></div>';
				return;
			}

			var rows = data.disputes.map( function ( d ) {
				return '<tr class="yp-row-clickable" data-ypn-dispute="' + escAttr( d.id ) + '">' +
					'<td><b>' + esc( money( d.amount ) ) + '</b><div class="ypn-muted">' + esc( d.customer_name || '' ) + ( d.order_number ? ' · Order ' + esc( d.order_number ) : '' ) + '</div></td>' +
					'<td class="ypn-hide-phone">' + esc( d.reason_label ) + '</td>' +
					'<td>' + statusPill( d ) + '</td>' +
					'<td>' + ( d.needs_response && d.due_by ? '<b class="ypn-bad">' + esc( day( d.due_by ) ) + '</b><div class="ypn-muted">' + esc( dueText( d.due_by ) ) + '</div>' : '<span class="ypn-muted">' + esc( day( d.created ) ) + '</span>' ) + '</td>' +
				'</tr>';
			} ).join( '' );

			viewEl.innerHTML =
				'<p class="yp-app__intro">When a customer’s bank disputes a card payment, the money is held until you answer. Respond before the due date with your side and any proof, or accept it. ' +
					'<button type="button" class="ypn-link" data-ypn-refresh>Refresh</button></p>' +
				'<section class="ypn-card">' +
					( rows
						? '<table class="ypn-table"><thead><tr><th>Amount</th><th class="ypn-hide-phone">Reason</th><th>Status</th><th>Respond by</th></tr></thead><tbody>' + rows + '</tbody></table>'
						: '<div class="ypn-empty"><b>No disputes</b><span>Nobody has disputed a card payment. If one comes in you’ll get an alert.</span></div>' ) +
				'</section>';

			viewEl.querySelector( '[data-ypn-refresh]' ).addEventListener( 'click', function () { load( true ); } );
			viewEl.querySelectorAll( '[data-ypn-dispute]' ).forEach( function ( row ) {
				row.addEventListener( 'click', function () {
					window.location.hash = '#/disputes/' + row.getAttribute( 'data-ypn-dispute' );
				} );
			} );
		}

		load( false );
	};

	function detail( viewEl, id ) {
		var titleEl = document.querySelector( '[data-yp-title]' );
		viewEl.innerHTML = '<p class="yp-field__hint">Loading&hellip;</p>';

		function load() {
			YP.request( api( 'disputes/' + encodeURIComponent( id ) ) ).then( draw ).catch( function ( error ) {
				viewEl.innerHTML = '<p class="yp-form__error">Couldn’t load this dispute: ' + esc( error.message ) + '</p><a class="ypn-btn" href="#/disputes">Back to disputes</a>';
			} );
		}

		function draw( d ) {
			if ( ! document.body.contains( viewEl ) ) {
				return;
			}
			if ( titleEl ) {
				titleEl.textContent = 'Dispute ' + money( d.amount );
			}

			var editable = d.needs_response;
			var value = function ( key ) {
				return d.evidence[ key ] || ( editable ? ( d.suggested[ key ] || '' ) : '' );
			};

			var fields = FIELDS.map( function ( f ) {
				var input = 'textarea' === f[ 2 ]
					? '<textarea rows="' + ( 'uncategorized_text' === f[ 0 ] ? 6 : 3 ) + '" data-ypn-ev="' + f[ 0 ] + '"' + ( editable ? '' : ' readonly' ) + '>' + esc( value( f[ 0 ] ) ) + '</textarea>'
					: '<input type="text" data-ypn-ev="' + f[ 0 ] + '" value="' + escAttr( value( f[ 0 ] ) ) + '"' + ( editable ? '' : ' readonly' ) + '>';
				return '<label class="ypn-form__field' + ( 'textarea' === f[ 2 ] || /address/.test( f[ 0 ] ) ? ' is-wide' : '' ) + '"><span>' + esc( f[ 1 ] ) + '</span>' + input +
					( f[ 3 ] && editable ? '<small>' + esc( f[ 3 ] ) + '</small>' : '' ) + '</label>';
			} ).join( '' );

			var files = FILES.map( function ( f ) {
				var has = !! d.evidence[ f[ 0 ] ];
				return '<div class="ypn-form__field is-wide ypn-dispute__file" data-ypn-file-row="' + f[ 0 ] + '">' +
					'<span>' + esc( f[ 1 ] ) + '</span>' +
					'<div><b data-ypn-file-name>' + ( has ? 'File attached' : 'No file' ) + '</b>' +
					( editable ? ' <label class="ypn-link">' + ( has ? 'Replace' : 'Attach a file' ) + '<input type="file" accept="image/*,application/pdf" hidden data-ypn-file="' + f[ 0 ] + '"></label>' : '' ) +
					'</div><input type="hidden" data-ypn-ev="' + f[ 0 ] + '" value="' + escAttr( d.evidence[ f[ 0 ] ] || '' ) + '"></div>';
			} ).join( '' );

			viewEl.innerHTML =
				'<div class="ypn-op">' +
					'<div class="ypn-op__sub">' + statusPill( d ) +
						'<span>' + esc( d.reason_label ) + ' · opened ' + esc( day( d.created ) ) + '</span>' +
						( d.order_id ? '<a class="ypn-link" href="#/order/' + d.order_id + '">Open order ' + esc( d.order_number || '' ) + '</a>' : '' ) +
					'</div>' +
					( editable && d.due_by
						? '<div class="ypn-banner ypn-banner--warn"><b>Respond by ' + esc( day( d.due_by ) ) + ' (' + esc( dueText( d.due_by ) ) + ')</b><span>If you don’t answer in time, the bank sides with the customer.</span></div>'
						: '' ) +
					( ! editable && d.submitted ? '<div class="ypn-banner"><b>Response sent</b><span>The bank is reviewing it. You’ll get the result by email from WooPayments.</span></div>' : '' ) +
					'<section class="ypn-card"><h3 class="ypn-card__title">Your response</h3>' +
						'<div class="ypn-form">' + fields + files + '</div>' +
						'<div data-ypn-dispute-error></div>' +
						( editable
							? '<div class="ypn-dispute__acts">' +
								'<button type="button" class="ypn-btn ypn-btn--primary" data-ypn-submit>Submit response</button>' +
								'<button type="button" class="ypn-btn" data-ypn-save>Save draft</button>' +
								'<button type="button" class="ypn-link ypn-bad" data-ypn-accept>Accept dispute</button>' +
							'</div>'
							: '' ) +
					'</section>' +
				'</div>';

			if ( editable ) {
				bind();
			}
		}

		function evidence() {
			var out = {};
			viewEl.querySelectorAll( '[data-ypn-ev]' ).forEach( function ( el ) {
				out[ el.getAttribute( 'data-ypn-ev' ) ] = el.value.trim();
			} );
			return out;
		}

		function showError( message ) {
			viewEl.querySelector( '[data-ypn-dispute-error]' ).innerHTML = message ? '<p class="yp-form__error">' + esc( message ) + '</p>' : '';
		}

		function save( submit, button ) {
			var label = button.textContent;
			button.disabled = true;
			button.textContent = submit ? 'Sending…' : 'Saving…';
			showError( '' );
			YP.request( api( 'disputes/' + encodeURIComponent( id ) ), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { evidence: evidence(), submit: submit } )
			} ).then( function ( d ) {
				draw( d );
				if ( ! submit ) {
					var saved = viewEl.querySelector( '[data-ypn-save]' );
					if ( saved ) {
						saved.textContent = 'Saved ✓';
					}
				}
			} ).catch( function ( error ) {
				button.disabled = false;
				button.textContent = label;
				showError( error.message );
			} );
		}

		function bind() {
			viewEl.querySelector( '[data-ypn-save]' ).addEventListener( 'click', function ( event ) { save( false, event.currentTarget ); } );
			viewEl.querySelector( '[data-ypn-submit]' ).addEventListener( 'click', function ( event ) {
				var button = event.currentTarget;
				if ( ! evidence().uncategorized_text ) {
					showError( 'Write your side of the story first.' );
					return;
				}
				YP.confirmModal( {
					title: 'Send this response to the bank?',
					message: 'You can’t change or add anything after it’s sent.',
					confirmLabel: 'Submit response',
					onConfirm: function () { save( true, button ); }
				} );
			} );
			viewEl.querySelector( '[data-ypn-accept]' ).addEventListener( 'click', function ( event ) {
				var button = event.currentTarget;
				YP.confirmModal( {
					title: 'Accept this dispute?',
					message: 'The customer keeps the refund and the dispute fee isn’t returned. This can’t be undone.',
					confirmLabel: 'Accept dispute',
					danger: true,
					onConfirm: function () {
						button.disabled = true;
						YP.request( api( 'disputes/' + encodeURIComponent( id ) + '/accept' ), { method: 'POST' } ).then( draw ).catch( function ( error ) {
							button.disabled = false;
							showError( error.message );
						} );
					}
				} );
			} );

			viewEl.querySelectorAll( '[data-ypn-file]' ).forEach( function ( input ) {
				input.addEventListener( 'change', function () {
					var file = input.files[ 0 ];
					var key = input.getAttribute( 'data-ypn-file' );
					var row = viewEl.querySelector( '[data-ypn-file-row="' + key + '"]' );
					if ( ! file ) {
						return;
					}
					row.querySelector( '[data-ypn-file-name]' ).textContent = 'Uploading ' + file.name + '…';
					var body = new FormData();
					body.append( 'file', file );
					YP.request( api( 'dispute-evidence-file' ), { method: 'POST', body: body } ).then( function ( result ) {
						row.querySelector( '[data-ypn-ev="' + key + '"]' ).value = result.id;
						row.querySelector( '[data-ypn-file-name]' ).textContent = result.filename || file.name;
					} ).catch( function ( error ) {
						row.querySelector( '[data-ypn-file-name]' ).textContent = 'Couldn’t upload: ' + error.message;
					} );
				} );
			} );
		}

		load();
	}
} )();
