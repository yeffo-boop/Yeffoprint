import { FormErrors } from '@woocommerce/components';
import { CustomsItem } from './customs-item';
import { CustomsState } from './customs-state';
import { AddressTypes } from './address-types';
import { OriginAddress } from './origin-address';

export interface ValidationInput< T > {
	errors: FormErrors< T >;
	values: T;
}

export interface CustomsValidationInput
	extends ValidationInput< CustomsState > {
	errors: FormErrors< CustomsState > & { items: FormErrors< CustomsItem >[] };
}

export interface AddressValidationInput< A = OriginAddress >
	extends ValidationInput< A > {
	errors: FormErrors< OriginAddress >;
	type?: AddressTypes;
}
