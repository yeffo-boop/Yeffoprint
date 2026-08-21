import { AddressTypes, AddressValidationInput, OriginAddress } from 'types';

export const createLocalErrors =
	(): AddressValidationInput[ 'errors' ] => ( {} );

export const createValidationResult = < T = OriginAddress >(
	values: T,
	errors: AddressValidationInput[ 'errors' ],
	localErrors: AddressValidationInput[ 'errors' ]
): AddressValidationInput< T > => ( {
	errors: {
		...errors,
		...localErrors,
	},
	values,
} );

export const isMailAndPhoneRequired = ( {
	type,
	originCountry,
	destinationCountry,
}: {
	type: AddressTypes;
	originCountry?: string;
	destinationCountry: string;
} ) => {
	if ( type === 'origin' ) {
		return true;
	}

	if ( type === 'destination' && originCountry ) {
		return originCountry !== destinationCountry;
	}

	return false;
};
