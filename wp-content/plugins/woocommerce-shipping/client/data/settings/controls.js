import apiFetch from '@wordpress/api-fetch';
import { getAccountSettingsPath } from '../routes';

export const API_FETCH_ACCOUNT_SETTINGS = async () => {
	const result = await apiFetch( {
		path: getAccountSettingsPath(),
		method: 'GET',
	} );

	return result;
};

export const API_SAVE_ACCOUNT_SETTINGS = async ( action ) => {
	const result = await apiFetch( {
		path: getAccountSettingsPath(),
		method: 'POST',
		data: action.payload,
	} );

	return result;
};
