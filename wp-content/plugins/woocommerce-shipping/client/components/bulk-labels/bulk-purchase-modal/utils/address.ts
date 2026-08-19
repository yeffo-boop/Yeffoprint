import { composeAddress } from 'utils';

const getStringField = ( value: unknown ): string =>
	typeof value === 'string' ? value.trim() : '';

const firstStringField = ( ...values: unknown[] ): string =>
	values.map( getStringField ).find( Boolean ) ?? '';

/**
 * Convert the shipping-context destination to the single-label purchase
 * destination shape. The shipping-context/rate quote shape contains
 * `address_1`, `state_code`, and `country_code`, but Connect Server's label
 * purchase endpoint rejects those fields.
 */
export const toPurchaseDestination = ( order: {
	customer_name?: unknown;
	destination?: unknown;
} ): Record< string, unknown > => {
	const source =
		order.destination &&
		typeof order.destination === 'object' &&
		! Array.isArray( order.destination )
			? ( order.destination as Record< string, unknown > )
			: {};
	const company = getStringField( source.company );
	const name =
		getStringField( source.name ) ||
		getStringField( order.customer_name ) ||
		company;

	return {
		company,
		name,
		phone: getStringField( source.phone ),
		email: getStringField( source.email ),
		address: composeAddress( {
			address: getStringField( source.address ),
			address_1: getStringField( source.address_1 ),
			address_2: getStringField( source.address_2 ),
		} ),
		address_2: getStringField( source.address_2 ),
		city: getStringField( source.city ),
		state: firstStringField( source.state, source.state_code ),
		postcode: getStringField( source.postcode ),
		country: firstStringField( source.country, source.country_code ),
		residential: source.residential ?? ! company,
	};
};

export const toPurchaseOrigin = (
	origin: Record< string, unknown >
): Record< string, unknown > => {
	const company = getStringField( origin.company );
	const name = getStringField( origin.name ) || company;

	return {
		company,
		name,
		phone: getStringField( origin.phone ),
		address: composeAddress( {
			address: getStringField( origin.address ),
			address_1: getStringField( origin.address_1 ),
			address_2: getStringField( origin.address_2 ),
		} ),
		address_2: getStringField( origin.address_2 ),
		city: getStringField( origin.city ),
		state: getStringField( origin.state ),
		postcode: getStringField( origin.postcode ),
		country: getStringField( origin.country ),
		id: origin.id,
		is_verified: origin.is_verified,
	};
};
