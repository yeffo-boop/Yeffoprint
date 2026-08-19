/**
 * Dedicated @wordpress/data store for the bulk-labels modal's
 * fetch-once resources.
 *
 * The orders shipping-context is a GET-by-ids collection, so it's a
 * core-data *entity* (see register-entity.ts). These two aren't:
 *   - GET /wcshipping/v1/packages is a singleton object, and
 *   - POST /wcshipping/v1/label/auto-assign-packages is body-driven,
 * neither of which maps onto a core-data entity. A small store with
 * resolvers is the idiomatic @wordpress/data tool for them — it gives
 * the same automatic caching / dedupe / resolution-state the entity
 * pattern does, without each hook hand-rolling apiFetch + useState.
 */

import { createReduxStore } from '@wordpress/data';
import { apiFetch, controls as wpControls } from '@wordpress/data-controls';
import { addQueryArgs } from '@wordpress/url';
import { BULK_LABELS_STORE_NAME } from 'data/constants';
import {
	getAutoAssignPackagesPath,
	getBatchLabelPurchasePath,
	getPackagesPath,
} from 'data/routes';

// Mirror the single-label rate/purchase flow: without this the
// /wcshipping/v1/packages response filters out the FedEx & UPSDAP
// predefined schema, so saved predefined boxes lose their dimensions
// (dropped from the dropdown) and auto-assigned FedEx/UPSDAP boxes
// can't be rated.
const FEATURES_SUPPORTED_BY_CLIENT = [ 'upsdap', 'fedex' ];
import {
	buildAssignablePackages,
	type RawPackagesResponse,
} from './packages-transform';
import type {
	AssignablePackage,
	AutoAssignedPackagesMap,
	BatchPurchaseResponse,
	BatchPurchaseShipment,
} from './types';

interface BulkLabelsState {
	assignablePackages?: AssignablePackage[];
	autoAssigned: Record< string, AutoAssignedPackagesMap >;
}

const DEFAULT_STATE: BulkLabelsState = {
	assignablePackages: undefined,
	autoAssigned: {},
};

const SET_ASSIGNABLE_PACKAGES = 'SET_ASSIGNABLE_PACKAGES';
const SET_AUTO_ASSIGNED_PACKAGES = 'SET_AUTO_ASSIGNED_PACKAGES';

/** Stable cache key for an auto-assign batch, order-independent. */
const autoAssignKey = ( orderIds: number[] ): string =>
	[ ...orderIds ].sort( ( a, b ) => a - b ).join( ',' );

const actions = {
	setAssignablePackages( packages: AssignablePackage[] ) {
		return { type: SET_ASSIGNABLE_PACKAGES, packages } as const;
	},
	setAutoAssignedPackages( key: string, results: AutoAssignedPackagesMap ) {
		return { type: SET_AUTO_ASSIGNED_PACKAGES, key, results } as const;
	},
	*purchaseBatchLabels(
		origin: Record< string, unknown >,
		shipments: BatchPurchaseShipment[],
		signal?: AbortSignal
	): Generator<
		ReturnType< typeof apiFetch >,
		BatchPurchaseResponse,
		BatchPurchaseResponse
	> {
		const response: BatchPurchaseResponse = yield apiFetch( {
			path: getBatchLabelPurchasePath(),
			method: 'POST',
			data: { origin, shipments },
			signal,
		} );
		return response ?? {};
	},
};

type Action =
	| ReturnType< typeof actions.setAssignablePackages >
	| ReturnType< typeof actions.setAutoAssignedPackages >;

const reducer = (
	state: BulkLabelsState = DEFAULT_STATE,
	action: Action
): BulkLabelsState => {
	switch ( action.type ) {
		case SET_ASSIGNABLE_PACKAGES:
			return { ...state, assignablePackages: action.packages };
		case SET_AUTO_ASSIGNED_PACKAGES:
			return {
				...state,
				autoAssigned: {
					...state.autoAssigned,
					[ action.key ]: action.results,
				},
			};
		default:
			return state;
	}
};

const selectors = {
	getAssignablePackages(
		state: BulkLabelsState
	): AssignablePackage[] | undefined {
		return state.assignablePackages;
	},
	getAutoAssignedPackages(
		state: BulkLabelsState,
		orderIds: number[]
	): AutoAssignedPackagesMap | undefined {
		return state.autoAssigned[ autoAssignKey( orderIds ) ];
	},
};

const resolvers = {
	*getAssignablePackages() {
		const response: RawPackagesResponse = yield apiFetch( {
			path: addQueryArgs( getPackagesPath(), {
				features_supported_by_client: FEATURES_SUPPORTED_BY_CLIENT,
			} ),
		} );
		return actions.setAssignablePackages(
			buildAssignablePackages( response )
		);
	},
	*getAutoAssignedPackages( orderIds: number[] ) {
		if ( ! orderIds || orderIds.length === 0 ) {
			return actions.setAutoAssignedPackages(
				autoAssignKey( orderIds ?? [] ),
				{}
			);
		}
		const response: AutoAssignedPackagesMap = yield apiFetch( {
			path: getAutoAssignPackagesPath(),
			method: 'POST',
			data: { order_ids: orderIds },
		} );
		return actions.setAutoAssignedPackages(
			autoAssignKey( orderIds ),
			response ?? {}
		);
	},
};

/**
 * Shape of `select( BULK_LABELS_STORE_NAME )` — the data selectors plus
 * the resolution-state meta-selectors @wordpress/data adds for any
 * selector that has a resolver. Lets the hooks stay typed without
 * pulling the (lazily-registered) store descriptor.
 */
export interface BulkLabelsSelect {
	getAssignablePackages: () => AssignablePackage[] | undefined;
	getAutoAssignedPackages: (
		orderIds: number[]
	) => AutoAssignedPackagesMap | undefined;
	hasFinishedResolution: ( selector: string, args: unknown[] ) => boolean;
	getResolutionError: ( selector: string, args: unknown[] ) => unknown;
}

export interface BulkLabelsDispatch {
	purchaseBatchLabels: (
		origin: Record< string, unknown >,
		shipments: BatchPurchaseShipment[],
		signal?: AbortSignal
	) => Promise< BatchPurchaseResponse >;
}

/**
 * Normalize whatever `getResolutionError()` hands back into an `Error`.
 * The data layer may return the raw REST error object (`{ code,
 * message }`) rather than an `Error`, so an `instanceof Error` check
 * alone silently drops real failures.
 */
export const toError = ( value: unknown ): Error | null => {
	if ( ! value ) {
		return null;
	}
	if ( value instanceof Error ) {
		return value;
	}
	if ( typeof value === 'string' ) {
		return new Error( value );
	}
	if ( typeof value === 'object' ) {
		const maybe = value as { message?: unknown; code?: unknown };
		if ( typeof maybe.message === 'string' && maybe.message ) {
			return new Error( maybe.message );
		}
		if ( typeof maybe.code === 'string' && maybe.code ) {
			return new Error( maybe.code );
		}
	}
	return new Error( 'Request failed.' );
};

export const createBulkLabelsStore = () =>
	createReduxStore( BULK_LABELS_STORE_NAME, {
		reducer,
		actions,
		selectors,
		resolvers,
		controls: wpControls,
	} );
