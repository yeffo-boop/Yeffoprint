import apiFetch from '@wordpress/api-fetch';
import { dispatch } from '@wordpress/data';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { LabelPrintFulfillmentRef, PaperSize, PDFJson } from 'types';
import {
	getPaperSizes,
	getPaperSizeWithKey,
} from 'components/label-purchase/label/utils';
import { getConfig } from 'utils/config';
import {
	getPrintURL,
	getStoreOrigin,
	PopupBlockedError,
	printDocument,
	recordEvent,
} from 'utils';
import { persistPaperSize } from 'utils/label/persist-paper-size';

export type BulkLabelPrintErrorKind = 'print_error' | 'no_labels';

/**
 * Error thrown by the bulk-label print hook. Extends Error so consumers can
 * use `instanceof BulkLabelPrintError` at the catch site and a stray TypeError
 * doesn't crash the Notice mapper.
 */
export class BulkLabelPrintError extends Error {
	public readonly kind: BulkLabelPrintErrorKind;
	public readonly messages: [ string, ...string[] ];

	constructor(
		kind: BulkLabelPrintErrorKind,
		messages: [ string, ...string[] ]
	) {
		super( messages[ 0 ] );
		this.name = 'BulkLabelPrintError';
		this.kind = kind;
		this.messages = messages;
		// Subclassing `Error` can break `instanceof` after transpilation
		// targeting ES5 because the prototype chain is not always set up
		// automatically. Repairing it here keeps `e instanceof
		// BulkLabelPrintError` reliable regardless of the build target.
		Object.setPrototypeOf( this, new.target.prototype );
	}
}

/**
 * Strip a trailing `( <three-digit-number> )` HTTP-status suffix from a
 * server error message before surfacing it to the merchant.
 *
 * Background: the bulk-print fetch can resolve as HTTP 500 from the
 * plugin while the upstream Connect Server body contains a different
 * status like 404 baked into the prose ("Not Found label not found.
 * ( 404 )"). Showing both is confusing — the network panel reads 500
 * but the modal says 404 — and merchants do not need the HTTP code in
 * either case. The status stays available in Sentry and Tracks via the
 * original error object, so dropping the parenthetical from the
 * displayed copy loses nothing for support.
 *
 * Constrained to 3-digit numbers to avoid trimming legitimate
 * parenthetical numbers from arbitrary upstream messages.
 */
const stripHttpStatusSuffix = ( message: string ): string =>
	message.replace( /\s*\(\s*\d{3}\s*\)\s*$/, '' ).trimEnd();

