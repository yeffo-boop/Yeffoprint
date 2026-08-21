import type { Country } from '@woocommerce/data';

export interface Continent {
	code: string;
	name: string;
	countries: Country[];
}
