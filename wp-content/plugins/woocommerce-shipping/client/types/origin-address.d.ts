import { LocationResponse } from './connect-server';
import { CamelCaseType } from './helpers';

export interface OriginAddress
	extends CamelCaseType<
		Omit< LocationResponse, 'address_1' | 'address_2' >
	> {
	address1?: string;
	address2?: string;
}
