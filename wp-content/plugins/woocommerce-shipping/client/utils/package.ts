import { mapValues } from 'lodash';
import { AvailablePackages, CamelCaseType, CustomPackageResponse } from 'types';
import { camelCaseKeys } from './common';

export const camelCasePackageResponse = ( packages: {
	custom: CustomPackageResponse[];
	predefined: Record< string, string[] >;
} ) =>
	mapValues( packages, ( value, key ) => {
		if ( key === 'custom' ) {
			return ( value as CustomPackageResponse[] ).map(
				( v: CustomPackageResponse ) => camelCaseKeys( v )
			);
		}
		return value;
	} ) as {
		custom: CamelCaseType< CustomPackageResponse >[];
		predefined: Record< string, string[] >;
	};

export const getSelectedCarrierIdFromPackage = (
	availablePackages: AvailablePackages,
	packageId: string
) =>
	Object.entries( availablePackages ).find( ( [ , groups ] ) =>
		Object.values( groups )
			.map( ( group ) => group.definitions )
			.flat()
			.map( ( { id } ) => id )
			.includes( packageId )
	)?.[ 0 ];
