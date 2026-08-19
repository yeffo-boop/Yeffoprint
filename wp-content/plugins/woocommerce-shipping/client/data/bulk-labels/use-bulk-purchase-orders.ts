import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import {
	buildBatchSummary,
	buildBulkPurchaseOrders,
	findLargestAddressGroup,
} from './display';
import { ORDERS_SHIPPING_CONTEXT_ENTITY } from './constants';
import { toError } from './store';
import { useAutoAssignedPackages } from './use-auto-assigned-packages';
import type {
	AddressGrouping,
	BatchSummary,
	BulkPurchaseOrder,
	ManualPackageSelections,
	OrderShippingContextRecord,
} from './types';

const EMPTY_MANUAL_SELECTIONS: ManualPackageSelections = {};

interface UseBulkPurchaseOrdersResult {
	isResolving: boolean;
	error: Error | null;
	records: OrderShippingContextRecord[];
	orders: BulkPurchaseOrder[];
	summary: BatchSummary;
	grouping: AddressGrouping | null;
}

const EMPTY_RECORDS: OrderShippingContextRecord[] = [];

/**
 * Reactive hook that pulls orders shipping context via core-data's
 * useEntityRecords, then layers on the WOOSHIP-2133 fields the
 * endpoint doesn't yet provide (service, cost, status, note) so the
 * modal can render every column. Components reading this hook won't
 * have to change once those fields land for real.
 */
export const useBulkPurchaseOrders = (
	orderIds: number[],
	manualSelections: ManualPackageSelections = EMPTY_MANUAL_SELECTIONS
): UseBulkPurchaseOrdersResult => {
	// Stringify so a fresh array reference with the same IDs doesn't
	// re-trigger the fetch.
	const idsKey = orderIds.join( ',' );
	const hasIds = orderIds.length > 0;

	const [ records, setRecords ] =
		useState< OrderShippingContextRecord[] >( EMPTY_RECORDS );
	const [ isShippingContextResolving, setIsShippingContextResolving ] =
		useState( false );
	const [ error, setError ] = useState< Error | null >( null );

	useEffect( () => {
		if ( ! hasIds ) {
			setRecords( EMPTY_RECORDS );
			setError( null );
			setIsShippingContextResolving( false );
			return;
		}

		let isCurrent = true;
		setRecords( EMPTY_RECORDS );
		setError( null );
		setIsShippingContextResolving( true );

		void apiFetch< OrderShippingContextRecord[] >( {
			path: addQueryArgs( ORDERS_SHIPPING_CONTEXT_ENTITY.baseURL, {
				ids: orderIds,
			} ),
		} )
			.then( ( nextRecords ) => {
				if ( ! isCurrent ) {
					return;
				}
				setRecords(
					Array.isArray( nextRecords ) ? nextRecords : EMPTY_RECORDS
				);
			} )
			.catch( ( fetchError ) => {
				if ( ! isCurrent ) {
					return;
				}
				setError( toError( fetchError ) );
			} )
			.finally( () => {
				if ( isCurrent ) {
					setIsShippingContextResolving( false );
				}
			} );

		return () => {
			isCurrent = false;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- idsKey covers orderIds contents.
	}, [ idsKey, hasIds ] );

	const safeRecords = useMemo( () => records ?? EMPTY_RECORDS, [ records ] );
	const usableRecords = useMemo(
		() => safeRecords.filter( ( record ) => ! record.error ),
		[ safeRecords ]
	);

	// Only ask the box-packer for orders that actually loaded — orders that
	// errored out of the shipping-context fetch are skipped entirely so we
	// don't waste a batch slot on rows the modal will never render.
	const assignableOrderIds = useMemo(
		() => usableRecords.map( ( record ) => record.order_id ),
		[ usableRecords ]
	);

	const {
		isResolving: isAutoAssignResolving,
		results: autoAssignedPackages,
		error: autoAssignError,
	} = useAutoAssignedPackages( assignableOrderIds );

	const orders = useMemo(
		() =>
			buildBulkPurchaseOrders(
				usableRecords,
				autoAssignedPackages,
				manualSelections
			),
		[ usableRecords, autoAssignedPackages, manualSelections ]
	);
	const summary = useMemo( () => buildBatchSummary( orders ), [ orders ] );
	const grouping = useMemo(
		() => findLargestAddressGroup( usableRecords ),
		[ usableRecords ]
	);

	return {
		// Hold the modal in its loading state until both the shipping-context
		// fetch and the box-packer auto-assign call have settled, so the
		// table doesn't flash placeholder packages before the suggestion
		// arrives.
		isResolving:
			hasIds && ( isShippingContextResolving || isAutoAssignResolving ),
		// Surface a failed auto-assign as a modal-level error rather than
		// letting rows fall back to placeholder packages and read as
		// "ready". The shipping-context error takes precedence since
		// without records there's nothing to suggest packages for.
		error: error ?? autoAssignError,
		records: safeRecords,
		orders,
		summary,
		grouping,
	};
};
