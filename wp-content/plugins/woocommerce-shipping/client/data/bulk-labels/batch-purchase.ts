/**
 * Real batch-purchase dispatcher for the bulk-labels flow.
 *
 * Wires the rate-review-selected orders into the plugin's batch
 * label-purchase endpoint at `POST /wcshipping/v1/label/purchase/batch`.
 * The endpoint returns one entry per order; this dispatcher forwards those
 * entries one-by-one so the progress modal can update each row.
 */

import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import * as Sentry from '@sentry/react';
import { BULK_LABELS_STORE_NAME } from 'data/constants';
import type { BulkLabelsDispatch } from './store';
import type {
	BatchPurchaseEntry,
	BatchPurchaseResponse,
	BatchPurchaseShipment,
	PurchasableBulkPurchaseOrder,
} from './types';

export interface BulkBatchOutcome {
	kind: 'settled';
	response: BatchPurchaseResponse;
}

export interface BulkBatchPurchaseHandle {
	cancel: () => void;
	/**
	 * Resolves when every order has settled, or when the request is
	 * cancelled. Rejects when the batch request itself fails.
	 */
	promise: Promise< BulkBatchOutcome >;
}

export interface RunBatchPurchaseArgs {
	orders: PurchasableBulkPurchaseOrder[];
	/**
	 * Shared ship-from address for the whole batch. This comes from the
	 * same origins endpoint used by the bulk rate-review modal, not the
	 * single-label page config.
	 */
	origin: Record< string, unknown >;
	/** Fires once per order as the batch response is parsed. */
	onOrderSettled: ( orderId: number, entry: BatchPurchaseEntry ) => void;
	/** AbortSignal for the modal-close cancel path. */
	signal?: AbortSignal;
}

const isAbortError = ( err: unknown ): boolean =>
	typeof err === 'object' &&
	err !== null &&
	'name' in err &&
	( err as { name?: string } ).name === 'AbortError';

const emptyResponse = (): BatchPurchaseResponse => ( {} );

/**
 * Build a single `shipments[]` entry for the batch purchase request.
 * The package payload reuses the dimensions/weight from the rate quote,
 * plus the selected rate IDs required by the label purchase endpoint.
 */
const buildShipment = (
	order: PurchasableBulkPurchaseOrder
): BatchPurchaseShipment => {
	const rate = order.selected_rate;
	const pkg = order.request_package;
	const packagePayload = {
		id: pkg.id,
		box_id: pkg.box_id,
		length: pkg.length,
		width: pkg.width,
		height: pkg.height,
		weight: pkg.weight,
		is_letter: pkg.is_letter,
		service_id: rate.service_id,
		carrier_id: rate.carrier_id,
		service_name: rate.service_name,
		shipment_id: rate.shipment_id,
		rate_id: rate.rate_id,
		products: pkg.products ?? [],
	};

	return {
		order_id: order.order_id,
		destination: order.purchase_destination,
		packages: [ packagePayload ],
		selected_rate: {
			rate: {
				rate_id: rate.rate_id,
				service_id: rate.service_id,
				carrier_id: rate.carrier_id,
				title: rate.service_name,
				rate: rate.rate,
				retail_rate: rate.retail_rate,
				shipment_id: rate.shipment_id,
			},
			parent: null,
		},
		selected_rate_options: {},
		hazmat: {},
		customs: {},
		features_supported_by_client: [ 'upsdap', 'fedex' ],
		shipment_options: {},
		is_return: false,
	};
};

const settleResponseEntries = (
	response: BatchPurchaseResponse,
	orders: PurchasableBulkPurchaseOrder[],
	onOrderSettled: RunBatchPurchaseArgs[ 'onOrderSettled' ]
): void => {
	const settledKeys = new Set< string >();

	orders.forEach( ( order, index ) => {
		const key = [
			`order_${ order.order_id }`,
			// Be tolerant of legacy numeric keys if a mocked server
			// returns them without the `order_` prefix.
			String( order.order_id ),
			// `invalid_order_<index>` keys map to the shipment position
			// in the request body. Use that to surface shape errors in-row.
			`invalid_order_${ index }`,
		].find( ( candidate ) =>
			Object.prototype.hasOwnProperty.call( response, candidate )
		);

		if ( ! key ) {
			return;
		}

		settledKeys.add( key );
		onOrderSettled( order.order_id, response[ key ] );
	} );

	Object.keys( response ).forEach( ( key ) => {
		if ( settledKeys.has( key ) ) {
			return;
		}
		Sentry.addBreadcrumb( {
			category: 'batch-progress-modal',
			level: 'warning',
			message: 'Batch response key did not match any order.',
			data: { key },
		} );
	} );
};

export const runBatchPurchase = ( {
	orders,
	origin,
	onOrderSettled,
	signal,
}: RunBatchPurchaseArgs ): BulkBatchPurchaseHandle => {
	const controller = new AbortController();
	let cancelled = false;
	let finished = false;

	let resolvePromise!: ( value: BulkBatchOutcome ) => void;
	let rejectPromise!: ( reason: Error ) => void;
	const promise = new Promise< BulkBatchOutcome >( ( resolve, reject ) => {
		resolvePromise = resolve;
		rejectPromise = reject;
	} );

	let externalAbortHandler: ( () => void ) | null = null;
	const removeExternalAbortListener = () => {
		if ( signal && externalAbortHandler ) {
			signal.removeEventListener( 'abort', externalAbortHandler );
			externalAbortHandler = null;
		}
	};

	const finish = ( response: BatchPurchaseResponse ) => {
		if ( finished ) {
			return;
		}
		finished = true;
		removeExternalAbortListener();
		resolvePromise( { kind: 'settled', response } );
	};

	const fail = ( err: Error ) => {
		if ( finished ) {
			return;
		}
		finished = true;
		removeExternalAbortListener();
		rejectPromise( err );
	};

	const cancel = () => {
		if ( cancelled || finished ) {
			return;
		}
		cancelled = true;
		controller.abort();
		// A batch purchase is one request, so cancel means we do not have
		// trusted per-order results. Let the modal promote pending rows to
		// `batch_interrupted`.
		finish( emptyResponse() );
	};

	if ( signal ) {
		if ( signal.aborted ) {
			cancel();
			return { cancel, promise };
		}
		externalAbortHandler = () => cancel();
		signal.addEventListener( 'abort', externalAbortHandler, {
			once: true,
		} );
	}

	if ( orders.length === 0 ) {
		finish( emptyResponse() );
		return { cancel, promise };
	}

	if ( Object.keys( origin ).length === 0 ) {
		fail(
			new Error(
				__(
					'No origin address is configured for batch label purchase.',
					'woocommerce-shipping'
				)
			)
		);
		return { cancel, promise };
	}

	( async () => {
		try {
			const response = await (
				dispatch(
					BULK_LABELS_STORE_NAME
				) as unknown as BulkLabelsDispatch
			 ).purchaseBatchLabels(
				origin,
				orders.map( buildShipment ),
				controller.signal
			);

			if ( cancelled || controller.signal.aborted ) {
				return;
			}

			const responseMap = response ?? emptyResponse();
			settleResponseEntries( responseMap, orders, onOrderSettled );
			finish( responseMap );
		} catch ( err: unknown ) {
			if ( isAbortError( err ) || cancelled ) {
				return;
			}
			fail(
				err instanceof Error
					? err
					: new Error(
							__(
								'Batch label-purchase request failed.',
								'woocommerce-shipping'
							)
					  )
			);
		}
	} )();

	return { cancel, promise };
};
