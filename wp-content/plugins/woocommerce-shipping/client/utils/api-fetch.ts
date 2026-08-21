import type { APIFetchOptions } from '@wordpress/api-fetch';
import { apiFetch } from '@wordpress/data-controls';

export const ABORTED_RESPONSE_TYPE = 'latestApiFetchAborted';
const previousAbortControllers: Record< string, AbortController[] > = {};

export interface ApiFetchControl {
	type: 'API_FETCH';
	request: APIFetchOptions;
}

export const isAbortError = ( error: unknown ): boolean =>
	typeof error === 'object' &&
	error !== null &&
	'name' in error &&
	error.name === 'AbortError';

export type AbortedResponse = DOMException & {
	name: 'AbortError';
};

/**
 * Performs an API fetch request that can be aborted if a new request with the same ID is made.
 * This is useful for preventing race conditions in scenarios where multiple API calls could be
 * made in quick succession, such as fetching rates on user input changes.
 *
 * When a new request is made with the same ID, any pending requests with that ID will be automatically
 * aborted. This ensures that only the most recent request's response will be processed.
 *
 * @template T The expected response type from the API
 * @param {APIFetchOptions} request - The request options to be passed to apiFetch
 * @param {string}          id      - A unique identifier for this request group. Requests with the same ID will abort previous pending requests
 *
 * @example
 * ```ts
 * const response = yield* abortableApiFetch({ path: '/api/get-rates', method: 'GET' }, 'get-rates');
 * if (isAbortError(response)) {
 *   // Handle aborted request
 *   return;
 * }
 * // Handle successful response
 * ```
 */
export function* abortableApiFetch< T >(
	request: APIFetchOptions,
	id: string
): Generator<
	ReturnType< typeof apiFetch >,
	T | AbortedResponse,
	T | AbortedResponse
> {
	// Initialize the array if it doesn't exist
	if ( ! previousAbortControllers[ id ] ) {
		previousAbortControllers[ id ] = [];
	}

	// Remove aborted controllers
	previousAbortControllers[ id ] = previousAbortControllers[ id ].filter(
		( controller ) => ! controller.signal.aborted
	);

	// Abort previous requests
	previousAbortControllers[ id ].forEach( ( controller ) =>
		controller.abort()
	);

	const currentAbortController = new AbortController();
	previousAbortControllers[ id ].push( currentAbortController );
	try {
		const result = yield apiFetch( {
			...request,
			signal: currentAbortController.signal,
		} );

		return result;
	} catch ( error ) {
		// If the error is not an abort error, throw it for the user to handle
		if ( ! isAbortError( error ) ) {
			throw error;
		} else {
			// If the error is an abort error, return the aborted response type for the user to handle
			// Not throwing as it's not an error, but a response type.
			return error as AbortedResponse;
		}
	}
}
