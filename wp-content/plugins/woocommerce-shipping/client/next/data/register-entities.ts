/**
 * WordPress dependencies
 */
import { store as coreStore } from '@wordpress/core-data';
import { dispatch } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { ADDRESS_ENTITY } from './constants';

const registered: string[] = [];

export const registerAddressEntity = () => {
	if ( registered.includes( ADDRESS_ENTITY.name ) ) {
		return;
	}
	const { addEntities } = dispatch( coreStore );
	addEntities( [ ADDRESS_ENTITY ] );
	registered.push( ADDRESS_ENTITY.name );
};
