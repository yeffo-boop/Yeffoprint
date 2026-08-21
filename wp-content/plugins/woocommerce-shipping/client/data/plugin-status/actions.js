import {
	STATUS_INIT,
	STATUS_REFRESH_SERVICE_DATA,
	STATUS_SAVE_DEBUG_TOGGLE,
} from './action-types';

export function init( payload ) {
	return {
		type: STATUS_INIT,
		payload,
	};
}

export function* toggleDebug( { nonce, payload } ) {
	yield {
		type: 'API_SAVE_DEBUG_TOGGLE',
		nonce,
		payload,
	};

	return {
		type: STATUS_SAVE_DEBUG_TOGGLE,
		payload,
	};
}

export function* refreshServiceData( { nonce } ) {
	const result = yield {
		type: 'API_REFRESH_SERVICE_DATA',
		nonce,
	};

	return {
		type: STATUS_REFRESH_SERVICE_DATA,
		payload: { ...result },
	};
}
