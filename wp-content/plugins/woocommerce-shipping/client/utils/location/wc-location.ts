import { type Country } from '@woocommerce/data';
import { ACCEPTED_USPS_ORIGIN_COUNTRIES } from './constants';

const filterToAllowedCountries = ( countries: Country[] ) =>
	countries.filter( ( { code } ) => {
		return ACCEPTED_USPS_ORIGIN_COUNTRIES.includes( code );
	} );
export const mapToSelectOption = (
	countries: Country[] | Country[ 'states' ] | never[]
) =>
	countries.map( ( { name, code } ) => ( {
		label: name,
		value: code,
	} ) );

export const getAllowedOriginCountries = ( countries: Country[] ) =>
	mapToSelectOption( filterToAllowedCountries( countries ) );

export const getStateForCountry = (
	countryCode: string,
	countries: Country[]
) => {
	const country = countries.find( ( { code } ) => code === countryCode );
	return country ? mapToSelectOption( country.states ) : [];
};
