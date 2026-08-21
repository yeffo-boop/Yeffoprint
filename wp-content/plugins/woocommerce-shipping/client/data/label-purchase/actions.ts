import { apiFetch } from '@wordpress/data-controls';
import {
	ORDER_STATUS_UPDATE_FAILED,
	ORDER_STATUS_UPDATED,
	RATES_FETCH_ABORTED,
	RATES_FETCH_FAILED,
	RATES_FETCHED,
	RATES_RESET,
	SHIPMENTS_UPDATE_FAILED,
	SHIPMENTS_UPDATED,
	STATE_RESET,
} from './action-types';
import { getRatesPath, getShipmentsPath, getWCOrdersPath } from 'data/routes';
import { select } from '@wordpress/data';
import {
	AbortedResponse,
	abortableApiFetch,
	isAbortError,
	mapAddressForRequest,
} from 'utils';
import { OriginAddress, RequestExtraOptions } from 'types';
import {
	RatesFetchedAction,
	RatesFetchFailedAction,
	RatesFetchAbortedAction,
	RatesResetAction,
	StateResetAction,
} from './types.d';
import { addressStore } from '../address';

export function* updateShipments( {
	shipments,
	orderId,
	shipmentIdsToUpdate,
}: {
	shipments: unknown;
	orderId: string;
	shipmentIdsToUpdate: Record< string, number | string >;
} ): Generator<
	ReturnType< typeof apiFetch >,
	{
		type: typeof SHIPMENTS_UPDATED | typeof SHIPMENTS_UPDATE_FAILED;
		result?: unknown;
		error?: Record< string, string >;
	},
	{
		success: boolean;
		data: string; // JSON string
	}
> {
	try {
		const result = yield apiFetch( {
			path: getShipmentsPath( orderId ),
			method: 'POST',
			data: { shipments, shipmentIdsToUpdate },
		} );
		return {
			type: SHIPMENTS_UPDATED,
			result,
		};
	} catch ( error: unknown ) {
		return {
			type: SHIPMENTS_UPDATE_FAILED,
			error: error as Record< string, string >,
		};
	}
}

export function* getRates<
	FetchReply = RatesFetchedAction[ 'payload' ] | AbortedResponse,
>( payload: {
	orderId: string | number;
	origin: OriginAddress;
	packages: unknown[];
	is_return?: boolean;
	shipment_options?: RequestExtraOptions;
} ): Generator<
	ReturnType< typeof abortableApiFetch >,
	RatesFetchedAction | RatesFetchFailedAction | RatesFetchAbortedAction,
	FetchReply
> {
	const destination = select( addressStore ).getPreparedDestination();
	const { orderId, origin, ...restOfPayload } = payload;

	try {
		const result = yield abortableApiFetch< FetchReply >(
			{
				path: getRatesPath(),
				method: 'POST',
				data: {
					order_id: orderId,
					destination,
					origin: mapAddressForRequest( origin ),
					...restOfPayload,
					features_supported_by_client: [ 'upsdap', 'fedex' ],
				},
			},
			'get-rates'
		);

		if ( isAbortError( result ) ) {
			return {
				type: RATES_FETCH_ABORTED,
			} as RatesFetchAbortedAction;
		}

		return {
			type: RATES_FETCHED,
			payload: result,
		};
	} catch ( error ) {
		return {
			type: RATES_FETCH_FAILED,
			payload: error as Record< string, string >,
		};
	}
}

export function* updateOrderStatus( {
	orderId,
	status,
}: {
	orderId: string;
	status: string;
} ): Generator<
	ReturnType< typeof apiFetch >,
	{
		type: typeof ORDER_STATUS_UPDATED | typeof ORDER_STATUS_UPDATE_FAILED;
		payload: { status: string } | { error: string };
	},
	{
		status: string;
	}
> {
	try {
		const result = yield apiFetch( {
			path: getWCOrdersPath( orderId ),
			method: 'PUT',
			data: { status },
		} );
		return {
			type: ORDER_STATUS_UPDATED,
			payload: result,
		};
	} catch ( error ) {
		return {
			type: ORDER_STATUS_UPDATE_FAILED,
			payload: { error: ( error as Error ).message },
		};
	}
}

export const ratesReset = (): RatesResetAction => ( {
	type: RATES_RESET,
} );

export const stateReset = (): StateResetAction => ( {
	type: STATE_RESET,
} );
