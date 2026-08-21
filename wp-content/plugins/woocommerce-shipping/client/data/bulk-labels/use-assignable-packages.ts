import { useSelect } from '@wordpress/data';
import { BULK_LABELS_STORE_NAME } from 'data/constants';
import { toError, type BulkLabelsSelect } from './store';
import type { AssignablePackage } from './types';

interface UseAssignablePackagesResult {
	isResolving: boolean;
	error: Error | null;
	packages: AssignablePackage[];
}

const EMPTY_PACKAGES: AssignablePackage[] = [];

/**
 * The merchant's selectable box packages for the per-order package
 * dropdown. Backed by the bulk-labels @wordpress/data store, so the
 * GET /wcshipping/v1/packages call is made once and shared/cached
 * across every row — no per-hook apiFetch/useState. `enabled` gates
 * the selector so nothing is fetched until the modal opens.
 */
export const useAssignablePackages = (
	enabled: boolean
): UseAssignablePackagesResult =>
	useSelect(
		( select ): UseAssignablePackagesResult => {
			if ( ! enabled ) {
				return {
					isResolving: false,
					error: null,
					packages: EMPTY_PACKAGES,
				};
			}
			const store = select(
				BULK_LABELS_STORE_NAME
			) as unknown as BulkLabelsSelect;
			const packages = store.getAssignablePackages();
			const resolutionError = store.getResolutionError(
				'getAssignablePackages',
				[]
			);
			return {
				packages: packages ?? EMPTY_PACKAGES,
				isResolving: ! store.hasFinishedResolution(
					'getAssignablePackages',
					[]
				),
				error: toError( resolutionError ),
			};
		},
		[ enabled ]
	);
