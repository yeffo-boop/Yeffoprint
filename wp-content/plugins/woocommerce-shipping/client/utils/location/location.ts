import { find, flatMap, get, isEmpty, isEqual, omit, sortBy } from 'lodash';
// @ts-ignore can't find type definitions for @woocommerce/settings
import { getSetting } from '@woocommerce/settings';
import { composeAddress, composeName } from '../order';
import {
	Destination,
	LocationResponse,
	OriginAddress,
	WCShippingConfig,
} from 'types';
import {
	ACCEPTED_USPS_ORIGIN_COUNTRIES,
	US_MILITARY_STATES,
	US_TERRITORY_STATES,
} from './constants';
import { getConfig } from '../config';
import { camelCaseKeys } from '../common';

const getContinents = ( config: WCShippingConfig ) =>
	get( config, [ 'continents' ] );

export const getCountryName =
	( config: WCShippingConfig = getConfig() ) =>
	( countryCode: string ) => {
		const country = find( flatMap( getContinents( config ), 'countries' ), {
			code: countryCode,
		} );
		if ( ! country ) {
			return countryCode;
		}
		return country.name;
	};

export const getAllCountries = ( config: WCShippingConfig ) => {
	const allCountries = flatMap( getContinents( config ), 'countries' );
	return sortBy( allCountries, 'name' );
};

export const getAllCountryNames = (
	config: WCShippingConfig
): {
	label: string;
	value: string;
}[] => {
	const countries = getAllCountries( config );
	return countries.reduce( ( acc, { code, name } ) => {
		return [
			...acc,
			{
				label: name,
				value: code,
			},
		];
	}, [] );
};

const getOriginCountryNames = (
	config: WCShippingConfig
): ReturnType< typeof getAllCountryNames > =>
	getAllCountryNames( config ).filter( ( { value } ) =>
		ACCEPTED_USPS_ORIGIN_COUNTRIES.includes( value )
	);
export const getCountryNames = (
	group: 'destination' | 'origin' | 'all',
	countryCode: string | undefined | null,
	config = getConfig()
) => {
	let countryNames =
		group === 'origin'
			? getOriginCountryNames( config )
			: getAllCountryNames( config );

	// If the selected country is not supported but the user managed to select it, add it to the list
	if (
		countryCode &&
		! countryNames.find( ( c ) => countryCode === c.value )
	) {
		countryNames = [
			...countryNames,
			{
				label: countryCode,
				value: getCountryName( config )( countryCode ),
			},
		];
	}

	return countryNames;
};

export const getStates = (
	countryCode: string,
	config: WCShippingConfig = getConfig()
) => {
	const country = find( flatMap( getContinents( config ), 'countries' ), {
		code: countryCode,
	} );
	if ( ! country ) {
		return [];
	}
	return sortBy( country.states, 'name' );
};

export const getStateNames = (
	countryCode: string,
	config: WCShippingConfig = getConfig()
) => {
	const states = getStates( countryCode, config );
	let names: Record< string, string > = states.reduce(
		( acc, { code, name } ) => {
			const key = `${ code }`.startsWith( `${ countryCode }-` )
				? `${ code }`.replace( `${ countryCode }-`, '' )
				: code;
			return {
				...acc,
				[ key ]: name,
			};
		},
		{} as Record< string, string >
	);

	if ( countryCode === 'US' ) {
		// Filter out military addresses
		names = omit( names, US_MILITARY_STATES );
	}
	return Object.entries( names ).reduce(
		( acc, [ value, label ] ) => [
			...acc,
			{
				label,
				value,
			},
		],
		[] as {
			label: string;
			value: string;
		}[]
	);
};

export const hasStates = (
	countryCode: string,
	config: WCShippingConfig = getConfig()
) => {
	return ! isEmpty( getStates( countryCode, config ) );
};

export const getStoreOrigin = () => {
	return {
		country: getSetting( 'baseLocation' )?.country ?? 'US',
		state: getSetting( 'baseLocation' )?.state ?? 'CA',
	};
};

