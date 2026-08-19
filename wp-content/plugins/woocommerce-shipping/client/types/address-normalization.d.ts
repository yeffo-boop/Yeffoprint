import { LocationResponse } from './connect-server';

export interface AddressNormalization< T = LocationResponse > {
	isTrivialNormalization: boolean;
	address: T;
	normalizedAddress: T;
	errors?: Record< string, string >;
}
