import { createElement, useEffect, useState } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { Modal, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import * as Sentry from '@sentry/react';
import { BulkLabelsBanner, BulkPrintDialog } from 'components/bulk-labels';
import { BulkPurchaseModal } from 'components/bulk-labels/bulk-purchase-modal';
import {
	BatchProgressModal,
	type BatchPurchaseState,
} from 'components/bulk-labels/batch-progress-modal';
import {
	getBulkLabelsMaxOrders,
	registerBulkLabelsStore,
	registerOrdersShippingContextEntity,
} from 'data/bulk-labels';
import type { PurchasableBulkPurchaseOrder } from 'data/bulk-labels';
import type { LabelPrintFulfillmentRef } from 'types';
import { initSentry } from 'utils';

initSentry();
registerOrdersShippingContextEntity();
registerBulkLabelsStore();

const CONTAINER_ID = 'wcshipping-bulk-labels-banner-root';
const BULK_ACTION_VALUE = 'wcshipping_create_shipping_labels';

let root: ReturnType< typeof createRoot > | null = null;

/**
 * Read order IDs from the WP orders form's selected row checkboxes. Used
 * when the merchant triggers the modal via the native bulk-action dropdown.
 */
const getCheckedOrderIdsFromForm = (): number[] => {
	const checkboxes = document.querySelectorAll< HTMLInputElement >(
		'#the-list input[type="checkbox"][name="id[]"]:checked,' +
			' #the-list input[type="checkbox"][name="post[]"]:checked'
	);

	const ids = Array.from( checkboxes )
		.map( ( cb ) => Number( cb.value ) )
		.filter( ( id ) => Number.isFinite( id ) && id > 0 );

	// Drop a Sentry breadcrumb when malformed checkbox values are
	// silently filtered out so the gap is visible if it ever becomes
	// non-zero (e.g. a WP markup change).
	if ( ids.length !== checkboxes.length ) {
		Sentry.addBreadcrumb( {
			category: 'bulk-labels',
			level: 'warning',
			message:
				'Some bulk-labels order checkboxes had non-numeric values; dropped.',
			data: {
				expected: checkboxes.length,
				actual: ids.length,
			},
		} );
	}

	return ids;
};

/**
 * Whether the orders form's bulk-action <select> currently targets the
 * "Fulfill with labels" action. WP renders both a top and bottom dropdown,
 * so we read the one whose Apply button was clicked.
 */
const formTargetsBulkLabelsAction = (
	form: HTMLFormElement,
	submitter: HTMLElement | null
): boolean => {
	const which =
		submitter?.closest( '.tablenav.bottom' ) !== null ? 'bottom' : 'top';
	const select = form.querySelector< HTMLSelectElement >(
		which === 'top' ? 'select[name="action"]' : 'select[name="action2"]'
	);
	return select?.value === BULK_ACTION_VALUE;
};

const isTestPrintLabelRef = ( ref: unknown ): ref is LabelPrintFulfillmentRef =>
	typeof ref === 'object' &&
	ref !== null &&
	'label_id' in ref &&
	'fulfillment_id' in ref &&
	typeof ref.label_id === 'number' &&
	typeof ref.fulfillment_id === 'number' &&
	ref.label_id > 0 &&
	ref.fulfillment_id > 0;

/**
 * Wrapper component that owns the modal open/close state. Kept inline in
 * the entrypoint so the banner stays presentational and the modal lifecycle
 * lives next to the form-submit interception.
 *
 * State machine:
 *   none -> rate-review (BulkPurchaseModal)
 *   rate-review -> progress/results (BatchProgressModal)
 *
 * The rate-review modal closes when the merchant hands off to the
 * batch-progress modal so the labels phase owns the screen.
 *
 * `batchState` stores a single snapshot of the most recent batch: the
 * order rows and their statuses. When the merchant closes the modal
 * mid-progress and reopens it for the same selection, that snapshot
 * lets `BatchProgressModal` resume on the saved state instead of
 * starting a fresh run. The snapshot is replaced on every new dispatch,
 * so starting a different selection clears the previous batch. Long
 * term we will key this by the sorted order-id list to keep
 * concurrent batches separate; today only one batch is in flight at a
 * time, so a single snapshot is enough.
 */
const BulkLabelsApp = () => {
	const [ reviewOrderIds, setReviewOrderIds ] = useState< number[] | null >(
		null
	);
	const [ batchOrders, setBatchOrders ] = useState<
		PurchasableBulkPurchaseOrder[] | null
	>( null );
	const [ batchOrigin, setBatchOrigin ] = useState< Record<
		string,
		unknown
	> | null >( null );
	const [ batchState, setBatchState ] = useState< BatchPurchaseState | null >(
		null
	);
	const [ capExceeded, setCapExceeded ] = useState( false );
	const [ testPrintLabelRefs, setTestPrintLabelRefs ] = useState<
		LabelPrintFulfillmentRef[] | null
	>( null );

	// Dev-only test entry point so the merged-PDF print dialog can be
	// exercised directly in the browser without running a full batch. Set
	// `?wcshipping_test_bulk_print=10:100,11:101` in the URL on the orders
	// list page, or call `window.__wcshippingBulkPrintTest([{ label_id: 10,
	// fulfillment_id: 100 }])`
	// from the console. Both branches are dev-only so production builds
	// cannot be coaxed into printing arbitrary fulfillment-backed labels via a
	// crafted URL.
	useEffect( () => {
		if ( process.env.NODE_ENV === 'production' ) {
			return;
		}

		const fromQuery = new URLSearchParams( window.location.search ).get(
			'wcshipping_test_bulk_print'
		);
		if ( fromQuery ) {
			const labelRefs = fromQuery
				.split( ',' )
				.map( ( pair ) =>
					pair.split( ':' ).map( ( id ) => Number( id.trim() ) )
				)
				.filter(
					( pair ): pair is [ number, number ] =>
						pair.length === 2 &&
						pair.every( ( id ) => Number.isFinite( id ) && id > 0 )
				)
				.map( ( [ label_id, fulfillment_id ] ) => ( {
					label_id,
					fulfillment_id,
				} ) );
			if ( labelRefs.length > 0 ) {
				setTestPrintLabelRefs( labelRefs );
			} else {
				// eslint-disable-next-line no-console
				console.warn(
					'[wcshipping] wcshipping_test_bulk_print parsed to empty list (input: "%s")',
					fromQuery
				);
			}
		}

		(
			window as unknown as Record< string, unknown >
		 ).__wcshippingBulkPrintTest = ( refs: unknown ) => {
			const labelRefs = Array.isArray( refs )
				? refs.filter( isTestPrintLabelRef )
				: [];

			if ( labelRefs.length > 0 ) {
				setTestPrintLabelRefs( labelRefs );
				return;
			}

			setTestPrintLabelRefs( null );
			// eslint-disable-next-line no-console
			console.warn(
				'[wcshipping] __wcshippingBulkPrintTest needs at least one fulfillment-backed label ref.'
			);
		};

		return () => {
			// Clean up the dev-only window hook on unmount so React-Refresh
			// remounts (or test teardown) don't leak the binding.
			delete ( window as unknown as Record< string, unknown > )
				.__wcshippingBulkPrintTest;
		};
	}, [] );

	useEffect( () => {
		const form = document.querySelector< HTMLFormElement >(
			'#wpbody-content form#posts-filter, #wpbody-content form#wc-orders-filter'
		);
		if ( ! form ) {
			return;
		}

		const onSubmit = ( event: SubmitEvent ) => {
			const submitter =
				event.submitter instanceof HTMLElement ? event.submitter : null;
			if ( ! formTargetsBulkLabelsAction( form, submitter ) ) {
				return;
			}

			// Always swallow the submit when our action is selected,
			// otherwise the form posts to WP's bulk handler and the page
			// reloads with no feedback (even for the zero-rows case).
			event.preventDefault();

			const ids = getCheckedOrderIdsFromForm();
			if ( ids.length === 0 ) {
				setCapExceeded( false );
				return;
			}

			// Match the batch endpoint contract. Refuse the bulk-action
			// submit when the merchant has more than the allowed number of
			// orders selected, rather than letting the modal open and 400
			// on the shipping-context fetch.
			if ( ids.length > getBulkLabelsMaxOrders() ) {
				setCapExceeded( true );
				return;
			}

			setCapExceeded( false );
			setReviewOrderIds( ids );
		};

		form.addEventListener( 'submit', onSubmit );
		return () => {
			form.removeEventListener( 'submit', onSubmit );
		};
	}, [] );

	/**
	 * Uncheck the orders-list row checkboxes for the given ids so the
	 * merchant cannot accidentally re-purchase labels that were just
	 * created. WP renders one of two checkbox names depending on
	 * legacy vs HPOS table mode; clear both shapes by `value`.
	 */
	const uncheckOrderRows = ( orderIds: number[] ): void => {
		if ( orderIds.length === 0 ) {
			return;
		}
		const selector = orderIds
			.map(
				( id ) =>
					`#the-list input[type="checkbox"][name="id[]"][value="${ id }"], #the-list input[type="checkbox"][name="post[]"][value="${ id }"]`
			)
			.join( ', ' );
		document
			.querySelectorAll< HTMLInputElement >( selector )
			.forEach( ( cb ) => {
				if ( cb.checked ) {
					cb.checked = false;
				}
			} );
	};

	const handleBatchClose = () => {
		// Clear the source checkboxes for orders that succeeded so a
		// second "Apply" of Fulfill-with-labels on the same selection
		// doesn't try to re-purchase the already-printed labels. Rows
		// that failed stay checked so the merchant can fix and re-run.
		const succeededIds = ( batchState?.rows ?? [] )
			.filter( ( row ) => row.status === 'succeeded' )
			.map( ( row ) => row.order_id );
		const shouldPreserveInterruptedBatch =
			batchState?.phase === 'progress' &&
			batchState.rows.some( ( row ) => row.status === 'pending' );
		uncheckOrderRows( succeededIds );
		setBatchOrders( null );
		setBatchOrigin( null );
		if ( ! shouldPreserveInterruptedBatch ) {
			setBatchState( null );
		}
	};

	const openBatchFor = (
		orders: PurchasableBulkPurchaseOrder[],
		origin: Record< string, unknown >
	) => {
		// Numeric comparator: default `.sort()` is lexicographic, so
		// [2, 10, 100] would compare as ['10','100','2'] and two
		// different selections could produce equal keys.
		const incomingIds = orders
			.map( ( o ) => o.order_id )
			.sort( ( a, b ) => a - b );
		const previousIds = ( batchState?.rows ?? [] )
			.map( ( r ) => r.order_id )
			.sort( ( a, b ) => a - b );
		const sameBatch =
			incomingIds.length === previousIds.length &&
			incomingIds.every( ( id, idx ) => id === previousIds[ idx ] );
		const canResumeInterruptedBatch = batchState?.phase === 'progress';

		// Reset persisted state when the merchant kicks off a fresh
		// selection, or when the previous run already reached results, so
		// we don't replay stale label IDs instead of buying new labels.
		if ( ! sameBatch || ! canResumeInterruptedBatch ) {
			setBatchState( null );
		}

		setBatchOrders( orders );
		setBatchOrigin( origin );
		setReviewOrderIds( null );
	};

	return (
		<>
			{ capExceeded && (
				<Notice
					className="bulk-labels-banner"
					status="error"
					onRemove={ () => setCapExceeded( false ) }
				>
					{ sprintf(
						/* translators: %d: maximum number of orders that can be processed at once */
						__(
							'You can process up to %d orders at a time. Please reduce your selection and try again.',
							'woocommerce-shipping'
						),
						getBulkLabelsMaxOrders()
					) }
				</Notice>
			) }
			<BulkLabelsBanner onCreateLabels={ setReviewOrderIds } />
			{ reviewOrderIds !== null && (
				<BulkPurchaseModal
					orderIds={ reviewOrderIds }
					onClose={ () => setReviewOrderIds( null ) }
					onCreateLabels={ openBatchFor }
				/>
			) }
			{ batchOrders !== null && batchOrigin !== null && (
				<BatchProgressModal
					// Stable per-selection key so a fresh selection
					// remounts the modal and the run-once dispatch
					// effect inside re-fires from scratch instead of
					// holding on to the previous batch's state. Use a
					// numeric comparator so [2, 10, 100] doesn't collide
					// with selections that share lexicographic order.
					key={ batchOrders
						.map( ( o ) => o.order_id )
						.sort( ( a, b ) => a - b )
						.join( ',' ) }
					orders={ batchOrders }
					origin={ batchOrigin }
					initialState={ batchState }
					onStateChange={ setBatchState }
					onClose={ handleBatchClose }
				/>
			) }
			{ testPrintLabelRefs !== null && testPrintLabelRefs.length > 0 && (
				// Dev-only modal wrapper for direct bulk-print dialog QA.
				// The real flow mounts the same print control inside the
				// batch-results modal; this wrapper gives the standalone
				// test hook a centered container instead of leaving it in
				// the bulk-labels banner row.
				<Modal
					title={ __( 'Print labels', 'woocommerce-shipping' ) }
					onRequestClose={ () => setTestPrintLabelRefs( null ) }
					shouldCloseOnClickOutside={ false }
					className="bulk-print-dialog-test-modal"
				>
					<BulkPrintDialog
						labelRefs={ testPrintLabelRefs }
						autoPrint
					/>
				</Modal>
			) }
		</>
	);
};

const mount = () => {
	if ( document.getElementById( CONTAINER_ID ) ) {
		return;
	}

	// Insert the banner container above the bulk actions tablenav.
	const tablenav = document.querySelector< HTMLElement >( '.tablenav.top' );
	if ( ! tablenav ) {
		return;
	}

	const container = document.createElement( 'div' );
	container.id = CONTAINER_ID;
	tablenav.parentNode?.insertBefore( container, tablenav );

	root ??= createRoot( container );
	root.render( createElement( BulkLabelsApp ) );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
