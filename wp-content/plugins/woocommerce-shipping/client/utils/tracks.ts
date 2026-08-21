/**
 * External imports
 */
import domReady from '@wordpress/dom-ready';
import _ from 'lodash';

/**
 * Check if site tracking is enabled.
 *
 * @return boolean
 */
export const isEnabled = (): boolean => window.wcTracks.isEnabled;

/**
 * Record a tracks using WC global object.
 *
 * By default WC adds `url`, `blog_lang`, `blog_id`, `store_id`, `products_count`, and `wc_version`
 * properties to every event, we extend this with WooCommerce Shipping specific properties.
 *
 * @param eventName       The name of the event to record without the `wcadmin_wcshipping_` prefix.
 * @param eventProperties Object of additional name=>value properties to include with the event.
 */
export const recordEvent = (
	eventName: string,
	eventProperties: Record< string, unknown > = {}
): void => {
	// Check if WooCommerce Shipping global settings object is available to extend the event properties.
	if ( window.wcShippingSettings ) {
		Object.assign( eventProperties, window.wcShippingSettings );

		// Filter out any undefined properties.
		_.omitBy( eventProperties, _.isNil );
	}
	// Prepend the event name with `wcshipping_` to namespace the event, this gets further namespaced by WC to `wcadmin_wcshipping_`.
	eventName = `wcshipping_${ eventName }`;

	// Wait for DOM to be ready before recording the event.
	domReady( () => {
		const recordFunction =
			window.wc?.tracks?.recordEvent ?? window.wcTracks.recordEvent;
		recordFunction( eventName, eventProperties );
	} );
};
