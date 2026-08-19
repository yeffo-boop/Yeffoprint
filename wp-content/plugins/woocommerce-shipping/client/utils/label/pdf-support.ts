import { get, includes, memoize } from 'lodash';

const getActiveXObject = ( name: string ) => {
	try {
		return new ActiveXObject( name );
	} catch {
		// Ignore
	}
};

/**
 * Detect if the current browser is on a mobile device
 */
const isMobile = () => {
	return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile|mobile|CriOS/i.test(
		navigator.userAgent
	);
};

/**
 * This function result is cached for performance reasons, since browser capabilities won't change.
 *
 * @return {string|false} "native" if the browser can display PDFs and code can reliably call JS
 * functions on those PDFs (such as print()). "addon" if the browser can display PDFs, but there's
 * no guarantee that they will respond to a DOM-like API. false if the browser can't display
 * PDFs at all.
 */
export const getPDFSupport = memoize( () => {
	if ( get( window, 'navigator.msSaveOrOpenBlob' ) ) {
		// IE & Edge, they don't support opening a Blob in an iframe/window
		return 'ie';
	}

	if ( isMobile() && ! window.MSStream ) {
		// Mobile browsers don't support triggering a print dialog, so we should load the pdf in a new tab
		// instead. Windows Phones are filtered out with `! window.MSStream` since the user agent
		// string can contain the false positive 'like iPhone'
		return 'addon';
	}

	if ( includes( navigator.userAgent, 'Firefox' ) ) {
		// Use native for Firefox with version > 94
		if (
			parseFloat( navigator.userAgent.split( 'Firefox/' )[ 1 ] ) >= 94
		) {
			return 'native_ff';

			// Use 'addon' for Firefox with version < 94 as it is still not fully tested and might have a long-lived bug (https://bugzilla.mozilla.org/show_bug.cgi?id=911444),
		}
		return 'addon';
	}

	if (
		includes( navigator.userAgent, 'Safari' ) &&
		! includes( navigator.userAgent, 'Chrome' )
	) {
		// In some version of iPads, the navigator.userAgent stopped including the device name.
		// If this Safari does not support native, then given that iOS doesn't support a print
		// dialog, this serves as a catch-all to load the pdf in a new tab.
		// Chrome userAgent has both Safari and Chrome; Safari userAgent has only Safari.
		return 'addon';
	}

	if (
		// @ts-ignore, deprecated property but still supported in some browsers https://developer.mozilla.org/en-US/docs/Web/API/Navigator/mimeTypes
		navigator.mimeTypes?.[ 'application/pdf' ] ||
		navigator.pdfViewerEnabled
	) {
		return 'native';
	}

	if (
		getActiveXObject( 'AcroPDF.PDF' ) ||
		getActiveXObject( 'PDF.PdfCtrl' )
	) {
		// IE with a PDF reader plugin installed
		return 'addon';
	}

	return false;
} );
