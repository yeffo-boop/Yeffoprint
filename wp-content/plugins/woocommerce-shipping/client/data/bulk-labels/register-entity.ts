import { dispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { ORDERS_SHIPPING_CONTEXT_ENTITY } from './constants';

let registered = false;

/**
 * Register the orders-shipping-context entity with @wordpress/core-data
 * so the modal can read it via useEntityRecords. Idempotent; safe to
 * call from every entrypoint that mounts the bulk-labels UI.
 */
export const registerOrdersShippingContextEntity = (): void => {
	if ( registered ) {
		return;
	}
	registered = true;
	void dispatch( coreStore ).addEntities( [
		ORDERS_SHIPPING_CONTEXT_ENTITY,
	] );
};
