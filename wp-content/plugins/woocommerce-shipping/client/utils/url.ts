/**
 * Update the browser history state.
 *
 * @param params
 */
const updateBrowserHistoryState = ( params: URLSearchParams ) => {
	const newUrl = `${ window.location.pathname }?${ params.toString() }`;
	window.history.replaceState( {}, '', newUrl );
};

/**
 * Get the URL parameters.
 *
 * @return URLSearchParams
 */
const getUrlParams = (): URLSearchParams =>
	new URLSearchParams( window.location.search );

/**
 * Check if the URL parameter has a specific value.
 *
 * @param parameter
 * @param value
 *
 * @return boolean
 */
export const urlParamHasValue = ( parameter: string, value: string ): boolean =>
	value === getUrlParams().get( parameter );

/**
 * Set the value of a URL parameter.
 *
 * @param parameter
 * @param value
 */
export const setUrlParamValue = ( parameter: string, value: string ) => {
	const params = getUrlParams();
	params.set( parameter, value );
	updateBrowserHistoryState( params );
};

/**
 * Delete a URL parameter.
 *
 * @param parameter
 */
export const deleteUrlParam = ( parameter: string ) => {
	const params = getUrlParams();
	params.delete( parameter );
	updateBrowserHistoryState( params );
};
