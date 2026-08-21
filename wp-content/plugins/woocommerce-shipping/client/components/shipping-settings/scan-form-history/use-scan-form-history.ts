/**
 * Custom hook for managing ScanForm history data
 */

import { useState, useCallback, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import type { ScanFormHistoryResponse, ScanFormHistoryItem } from 'types';
import { getScanFormHistoryPath } from 'data/routes';

export const useScanFormHistory = () => {
	const [ scanForms, setScanForms ] = useState< ScanFormHistoryItem[] >( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ page, setPage ] = useState( 1 );
	const [ total, setTotal ] = useState( 0 );
	const [ perPage, setPerPage ] = useState( 25 );

	const fetchScanForms = useCallback(
		async ( pageNum: number, itemsPerPage: number ) => {
			setIsLoading( true );
			setError( null );

			try {
				const response = await apiFetch< ScanFormHistoryResponse >( {
					path: getScanFormHistoryPath( pageNum, itemsPerPage ),
					method: 'GET',
				} );

				if ( response.success ) {
					setScanForms( response.scan_forms );
					setTotal( response.total );
				} else {
					setError( 'Failed to fetch SCAN Form history.' );
				}
			} catch ( err ) {
				setError(
					err instanceof Error
						? err.message
						: 'Failed to fetch SCAN Form history.'
				);
			} finally {
				setIsLoading( false );
			}
		},
		[]
	);

	useEffect( () => {
		void fetchScanForms( page, perPage );
	}, [ page, perPage, fetchScanForms ] );

	return {
		scanForms,
		isLoading,
		error,
		page,
		total,
		perPage,
		setPage,
		setPerPage,
	};
};
