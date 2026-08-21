import { register, select } from '@wordpress/data';
import { ADDRESS_STORE_NAME } from 'data/constants';
import { createStore } from './store';

let addressStore: ReturnType< typeof createStore >;
export const registerAddressStore = ( withDestination: boolean ) => {
	addressStore = addressStore || createStore( withDestination );
	if ( select( ADDRESS_STORE_NAME ) ) {
		return;
	}
	register( addressStore );
};

export { addressStore };
