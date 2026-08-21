import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { getServiceStatusPath } from 'data/routes';
import { ServiceStatusNotice, ServiceStatusResponse } from 'types';

/**
 * Hook to fetch service status notices from the Connect Server.
 * Fetches on mount and returns any active notices.
 */
export function useServiceStatus() {
	const [ notices, setNotices ] = useState< ServiceStatusNotice[] >( [] );

	useEffect( () => {
		apiFetch< ServiceStatusResponse >( {
			path: getServiceStatusPath(),
			method: 'GET',
		} )
			.then( ( response ) => {
				setNotices( response.notices ?? [] );
			} )
			.catch( () => {
				// Silently fail - service status is informational, not critical.
				setNotices( [] );
			} );
	}, [] );

	return { notices };
}
