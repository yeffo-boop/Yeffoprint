import { __ } from '@wordpress/i18n';
import { PDFJson } from 'types';
import { getPDFSupport } from './pdf-support';

/**
 * Thrown when the browser refuses to open the merged PDF in a new tab. Used so
 * callers can branch on the cause (popup blocker vs generic failure) without
 * depending on a free-form English error message.
 */
export class PopupBlockedError extends Error {
	constructor() {
		super( 'Unable to open label PDF in new tab' );
		this.name = 'PopupBlockedError';
		// Subclassing `Error` can break `instanceof` after transpilation
		// targeting ES5 because the prototype chain is not always set up
		// automatically. Repairing it here keeps `e instanceof
		// PopupBlockedError` reliable regardless of the build target.
		Object.setPrototypeOf( this, new.target.prototype );
	}
}

let iframe: HTMLIFrameElement | null = null;

/**
 * Loads the given URL into a hidden iframe and resolves once the iframe's
 * `load` event fires. Replaces any previously-loaded iframe to avoid leaking
 * blob URLs across consecutive prints. Note: this only loads the document;
 * the caller is responsible for invoking `print()` on the resulting iframe.
 */
const loadDocumentInFrame = ( url: string ) =>
	new Promise< void >( ( resolve, reject ) => {
		if ( iframe ) {
			document.body.removeChild( iframe );
		}

		iframe = document.createElement( 'iframe' );
		iframe.src = url;

		// Note: Don't change this for "display: none" or it will stop working on MS Edge
		iframe.style.position = 'fixed';
		iframe.style.left = '-999px';

		iframe.onload = () => {
			resolve();
		};
		iframe.onerror = ( error ) => {
			reject( error );
		};

		document.body.appendChild( iframe );
	} );

const buildBlob = ( b64Content: string, mimeType: string ) => {
	const rawData = atob( b64Content );
	const rawDataLen = rawData.length;
	const binData = new Uint8Array( new ArrayBuffer( rawDataLen ) );
	for ( let i = 0; i < rawDataLen; i++ ) {
		binData[ i ] = rawData.charCodeAt( i );
	}
	return new Blob( [ binData ], { type: mimeType } );
};

/**
 * Renders a base64-encoded PDF in the most appropriate path for the current
 * browser. Resolves once the document has been handed off to the browser
 * (native print dialog opened, new tab opened, or download initiated).
 *
 * Per-path failure modes:
 * - `native` / `native_ff`: rejects when the hidden iframe fires `onerror`
 *   while loading the blob URL (e.g. PDF plugin error). Propagated from
 *   `loadDocumentInFrame()`.
 * - `addon`: rejects with `PopupBlockedError` when the new tab is suppressed.
 * - `ie`: rejects when `msSaveOrOpenBlob` returns false.
 * - default (browser cannot render PDFs at all): always resolves; the iframe
 *   load triggers a Download prompt, and we do not wait for it.
 */
export const printDocument = (
	{ b64Content, mimeType }: PDFJson,
	fileName: string
) => {
	const blob = buildBlob( b64Content, mimeType );
	const blobUrl =
		getPDFSupport() !== 'ie' ? URL.createObjectURL( blob ) : null; // IE has no use for "blob:" URLs

	if ( ! blobUrl ) {
		return Promise.reject(
			new Error(
				__( 'Unable to create blob url', 'woocommerce-shipping' )
			)
		);
	}
	switch ( getPDFSupport() ) {
		case 'native':
			// Happy case where everything can happen automatically. Supported in Chrome and Safari
			return loadDocumentInFrame( blobUrl ).then( () => {
				iframe?.contentWindow?.print();
				URL.revokeObjectURL( blobUrl );
			} );

		case 'native_ff':
			// Native for firefox
			return loadDocumentInFrame( blobUrl ).then( () => {
				// Fixing the Firefox issue when the browser is set to open PDF file using another program instead of using native PDF opener.
				// When PDF file is opened in another program, the iframe content will be empty.
				// This code is trying to identify how the firefox open the PDF by checking if the iframe content empty or not.
				// Empty iframe content   = firefox use another program to open PDF.
				// Unempty iframe content = firefox use native PDF opener from browser.
				// The empty iframe content will not catch any error. Thus we dont call the print() and let the browser open the PDF using another program.
				try {
					( () => iframe?.contentDocument!.body.innerText )();
				} catch {
					iframe?.contentWindow?.print();
				}

				URL.revokeObjectURL( blobUrl );
			} );

		case 'addon':
			// window.open will be blocked by the browser if this code isn't being executed from a direct user interaction
			const success = window.open( blobUrl );
			setTimeout( () => URL.revokeObjectURL( blobUrl ), 1000 );
			return success
				? Promise.resolve()
				: Promise.reject( new PopupBlockedError() );

		case 'ie':
			// @ts-ignore. Internet Explorer / Edge don't allow to load "blob:" URLs into an <iframe> or a new tab. The only solution is to download
			return navigator.msSaveOrOpenBlob( blob, fileName )
				? Promise.resolve()
				: Promise.reject( new Error( 'Unable to download the PDF' ) );

		default:
			// If browser doesn't support PDFs at all, this will trigger the "Download" pop-up.
			// No need to wait for the iframe to load, it will never finish.
			loadDocumentInFrame( blobUrl );
			setTimeout( () => URL.revokeObjectURL( blobUrl ), 0 );
			return Promise.resolve();
	}
};

/**
 * Opens the browser's print dialog to print HTML content as a packing slip document.
 * Uses an invisible iframe to load the content and triggers the print dialog.
 *
 * @param {string} content - The HTML content to be printed
 * @return {Promise<void>} Resolves when printing is initiated
 */
export const printPackingSlipDocument = (
	content: string
): Promise< void > => {
	// Add print-specific styles to remove headers and footers
	const printStyles = `
		<style>
			@media print {
				@page {
					margin: 0;
					size: auto;
				}
				body {
					margin: 1cm;
				}
			}
		</style>
	`;
	const contentWithStyles = content.includes( '</head>' )
		? content.replace( '</head>', `${ printStyles }</head>` )
		: `<html><head>${ printStyles }</head><body>${ content }</body></html>`;

	const blob = new Blob( [ contentWithStyles ], { type: 'text/html' } );
	const blobUrl = URL.createObjectURL( blob );

	return loadDocumentInFrame( blobUrl ).then( () => {
		iframe?.contentWindow?.print();
		URL.revokeObjectURL( blobUrl );
	} );
};
