/* eslint-disable @typescript-eslint/no-explicit-any */

import type { OriginAddress, Destination } from 'types';

export const mockOriginAddress: OriginAddress = {
	id: 'origin_1',
	name: 'Test Origin',
	firstName: 'Test',
	lastName: 'Origin',
	address: '123 Test St',
	city: 'Test City',
	state: 'CA',
	postcode: '90210',
	country: 'US',
	email: 'test@example.com',
	phone: '555-1234',
	isVerified: true,
	defaultAddress: true,
};

export const mockStoreOrigin: OriginAddress = {
	id: 'store_origin',
	name: 'Store Origin',
	firstName: 'Store',
	lastName: 'Origin',
	address: '456 Store St',
	city: 'Store City',
	state: 'NY',
	postcode: '10001',
	country: 'US',
	email: 'store@example.com',
	phone: '555-5678',
	isVerified: true,
	defaultAddress: false,
};

export const mockDestination: Destination = {
	firstName: 'Customer',
	lastName: 'Name',
	name: 'Customer Name',
	address: '789 Customer St',
	city: 'Customer City',
	state: 'TX',
	postcode: '73301',
	country: 'US',
	email: 'customer@example.com',
	phone: '555-9999',
};

/**
 * Creates a mock createReducer function for testing address reducers
 */
export const createMockReducer = ( defaultState: any ) => {
	const handlers = new Map();
	const reducer = ( state = defaultState, action: any ) => {
		const handler = handlers.get( action.type );
		if ( handler ) {
			return handler( state, action );
		}
		return state;
	};

	reducer.on = ( actionType: string, handler: any ) => {
		handlers.set( actionType, handler );
		return reducer;
	};

	reducer.bind = () => reducer;

	return reducer;
};

/**
 * Mock utilities for address reducer tests
 */
export const mockAddressUtils = {
	createReducer: createMockReducer,
	getOriginAddresses: () => [ mockOriginAddress ],
	getFirstSelectableOriginAddress: () => mockOriginAddress,
	getStoreOrigin: () => mockStoreOrigin,
	getCurrentOrderShipTo: () => mockDestination,
	getIsDestinationVerified: () => false,
	isOriginAddress: ( address: any ) => !! address.id,
};