/**
 * US territories can be addressed either as their own country code (after
 * address normalization) or as country "US" with the territory in the state
 * field (when the merchant declines normalization). Collapse both encodings to
 * a single effective country code so customs handling is consistent regardless
 * of which form the address takes.
 */
const getEffectiveCustomsCountry = (
	address: Pick< LocationResponse, 'country' | 'state' >
): string | undefined => {
	if (
		address?.country === 'US' &&
		US_TERRITORY_STATES.includes( address?.state )
	) {
		return address.state;
	}
	return address?.country;
};

export const isCustomsRequired = (
	origin: Pick< LocationResponse, 'country' | 'state' >,
	destination: Pick< LocationResponse, 'country' | 'state' >
) => {
	if (
		! isEqual(
			getEffectiveCustomsCountry( origin ),
			getEffectiveCustomsCountry( destination )
		)
	) {
		return true;
	}

	if (
		origin?.country === 'US' &&
		US_MILITARY_STATES.includes( origin?.state )
	) {
		return true;
	}

	if (
		destination?.country === 'US' &&
		US_MILITARY_STATES.includes( destination?.state )
	) {
		return true;
	}

	return false;
};

/**
 * USPS enforces the customs `itemDescription` 30-char limit only on its
 * domestic-mail label path (US + US territories + military APO/FPO/DPO, which
 * are country `US`). True international labels go through a different path and
 * are not subject to it. A shipment uses the domestic-mail path when both
 * endpoints are USPS domestic-mail territories — mirrors the backend
 * `USPSTerritories::DOMESTIC_MAIL_TERRITORIES`.
 */
export const isUSPSDomesticMailShipment = (
	originCountry?: string,
	destinationCountry?: string
): boolean => {
	if ( ! originCountry || ! destinationCountry ) {
		return false;
	}
	return (
		ACCEPTED_USPS_ORIGIN_COUNTRIES.includes( originCountry ) &&
		ACCEPTED_USPS_ORIGIN_COUNTRIES.includes( destinationCountry )
	);
};

export const getOriginAddresses = (
	config: WCShippingConfig = getConfig()
) => {
	return ( config.origin_addresses ?? [] )
		.map( ( originalAddress ) => ( {
			...originalAddress,
			address: composeAddress( originalAddress ),
			name: composeName( originalAddress ),
		} ) )
		.map( camelCaseKeys );
};

export const getFirstSelectableOriginAddress = (
	config: WCShippingConfig = getConfig()
) => {
	const originAddresses = getOriginAddresses( config );
	const defaultAddress = originAddresses.find(
		( address ) => address.defaultAddress
	);

	if ( defaultAddress ) {
		return defaultAddress;
	}

	// Prioritize store address with ID 'store_details' as default return address
	const storeAddress = originAddresses.find(
		( address ) => address.id === 'store_details'
	);

	return (
		storeAddress ??
		( originAddresses.length > 0 ? originAddresses[ 0 ] : undefined )
	);
};

export const getDefaultOutboundAddress = (
	config: WCShippingConfig = getConfig()
) => {
	const originAddresses = getOriginAddresses( config );
	const defaultAddress = originAddresses.find(
		( address ) => address.defaultAddress
	);

	if ( defaultAddress ) {
		return defaultAddress;
	}

	// Fallback to first selectable address
	return getFirstSelectableOriginAddress( config );
};

export const getDefaultReturnAddress = (
	config: WCShippingConfig = getConfig()
) => {
	const originAddresses = getOriginAddresses( config );
	const defaultReturnAddress = originAddresses.find(
		( address ) => address.defaultReturnAddress
	);

	if ( defaultReturnAddress ) {
		return defaultReturnAddress;
	}

	// Fallback to first selectable address
	return getFirstSelectableOriginAddress( config );
};

export const isOriginAddress = (
	address: Destination | OriginAddress
): address is OriginAddress => 'id' in address;

export const isCountryInEU = (
	countryCode: string,
	config: WCShippingConfig = getConfig()
) => config.eu_countries.includes( countryCode.toUpperCase() );
