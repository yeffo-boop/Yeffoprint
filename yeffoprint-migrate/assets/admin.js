/**
 * Drives the batch export/import loops for the YeffoPrint Migrate
 * admin page. Vanilla JS, no build step — each button starts a loop
 * of AJAX calls against class-ajax-controller.php's endpoints, each
 * one small enough to never approach a request timeout; the loop
 * itself lives here, not server-side, since "admin page only" means
 * there's no long-running process to drive it from anywhere else.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintMigrate === 'undefined' ) {
		return;
	}

	var config = yeffoprintMigrate;

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-action="export-settings"]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				runSettingsExport( button );
			} );
		} );

		document.querySelectorAll( '[data-action="import-settings"]' ).forEach( function ( button ) {
			syncUploadedState( button, 'settings' );
			button.addEventListener( 'click', function () {
				if ( button.disabled ) {
					return;
				}
				if ( button.getAttribute( 'data-confirm' ) && ! window.confirm( config.i18n.confirmImport ) ) {
					return;
				}
				runSettingsImport( button );
			} );
		} );

		document.querySelectorAll( '[data-action="export-batch"]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				runBatchExport( button, button.getAttribute( 'data-type' ) );
			} );
		} );

		document.querySelectorAll( '[data-action="import-batch"]' ).forEach( function ( button ) {
			var type = button.getAttribute( 'data-type' );
			syncUploadedState( button, type );
			button.addEventListener( 'click', function () {
				if ( button.disabled ) {
					return;
				}
				runBatchImport( button, type );
			} );
		} );
	} );

	function syncUploadedState( importButton, type ) {
		var section = importButton.closest( '.yeffoprint-migrate-section' );
		var notice = section && section.querySelector( '.yeffoprint-migrate-uploaded[data-type="' + type + '"]' );
		if ( notice ) {
			importButton.disabled = false;
			importButton.setAttribute( 'data-file', notice.getAttribute( 'data-file' ) );
		}
	}

	function elements( button ) {
		var section = button.closest( '.yeffoprint-migrate-section' );
		return {
			section:  section,
			progress: section.querySelector( '.yeffoprint-migrate-progress' ),
			bar:      section.querySelector( 'progress' ),
			label:    section.querySelector( '.yeffoprint-migrate-progress-label' ),
			result:   section.querySelector( '.yeffoprint-migrate-result' )
		};
	}

	function post( action, data ) {
		var body = new URLSearchParams( Object.assign( { action: action, nonce: config.nonce }, data ) );
		return fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) { return response.json(); } );
	}

	function showResult( els, html, isError ) {
		els.result.hidden = false;
		els.result.innerHTML = html;
		els.result.classList.toggle( 'is-error', !! isError );
	}

	/* ---------- Settings (one-shot) ---------- */

	function runSettingsExport( button ) {
		var els = elements( button );
		button.disabled = true;
		showResult( els, escapeHtml( config.i18n.exporting ), false );

		post( 'yeffoprint_migrate_export_settings', {} ).then( function ( response ) {
			button.disabled = false;
			if ( ! response.success ) {
				showResult( els, escapeHtml( response.data && response.data.message || config.i18n.error ), true );
				return;
			}
			showResult(
				els,
				escapeHtml( config.i18n.done ) + ' ' +
					response.data.options + ' options, ' +
					response.data.zones + ' shipping zones, ' +
					response.data.rates + ' tax rates. ' +
					'<code>' + escapeHtml( response.data.file ) + '</code> — see Files below to download.',
				false
			);
		} );
	}

	function runSettingsImport( button ) {
		var els = elements( button );
		var file = button.getAttribute( 'data-file' );
		button.disabled = true;
		showResult( els, escapeHtml( config.i18n.importing ), false );

		post( 'yeffoprint_migrate_import_settings', { file: file } ).then( function ( response ) {
			button.disabled = false;
			if ( ! response.success ) {
				showResult( els, escapeHtml( response.data && response.data.message || config.i18n.error ), true );
				return;
			}
			showResult(
				els,
				escapeHtml( config.i18n.done ) + ' ' +
					response.data.options_written + ' options, ' +
					response.data.zones_written + ' shipping zones, ' +
					response.data.tax_rates_written + ' tax rates written.',
				false
			);
		} );
	}

	/* ---------- Users / Orders export (batched) ---------- */

	function runBatchExport( button, type ) {
		var els = elements( button );
		button.disabled = true;
		els.progress.hidden = false;
		els.result.hidden = true;

		step( true );

		function step( reset ) {
			post( 'yeffoprint_migrate_export_batch', { type: type, reset: reset ? 1 : 0 } ).then( function ( response ) {
				if ( ! response.success ) {
					button.disabled = false;
					showResult( els, escapeHtml( response.data && response.data.message || config.i18n.error ), true );
					return;
				}

				var data = response.data;
				updateProgress( els, data.total ? Math.round( ( data.processed / data.total ) * 100 ) : 100, data.processed + ' / ' + data.total );

				if ( data.done ) {
					button.disabled = false;
					showResult( els, escapeHtml( config.i18n.done ) + ' <code>' + escapeHtml( data.file ) + '</code> — see Files below to download.', false );
					return;
				}

				step( false );
			} );
		}
	}

	/* ---------- Users / Orders import (batched) ---------- */

	function runBatchImport( button, type ) {
		var els = elements( button );
		var file = button.getAttribute( 'data-file' );
		button.disabled = true;
		els.progress.hidden = false;
		els.result.hidden = true;

		step( true );

		function step( reset ) {
			post( 'yeffoprint_migrate_import_batch', { type: type, file: file, reset: reset ? 1 : 0 } ).then( function ( response ) {
				if ( ! response.success ) {
					button.disabled = false;
					showResult( els, escapeHtml( response.data && response.data.message || config.i18n.error ), true );
					return;
				}

				var data = response.data;
				var pct = data.total_bytes ? Math.round( ( data.byte_offset / data.total_bytes ) * 100 ) : 100;
				updateProgress( els, pct, pct + '%' );

				if ( data.done ) {
					button.disabled = false;
					var summary = 'users' === type
						? data.created + ' created, ' + data.matched + ' matched existing accounts.'
						: data.created + ' created, ' + data.skipped + ' already migrated (skipped).';
					var errorsHtml = data.errors && data.errors.length
						? '<details><summary>' + data.errors.length + ' error(s)</summary><pre>' + escapeHtml( data.errors.join( '\n' ) ) + '</pre></details>'
						: '';
					showResult( els, escapeHtml( config.i18n.done ) + ' ' + summary + errorsHtml, !! ( data.errors && data.errors.length ) );
					return;
				}

				step( false );
			} );
		}
	}

	function updateProgress( els, percent, label ) {
		if ( els.bar ) {
			els.bar.value = percent;
		}
		if ( els.label ) {
			els.label.textContent = label;
		}
	}

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}
} )();
