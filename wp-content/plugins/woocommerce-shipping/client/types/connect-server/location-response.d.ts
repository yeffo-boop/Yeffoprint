export interface LocationResponse {
	id: string;
	address_1?: string;
	address_2?: string;
	city: string;
	company?: string;
	email: string;
	first_name: string;
	last_name: string;
	phone: string;
	postcode: string;
	state: string;
	country: string;
	name?: string;
	address?: string;
	is_verified?: boolean;
	default_address?: boolean;
	default_return_address?: boolean;
	/**
	 * Only used in conjunction with the v2 endpoint for now.
	 * It specifies if the address has been approved by the merchant.
	 */
	is_approved?: boolean;
}
