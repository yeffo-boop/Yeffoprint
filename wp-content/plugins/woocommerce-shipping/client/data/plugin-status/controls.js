import apiFetch from '@wordpress/api-fetch';
import { NAMESPACE } from '../constants';

export const API_SAVE_DEBUG_TOGGLE = async ( action ) => {
	const result = await apiFetch( {
		path: `${ NAMESPACE }/self-help`,
		headers: { 'X-WP-Nonce': action.nonce },
		method: 'POST',
		data: {
			wcc_debug_on: action.payload.debugEnabled,
			wcc_logging_on: action.payload.loggingEnabled,
		},
	} );
	return result;
};

export const API_REFRESH_SERVICE_DATA = async ( action ) => {
	const result = await apiFetch( {
		path: `${ NAMESPACE }/service-data-refresh`,
		headers: { 'X-WP-Nonce': action.nonce },
		method: 'POST',
	} );
	return result;
};
