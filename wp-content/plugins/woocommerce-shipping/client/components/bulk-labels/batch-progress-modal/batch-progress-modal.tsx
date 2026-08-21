import { Button, Flex, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { closeSmall } from '@wordpress/icons';
import * as Sentry from '@sentry/react';
import type { PurchasableBulkPurchaseOrder } from 'data/bulk-labels';
import { recordEvent } from 'utils';
import { ProgressView } from './progress-view';
import { ResultsView } from './results-view';
import { runBatchPurchase } from 'data/bulk-labels';
import {
	BATCH_INTERRUPTED_ERROR_CODE,
	BATCH_TRANSPORT_ERROR_CODE,
	BatchPurchaseErrorCode,
	BatchPurchaseState,
	OrderRow,
	SettledRow,
} from './types';
import {
	failedRows,
	mapErrorCodeToMessage,
	parseEntry,
	pendingRows,
} from './helpers';
import './style.scss';

export interface BatchProgressModalProps {
	/**
	 * The eligible orders to purchase labels for. The rate-review modal
	 * filters out `needs_fix` rows before invoking the batch flow.
	 */
	orders: PurchasableBulkPurchaseOrder[];
	/**
	 * Shared ship-from address selected in the rate-review step.
	 */
	origin: Record< string, unknown >;
	/**
	 * Initial state used when re-opening the modal after a close (e.g. to
	 * resume a partial-success view without re-running the batch). Pass
	 * `null` to start a fresh run.
	 */
	initialState?: BatchPurchaseState | null;
	/**
	 * Called whenever the modal's internal state changes so the parent
	 * can persist it across close-and-reopen. Receives the latest
	 * snapshot at every transition.
	 */
	onStateChange?: ( state: BatchPurchaseState ) => void;
	onClose: () => void;
}

const buildInitialRows = (
	orders: PurchasableBulkPurchaseOrder[]
): OrderRow[] =>
	orders.map( ( order ) => ( {
		order_id: order.order_id,
		order_number: order.order_number,
		customer_name: order.customer_name,
		status: 'pending',
	} ) );

/**
 * Generic fallback used when `promotePendingToFailures` sees an error
 * code not present in `KNOWN_ERROR_MESSAGES`. The two real call sites
 * pass `BATCH_INTERRUPTED_ERROR_CODE` or `BATCH_TRANSPORT_ERROR_CODE`,
 * both of which are mapped; this fallback keeps the row carrying a
 * non-empty message if a new client-side code is added later without
 * updating the map.
 */
const GENERIC_BATCH_FAILURE_MESSAGE = __(
	'We could not create this label. Open the order to retry.',
	'woocommerce-shipping'
);

/**
 * Promote any still-pending rows to failures with the supplied error
 * code. Used to recover from both close-mid-progress and transport
 * errors. Exported so the close-and-reopen unit test asserts against
 * the real production function instead of a re-implementation.
 *
 * The message is derived from `mapErrorCodeToMessage` so every call
 * site that produces the same error code surfaces identical
 * user-facing copy. Previously each caller passed its own inline
 * string, which drifted: the close-and-reopen path used a shorter
 * sentence than the cancel-as-settled path for the same
 * `batch_interrupted` code.
 */
export const promotePendingToFailures = (
	state: BatchPurchaseState,
	errorCode: BatchPurchaseErrorCode
): BatchPurchaseState => {
	const errorMessage =
		mapErrorCodeToMessage( errorCode ) ?? GENERIC_BATCH_FAILURE_MESSAGE;
	const settled: SettledRow[] = state.rows.map( ( row ) => {
		if ( row.status === 'pending' ) {
			return {
				order_id: row.order_id,
				order_number: row.order_number,
				customer_name: row.customer_name,
				status: 'failed' as const,
				error_code: errorCode,
				error_message: errorMessage,
			};
		}
		return row;
	} );
	return { phase: 'results', rows: settled };
};

/**
 * Build the resume-from-saved-state snapshot. When the merchant closed
 * mid-progress, promote unfinished orders to a `batch_interrupted`
 * failure so the user can spot and retry them; the merchant can then
 * close the modal cleanly. Exported so the unit test in
 * `__tests__/close-and-reopen.test.ts` calls the real function.
 */
export const buildInitialStateFromSaved = (
	initialState: BatchPurchaseState
): BatchPurchaseState => {
	const hasUnsettled = initialState.rows.some(
		( row ) => row.status === 'pending'
	);
	if ( ! hasUnsettled ) {
		return initialState;
	}
	return promotePendingToFailures(
		initialState,
		BATCH_INTERRUPTED_ERROR_CODE
	);
};

export const BatchProgressModal = ( {
	orders,
	origin,
	initialState,
	onStateChange,
	onClose,
}: BatchProgressModalProps ) => {
	const [ state, setState ] = useState< BatchPurchaseState >( () => {
		if ( ! initialState ) {
			return {
				phase: 'progress',
				rows: buildInitialRows( orders ),
			};
		}
		return buildInitialStateFromSaved( initialState );
	} );

	// Keep parent in sync without re-firing the effect when the callback
	// identity changes between renders, and skip the mount fire so we
	// don't trigger a no-op re-render on the parent for the seed state.
	const onStateChangeRef = useRef( onStateChange );
	useEffect( () => {
		onStateChangeRef.current = onStateChange;
	}, [ onStateChange ] );

	const hasFiredInitialStateChange = useRef( false );
	useEffect( () => {
		if ( ! hasFiredInitialStateChange.current ) {
			hasFiredInitialStateChange.current = true;
			return;
		}
		onStateChangeRef.current?.( state );
	}, [ state ] );

	// The dispatch effect must only run once for a given modal mount.
	// The parent (`BulkLabelsApp`) keys the modal on the sorted order
	// list so `orders` is stable for the lifetime of a single mount;
	// re-running would replay the dispatch and double-fire labels. We
	// use a `hasRunRef` guard instead of an
	// `eslint-disable-next-line react-hooks/exhaustive-deps` directive
	// so the disable doesn't leak into the codebase.
	const hasRunRef = useRef( false );

	// Per-row interrupted/transport failure events can fire from two
	// paths during a mid-progress close: `handleClose` (synchronous on
	// the merchant's click) AND the dispatch effect's await-handler
	// (after `handle.cancel()` settles the IIFE). Without a guard, both
	// paths emit `bulk_label_purchase_failed` for every pending row and
	// inflate the funnel. Whichever path fires first sets the ref;
	// the other path checks and skips.
	const hasFiredInterruptedFailuresRef = useRef( false );
	useEffect( () => {
		if ( hasRunRef.current ) {
			return;
		}
		hasRunRef.current = true;

		// Don't restart the dispatch when we're resuming a finished run.
		if ( state.phase === 'results' ) {
			return;
		}

		const controller = new AbortController();

		// Build an order_id → BulkPurchaseOrder map once per mount so
		// `onOrderSettled` does an O(1) lookup instead of an O(n)
		// `orders.find()` per per-order settle. `orders` is stable for
		// the lifetime of the modal (the parent re-keys the modal on
		// the sorted id list), so building the map here is safe.
		const ordersById = new Map(
			orders.map( ( order ) => [ order.order_id, order ] )
		);

		const handle = runBatchPurchase( {
			orders,
			origin,
			signal: controller.signal,
			onOrderSettled: ( orderId, entry ) => {
				const sourceOrder = ordersById.get( orderId );
				if ( ! sourceOrder ) {
					return;
				}
				const settled = parseEntry( sourceOrder, entry );

				if ( settled.status === 'failed' ) {
					recordEvent( 'bulk_label_purchase_failed', {
						error_code: settled.error_code,
						order_id: settled.order_id,
					} );
				}

				setState( ( prev ) => {
					if ( prev.phase !== 'progress' ) {
						return prev;
					}
					const nextRows: OrderRow[] = prev.rows.map( ( row ) =>
						row.order_id === orderId ? settled : row
					);
					return { phase: 'progress', rows: nextRows };
				} );
			},
		} );

		// Transport-level error path. Await the dispatcher promise so
		// request failures land in the catch handler below. When the
		// dispatcher resolves with `kind: 'settled'`, transition any
		// still-pending rows (e.g. cancelled before the response landed)
		// to `BATCH_INTERRUPTED_ERROR_CODE` so the merchant always sees
		// a "Fix and retry" link for them and the failure event fires.
		( async () => {
			try {
				const outcome = await handle.promise;
				if ( outcome.kind === 'settled' ) {
					setState( ( prev ) => {
						const stillPending = pendingRows( prev.rows );
						if ( stillPending.length === 0 ) {
							// Everything settled cleanly. Just flip
							// the phase.
							return {
								phase: 'results',
								rows: prev.rows as SettledRow[],
							};
						}
						// Some rows are still pending. This happens
						// when the run was cancelled mid-flight: mark
						// them as interrupted, fire the failure event
						// for each (only if `handleClose` did not already
						// fire them for the same rows), and surface them
						// in the results view alongside the rows that did
						// settle.
						if ( ! hasFiredInterruptedFailuresRef.current ) {
							hasFiredInterruptedFailuresRef.current = true;
							stillPending.forEach( ( row ) => {
								recordEvent( 'bulk_label_purchase_failed', {
									error_code: BATCH_INTERRUPTED_ERROR_CODE,
									order_id: row.order_id,
								} );
							} );
						}
						return promotePendingToFailures(
							prev,
							BATCH_INTERRUPTED_ERROR_CODE
						);
					} );
				}
			} catch ( err: unknown ) {
				Sentry.captureException( err, {
					tags: { component: 'batch-progress-modal' },
				} );
				recordEvent( 'bulk_label_purchase_transport_failed', {
					message: ( err as Error )?.message ?? 'unknown',
				} );
				setState( ( prev ) => {
					// Fire a per-order failure event for every row that
					// hasn't settled yet so the funnel doesn't silently
					// lose them. Skip if `handleClose` already fired
					// per-row events for the same rows in a close-and-
					// transport-fail race.
					if ( ! hasFiredInterruptedFailuresRef.current ) {
						hasFiredInterruptedFailuresRef.current = true;
						pendingRows( prev.rows ).forEach( ( row ) => {
							recordEvent( 'bulk_label_purchase_failed', {
								error_code: BATCH_TRANSPORT_ERROR_CODE,
								order_id: row.order_id,
							} );
						} );
					}
					return promotePendingToFailures(
						prev,
						BATCH_TRANSPORT_ERROR_CODE
					);
				} );
			}
		} )();

		return () => {
			controller.abort();
			handle.cancel();
		};
	}, [ state.phase, orders, origin ] );

	const isProgress = state.phase === 'progress';
	// Capture this at mount. `onStateChange` updates the parent's saved
	// `initialState` prop while the first run is still in progress; if we
	// recalculate from that prop during the results phase, a fresh purchase
	// is mistaken for a reopened saved result and auto-print never fires.
	const shouldAutoPrintSuccessfulLabelsRef = useRef(
		initialState === null || initialState === undefined
	);

	// Stable ID for the dialog's permanent label, separate from the
	// per-phase title. Assistive tech reads this whenever the dialog
	// receives focus, so it stays valid across the progress -> results
	// phase swap.
	const dialogTitleId = 'bulk-batch-progress-modal-dialog-title';
	// Stable ID for the assertive completion announcement. Lives on
	// the dialog itself (not inside a phase-specific subtree) so the
	// live region stays mounted across the phase swap and the message
	// is announced when results appear.
	const completionAnnouncementId =
		'bulk-batch-progress-modal-completion-announcement';

	const dialogRef = useRef< HTMLDivElement | null >( null );
	const hasFocusedInitialPhaseRef = useRef( false );

	// On phase transition, move focus back to the dialog so keyboard
	// users don't get dropped to the document body. The Modal handles
	// focus on initial mount (`focusOnMount: 'firstContentElement'`),
	// but doesn't re-focus when content swaps; doing it ourselves on
	// every phase change AFTER the first ensures the new phase's
	// controls are reachable by Tab without a hunt-and-peck.
	useEffect( () => {
		if ( ! hasFocusedInitialPhaseRef.current ) {
			hasFocusedInitialPhaseRef.current = true;
			return;
		}
		if ( ! dialogRef.current ) {
			return;
		}
		// The dialog node is the Modal's root. Focusing it makes the
		// next Tab land on the first interactive element inside.
		dialogRef.current.focus();
	}, [ state.phase ] );

	// Build the completion announcement once we land in `results`.
	const completionMessage = ( () => {
		if ( state.phase !== 'results' ) {
			return '';
		}
		const succeededCount = state.rows.filter(
			( r ) => r.status === 'succeeded'
		).length;
		const failedCount = failedRows( state.rows ).length;
		if ( failedCount === 0 ) {
			return __( 'All labels created.', 'woocommerce-shipping' );
		}
		if ( succeededCount === 0 ) {
			return __( 'No labels were created.', 'woocommerce-shipping' );
		}
		return __( 'Some labels need attention.', 'woocommerce-shipping' );
	} )();

	const handleClose = useCallback( () => {
		// Fire an aborted-by-user event when the merchant closes the
		// modal mid-progress, plus a `bulk_label_purchase_failed` for
		// every row still pending. Without the per-row event the funnel
		// would silently lose them: the modal unmounts before the
		// dispatch's cancel-as-settled path can fire its own per-row
		// events. Set the dedup ref before emitting so the cancel-as-
		// settled path (which runs after `onClose()` here) doesn't
		// re-fire the same per-row events.
		if ( state.phase === 'progress' ) {
			const pending = pendingRows( state.rows );
			if ( pending.length > 0 ) {
				recordEvent( 'bulk_label_purchase_aborted_by_user', {
					pending_count: pending.length,
					total_count: state.rows.length,
				} );
				if ( ! hasFiredInterruptedFailuresRef.current ) {
					hasFiredInterruptedFailuresRef.current = true;
					pending.forEach( ( row ) => {
						recordEvent( 'bulk_label_purchase_failed', {
							error_code: BATCH_INTERRUPTED_ERROR_CODE,
							order_id: row.order_id,
						} );
					} );
				}
			}
		}
		onClose();
	}, [ onClose, state ] );

	return (
		<Modal
			title={ __( 'Bulk label purchase', 'woocommerce-shipping' ) }
			onRequestClose={ handleClose }
			// Reuse the rate-review modal's overlay + full-bleed shell so the
			// progress/results modal matches the rest of the bulk flow rather
			// than appearing as a centered floating dialog. The local
			// `bulk-batch-progress-modal` class still scopes the inner layout.
			overlayClassName="bulk-purchase-overlay"
			className="bulk-purchase-modal bulk-batch-progress-modal"
			shouldCloseOnClickOutside={ false }
			shouldCloseOnEsc={ ! isProgress }
			aria={ {
				labelledby: dialogTitleId,
				describedby: completionAnnouncementId,
			} }
			__experimentalHideHeader
			isDismissible={ false }
			ref={ dialogRef }
		>
			{ /*
			 * Permanent dialog-level <h2> that the `aria-labelledby`
			 * points to. The per-phase title lives inside `ProgressView`
			 * / `ResultsView` for sighted readers; this one stays
			 * mounted so assistive tech always has a stable label.
			 */ }
			<h2 id={ dialogTitleId } className="screen-reader-text">
				{ __( 'Bulk label purchase', 'woocommerce-shipping' ) }
			</h2>
			<Flex
				className="bulk-batch-progress-modal__header"
				justify="flex-end"
				align="center"
			>
				<Button
					icon={ closeSmall }
					onClick={ handleClose }
					label={ __( 'Close', 'woocommerce-shipping' ) }
				/>
			</Flex>

			{ state.phase === 'progress' ? (
				<ProgressView rows={ state.rows } />
			) : (
				<ResultsView
					rows={ state.rows }
					onClose={ handleClose }
					autoPrintSuccessfulLabels={
						shouldAutoPrintSuccessfulLabelsRef.current
					}
				/>
			) }

			{ /*
			 * Stable polite live region that emits a single sentence
			 * summary once the phase becomes `results`. Lives outside
			 * the phase subtrees so it survives the swap, which means
			 * screen readers actually announce it.
			 */ }
			<div
				id={ completionAnnouncementId }
				role="status"
				aria-live="polite"
				aria-atomic="true"
				className="screen-reader-text"
			>
				{ completionMessage }
			</div>
		</Modal>
	);
};
