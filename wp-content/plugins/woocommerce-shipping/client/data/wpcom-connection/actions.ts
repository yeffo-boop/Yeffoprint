import { apiFetch } from '@wordpress/data-controls';
import { getWPCOMConnectionPath } from '../routes';
import { CREATING_CONNECTION_FAILED, CREATE_CONNECTION } from './action-types';
import { WPCOMConnectionCreationPayload, WPErrorRESTResponse } from 'types';

export function* createConnection( {
	payload,
}: WPCOMConnectionCreationPayload ): Generator<
	ReturnType< typeof apiFetch >,
	{
		type: typeof CREATE_CONNECTION | typeof CREATING_CONNECTION_FAILED;
		payload?: {
			redirectUrl?: string;
			source?: string;
			error?: WPErrorRESTResponse;
		};
	},
	{
		redirect_url: string;
		error: WPErrorRESTResponse;
	}
> {
	try {
		const { redirect_url: redirectUrl } = yield apiFetch( {
			path: getWPCOMConnectionPath(),
			method: 'POST',
			data: {
				return_url: payload.returnUrl,
				source: payload.source,
			},
		} );

		return {
			type: CREATE_CONNECTION,
			payload: {
				redirectUrl,
			},
		};
	} catch ( error ) {
		return {
			type: CREATING_CONNECTION_FAILED,
			payload: {
				error: error as WPErrorRESTResponse,
			},
		};
	}
}
