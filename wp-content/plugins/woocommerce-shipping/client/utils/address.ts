import { Destination, OriginAddress } from 'types';

/**
 * Returns true if there are no origin addresses or every address is unverified.
 *
 * @param originAddresses - Array of origin addresses (e.g. from getOriginAddresses())
 * @return true when no addresses exist or all have isVerified falsy
 */
export const areAllOriginsUnverified = (
	originAddresses: OriginAddress[] | undefined | null
): boolean =>
	! originAddresses?.length ||
	originAddresses.every(
		( address ) =>
			address.isApproved === false ||
			( ( address.isApproved === undefined ||
				address.isApproved === null ) &&
				! address.isVerified )
	);

/**
 * Map of common address abbreviations to their full names.
 * We only use this to ensure that leveinstein distance is not affected by abbreviations.
 */
export const addresAbbreviationMap: Record< string, string > = {
	STREET: 'ST',
	AVENUE: 'AVE',
	BOULEVARD: 'BLVD',
	ROAD: 'RD',
	DRIVE: 'DR',
	LANE: 'LN',
	COURT: 'CT',
	PARKWAY: 'PKWY',
	PLACE: 'PL',
	TERRACE: 'TER',
	CIRCLE: 'CIR',
	HIGHWAY: 'HWY',
	MOUNT: 'MT',
	MOUNTAIN: 'MTN',
	SQUARE: 'SQ',
	SUITE: 'STE',
	BUILDING: 'BLDG',
	FLOOR: 'FL',
	ROOM: 'RM',
	APARTMENT: 'APT',
	UNIT: 'UNIT',
	HARBOR: 'HBR',
	ISLAND: 'IS',
	CREEK: 'CRK',
	HEIGHTS: 'HTS',
	SPRING: 'SPG',
	VALLEY: 'VLY',
	CROSSING: 'XING',
	NORTH: 'N',
	SOUTH: 'S',
	EAST: 'E',
	WEST: 'W',
	// Add other common abbreviations as needed
};

/**
 * Normalise an address by removing periods, commas, extra spaces, and converting to uppercase.
 *
 * @param address The address to normalise
 *
 * @return The normalised address
 */
export const normaliseAddress = ( address: string ): string => {
	let normalised = address
		.toUpperCase() // Convert to uppercase
		.replace( /\./g, '' ) // Remove periods
		.replace( /,/g, '' ) // Remove commas
		.replace( /\s+/g, ' ' ); // Remove extra spaces
	// Replace full words with abbreviations.
	Object.keys( addresAbbreviationMap ).forEach( ( abbr ) => {
		const regex = new RegExp( `\\b${ abbr }\\b`, 'g' ); // Match the abbreviation as a whole word
		normalised = normalised.replace( regex, addresAbbreviationMap[ abbr ] );
	} );

	return normalised;
};

/**
 * Compare two addresses and return the Levenshtein distance between them to determine similarity.
 *
 * @param address1 The first address to compare
 * @param address2 The second address to compare
 *
 * @return number
 */
export const levenshteinDistance = (
	address1: string,
	address2: string
): number => {
	const matrix: number[][] = [];

	// Increment along the first column of each row
	for ( let i = 0; i <= address2.length; i++ ) {
		matrix[ i ] = [ i ];
	}

	// Increment each column in the first row
	for ( let j = 0; j <= address1.length; j++ ) {
		matrix[ 0 ][ j ] = j;
	}

	// Fill the matrix
	for ( let i = 1; i <= address2.length; i++ ) {
		for ( let j = 1; j <= address1.length; j++ ) {
			if ( address2.charAt( i - 1 ) === address1.charAt( j - 1 ) ) {
				matrix[ i ][ j ] = matrix[ i - 1 ][ j - 1 ];
			} else {
				matrix[ i ][ j ] = Math.min(
					matrix[ i - 1 ][ j - 1 ] + 1,
					Math.min(
						matrix[ i ][ j - 1 ] + 1,
						matrix[ i - 1 ][ j ] + 1
					)
				);
			}
		}
	}

	return matrix[ address2.length ][ address1.length ];
};

/**
 * Compare two addresses to determine if they are similar.
 * @param address1 The first address to compare
 * @param address2 The second address to compare
 * @return boolean True if the addresses are similar, false otherwise
 */
export const areAddressesClose = (
	address1: Destination,
	address2: Destination
): boolean => {
	const normStreet1Address1 = address1.address1
		? normaliseAddress( address1.address1 )
		: '';
	const normStreet1Address2 = address2.address1
		? normaliseAddress( address2.address1 )
		: '';
	const normStreet2Address1 = address1.address2
		? normaliseAddress( address1.address2 )
		: '';
	const normStreet2Address2 = address2.address2
		? normaliseAddress( address2.address2 )
		: '';
	const normCityAddress1 = address1.city?.toUpperCase() ?? '';
	const normCityAddress2 = address2.city?.toUpperCase() ?? '';
	const normStateAddress1 = address1.state?.toUpperCase() ?? '';
	const normStateAddress2 = address2.state?.toUpperCase() ?? '';
	// Extract the base 5 digits of the ZIP code to account for ZIP+4 codes
	const normPostalCodeAddress1 = address1.postcode.slice( 0, 5 ); // First 5 digits
	const normPostalCodeAddress2 = address2.postcode.slice( 0, 5 ); // First 5 digits
	const normCountryAddress1 = address1.country.toUpperCase();
	const normCountryAddress2 = address2.country.toUpperCase();

	// Set thresholds (adjust as necessary)
	const STREET_THRESHOLD = 5; // Maximum number of character changes allowed for street

	// Compare each aspect of the addresses
	const isStreetSimilar =
		levenshteinDistance( normStreet1Address1, normStreet1Address2 ) <=
		STREET_THRESHOLD;
	const isStreet2Similar =
		levenshteinDistance( normStreet2Address1, normStreet2Address2 ) <=
		STREET_THRESHOLD;
	const isCitySimilar = normCityAddress1 === normCityAddress2;
	const isStateSimilar = normStateAddress1 === normStateAddress2;
	const isPostalCodeSimilar =
		normPostalCodeAddress1 === normPostalCodeAddress2; // Compare only the first 5 digits
	const isCountrySimilar = normCountryAddress1 === normCountryAddress2;

	// Return true if all key address parts are considered "similar"
	return (
		isStreetSimilar &&
		isStreet2Similar &&
		isCitySimilar &&
		isStateSimilar &&
		isPostalCodeSimilar &&
		isCountrySimilar
	);
};

/**
 * Check if only checkbox fields (defaultAddress, defaultReturnAddress) have changed
 * and no actual address data fields have been modified.
 *
 * @param originalAddress The original address object
 * @param newAddress      The new address object with potentially changed values
 * @return boolean True if only checkbox fields changed, false otherwise
 */
export const hasOnlyCheckboxChanges = < T extends Record< string, unknown > >(
	originalAddress: T,
	newAddress: T
): boolean => {
	const addressFields = [
		'name',
		'company',
		'address',
		'city',
		'state',
		'postcode',
		'country',
		'email',
		'phone',
		'firstName',
		'lastName',
	];

	// Check if any actual address fields have changed
	const hasAddressFieldChanges = addressFields.some( ( field ) => {
		const originalValue = originalAddress[ field as keyof T ];
		const newValue = newAddress[ field as keyof T ];

		// Type-agnostic comparison to handle cases like 1 vs '1'
		// eslint-disable-next-line eqeqeq
		return originalValue != newValue;
	} );

	return ! hasAddressFieldChanges;
};
