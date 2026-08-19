export interface Entity {
	name: string;
	kind: string;
	baseURL: string;
	label: string;
	plural: string;
	key: string;
	supportsPagination: boolean;
	getTitle?: ( record: unknown ) => string;
}

export interface AddressEntityRecord {
	id: string;
	first_name: string;
	last_name: string;
	company: string;
	address_1: string;
	city: string;
	state: string;
	postcode: string;
	country: string;
	email: string;
	phone: string;
	default_address: boolean;
	default_return_address: boolean;
	is_verified: boolean;
	is_approved: boolean;
}
