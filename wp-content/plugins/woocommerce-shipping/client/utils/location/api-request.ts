import { LocationResponse, OriginAddress, RequestAddress } from 'types';
import { snakeCaseKeys } from '../common';
import { composeAddress, composeName } from '../order';

export const mapAddressForRequest = (
	originAddress: OriginAddress
): RequestAddress => {
	const {
		company,
		phone,
		email,
		country,
		state,
		address_2,
		city,
		postcode,
		id,
	} = snakeCaseKeys< OriginAddress, LocationResponse >( originAddress );

	return {
		company,
		name: composeName( {
			first_name: originAddress.firstName,
			last_name: originAddress.lastName,
			name: originAddress.name,
		} ),
		phone,
		email,
		country,
		state,
		address: composeAddress( {
			address_1: originAddress.address1,
			address_2: originAddress.address2,
			address: originAddress.address,
		} ),
		address_2,
		city,
		postcode,
		id,
	};
};