export const useBulkLabelPrint = (
	labelRefs: LabelPrintFulfillmentRef[]
): UseBulkLabelPrintApi => {
	const country = getStoreOrigin().country;
	// `getPaperSizes` always returns at least the two US sizes for any
	// country, so the non-empty tuple cast is safe and lets the caller rely
	// on `paperSizes[0]` without an optional chain.
	//
	// Memoize by country so consumers can put `paperSizes` in a hook
	// dependency array without re-firing the effect on every render
	// (the array would otherwise be a fresh reference each call).
	const paperSizes = useMemo< [ PaperSize, ...PaperSize[] ] >(
		() => getPaperSizes( country ) as [ PaperSize, ...PaperSize[] ],
		[ country ]
	);

	const savedPaperSizeKey =
		getConfig()?.accountSettings?.purchaseSettings?.paper_size;
	const savedPaperSize = savedPaperSizeKey
		? getPaperSizeWithKey( savedPaperSizeKey, country )
		: undefined;
	const hasWarnedRef = useRef( false );
	useEffect( () => {
		if ( savedPaperSizeKey && ! savedPaperSize && ! hasWarnedRef.current ) {
			hasWarnedRef.current = true;
			// eslint-disable-next-line no-console
			console.warn(
				'[wcshipping] Saved paper size "%s" not available for "%s"; falling back to "%s".',
				savedPaperSizeKey,
				country,
				paperSizes[ 0 ].key
			);
			// Surface the silent fallback in the WP notice system so the
			// merchant knows their saved preference is not honoured on this
			// store. The console line above is still useful for support.
			(
				dispatch( 'core/notices' ) as {
					createInfoNotice: (
						message: string,
						options?: { isDismissible?: boolean }
					) => void;
				}
			 ).createInfoNotice(
				__(
					"We couldn't apply your saved paper-size preference on this store. We are using the default size instead.",
					'woocommerce-shipping'
				),
				{ isDismissible: true }
			);
		}
	}, [ savedPaperSizeKey, savedPaperSize, country, paperSizes ] );
	const initialPaperSize = savedPaperSize ?? paperSizes[ 0 ];

	const [ selectedPaperSize, setSelectedPaperSize ] =
		useState< PaperSize >( initialPaperSize );
	const [ isPrinting, setIsPrinting ] = useState( false );

	// Track unmount so async resolutions don't update React state after the
	// dialog has been torn down. Without this, a fetch that returns after
	// the merchant closes the dialog still calls setIsPrinting and emits a
	// "succeeded" track event.
	const mountedRef = useRef( true );
	useEffect( () => {
		mountedRef.current = true;
		return () => {
			mountedRef.current = false;
		};
	}, [] );

	const printMergedLabels = useCallback(
		async ( size: PaperSize = selectedPaperSize ): Promise< void > => {
			if ( ! labelRefs.length ) {
				throw new BulkLabelPrintError( 'no_labels', [
					__(
						'No successful labels to print.',
						'woocommerce-shipping'
					),
				] );
			}

			setIsPrinting( true );
			try {
				const pdfJson = await apiFetch< PDFJson >( {
					path: getPrintURL( size.key, labelRefs, country ),
					method: 'GET',
				} );
				await printDocument( pdfJson, 'bulk-labels.pdf' );
				if ( mountedRef.current ) {
					recordEvent( 'bulk_label_print_succeeded', {
						label_count: labelRefs.length,
						paper_size: size.key,
					} );
				}
			} catch ( e ) {
				// WP REST errors come back as `{ code, message, data }` so
				// `instanceof Error` is false. Pull `.message` when it is a
				// string; otherwise drop the detail rather than printing
				// "[object Object]" to the merchant.
				let errMessage = '';
				if ( e instanceof Error ) {
					errMessage = stripHttpStatusSuffix( e.message );
				} else if (
					e &&
					typeof e === 'object' &&
					'message' in e &&
					typeof ( e as { message: unknown } ).message === 'string'
				) {
					errMessage = stripHttpStatusSuffix(
						( e as { message: string } ).message
					);
				}
				const isPopupBlocked = e instanceof PopupBlockedError;

				let messages: [ string, ...string[] ];
				if ( isPopupBlocked ) {
					messages = [
						__(
							'Allow popups for this site to print your labels.',
							'woocommerce-shipping'
						),
					];
				} else {
					const generic = __(
						'Error printing labels, try to print later.',
						'woocommerce-shipping'
					);
					messages = errMessage
						? [ generic, errMessage ]
						: [ generic ];
				}

				if ( mountedRef.current ) {
					recordEvent( 'bulk_label_print_failed', {
						label_count: labelRefs.length,
						paper_size: size.key,
						cause: isPopupBlocked ? 'popup_blocked' : 'print_error',
					} );
				}

				throw new BulkLabelPrintError( 'print_error', messages );
			} finally {
				if ( mountedRef.current ) {
					setIsPrinting( false );
				}
			}
		},
		[ country, labelRefs, selectedPaperSize ]
	);

	const [ isPersisting, setIsPersisting ] = useState( false );
	const hasNotifiedPersistFailureRef = useRef( false );
	const selectPaperSize = useCallback( async ( size: PaperSize ) => {
		setSelectedPaperSize( size );
		setIsPersisting( true );
		try {
			await persistPaperSize( size.key );
		} catch ( e ) {
			// Don't block the print flow; the merchant chose a size
			// locally and the persistence is best-effort. Surface a single
			// warning per mount so the merchant knows the saved preference
			// won't survive the next session.
			// eslint-disable-next-line no-console
			console.warn( '[wcshipping] Failed to persist paper size', e );
			if ( ! hasNotifiedPersistFailureRef.current ) {
				hasNotifiedPersistFailureRef.current = true;
				(
					dispatch( 'core/notices' ) as {
						createWarningNotice: (
							message: string,
							options?: { isDismissible?: boolean }
						) => void;
					}
				 ).createWarningNotice(
					__(
						"We couldn't save your paper-size preference. Your next print will still use the size you picked.",
						'woocommerce-shipping'
					),
					{ isDismissible: true }
				);
			}
		} finally {
			if ( mountedRef.current ) {
				setIsPersisting( false );
			}
		}
	}, [] );

	return {
		paperSizes,
		selectedPaperSize,
		selectPaperSize,
		printMergedLabels,
		isPrinting,
		isPersisting,
	};
};

/**
 * Public shape returned by `useBulkLabelPrint`. Exposed so the dialog (and any
 * future consumer that drives bulk printing) can type-check against the same
 * contract instead of inferring an anonymous object.
 *
 * `selectPaperSize` is documented as never-rejecting: failures during the
 * persist call are caught inside the hook and surfaced via a WP notice.
 */
export interface UseBulkLabelPrintApi {
	paperSizes: [ PaperSize, ...PaperSize[] ];
	selectedPaperSize: PaperSize;
	/** Pick a new paper size. Persists in the background; never rejects. */
	selectPaperSize: ( size: PaperSize ) => Promise< void >;
	printMergedLabels: ( size?: PaperSize ) => Promise< void >;
	isPrinting: boolean;
	isPersisting: boolean;
}
