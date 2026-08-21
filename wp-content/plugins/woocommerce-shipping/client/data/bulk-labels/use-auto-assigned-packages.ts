import { useSelect } from '@wordpress/data';
import { BULK_LABELS_STORE_NAME } from 'data/constants';
import { toError, type BulkLabelsSelect } from './store';
import type { AutoAssignedPackagesMap } from './types';

interface UseAutoAssignedPackagesResult {
	isResolving: boolean;
	error: Error | null;
	results: AutoAssignedPackagesMap;
}

const EMPTY_RESULTS: AutoAssignedPackagesMap = {};

/**
 * Per-order box-packer suggestions for the given orders, backed by the
 * bulk-labels @wordpress/data store. The POST
 * /wcshipping/v1/label/auto-assign-packages call runs once per distinct
 * order-id set (resolver-cached); reopening the modal with the same
 * selection reuses the cached result.
 */
export const useAutoAssignedPackages = (
	orderIds: number[]
): UseAutoAssignedPackagesResult => {
	const idsKey = orderIds.join( ',' );

	return useSelect(
		( select ): UseAutoAssignedPackagesResult => {
			if ( orderIds.length === 0 ) {
				return {
					isResolving: false,
					error: null,
					results: EMPTY_RESULTS,
				};
			}
			const store = select(
				BULK_LABELS_STORE_NAME
			) as unknown as BulkLabelsSelect;
			const results = store.getAutoAssignedPackages( orderIds );
			const resolutionError = store.getResolutionError(
				'getAutoAssignedPackages',
				[ orderIds ]
			);
			return {
				results: results ?? EMPTY_RESULTS,
				isResolving: ! store.hasFinishedResolution(
					'getAutoAssignedPackages',
					[ orderIds ]
				),
				error: toError( resolutionError ),
			};
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps -- idsKey covers orderIds contents
		[ idsKey ]
	);
};
