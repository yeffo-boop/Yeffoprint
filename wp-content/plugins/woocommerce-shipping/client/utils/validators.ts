import { __ } from '@wordpress/i18n';
import {
	ACCEPTED_USPS_ORIGIN_COUNTRIES,
	createLocalErrors,
	createValidationResult,
	hasStates,
} from 'utils';
import {
	AddressValidationInput,
	CamelCaseType,
	LocationResponse,
	OriginAddress,
} from 'types';
import { isEmail } from '@wordpress/url';

/**
 * Validates required fields for an address, but leaves email and phone validation to other validators.
 */

export const validateRequiredFields =
	( isCrossBorderShipment: boolean ) =>
	< T extends CamelCaseType< LocationResponse > >( {
		values,
		errors,
	}: AddressValidationInput< T > ): AddressValidationInput< T > => {
		const localErrors = createLocalErrors();

		const errorMessages: Partial< {
			[ K in keyof AddressValidationInput< T >[ 'errors' ] ]: string;
		} > = {
			address: __(
				'Please provide a valid address.',
				'woocommerce-shipping'
			),
			city: __( 'Please provide a valid city.', 'woocommerce-shipping' ),
			postcode: __(
				'Please provide a valid postal code.',
				'woocommerce-shipping'
			),
			country: __(
				'Please provide a valid country.',
				'woocommerce-shipping'
			),
		};

		if ( isCrossBorderShipment ) {
			errorMessages.email = __(
				"Email address can't be empty for this address.",
				'woocommerce-shipping'
			);

			errorMessages.phone = __(
				"Phone number can't be empty for this address.",
				'woocommerce-shipping'
			);
		}

		Object.entries( values ).forEach( ( [ key, value ] ) => {
			const errorMessage = errorMessages[ key as keyof OriginAddress ];
			if ( ! value && errorMessage ) {
				localErrors[ key as keyof OriginAddress ] = errorMessage;
			}
		} );

		// Either name or company is required.
		if ( ! values.name && ! values.company ) {
			localErrors.name = localErrors.company = __(
				'Please provide a valid name or company name.',
				'woocommerce-shipping'
			);
		}

		return createValidationResult( values, errors, localErrors );
	};

export const validatePostalCode = ( {
	values,
	errors,
}: AddressValidationInput ) => {
	const localErrors = createLocalErrors();

	const { postcode, country } = values;
	if (
		ACCEPTED_USPS_ORIGIN_COUNTRIES.includes( country ) &&
		! /^\d{5}(?:-\d{4})?$/.test( postcode )
	) {
		localErrors.postcode = __(
			'Please provide a valid postal code.',
			'woocommerce-shipping'
		);
	}
	return createValidationResult( values, errors, localErrors );
};

export const validateCountryAndState = ( {
	values,
	errors,
}: AddressValidationInput ) => {
	const localErrors = createLocalErrors();

	const { country, state } = values;
	if ( ! state && hasStates( country ) ) {
		localErrors.state = __(
			'Please provide a valid state.',
			'woocommerce-shipping'
		);
	}

	return createValidationResult( values, errors, localErrors );
};

/**
 * Validates phone number format with enhanced security and format checking
 * @param param0        Validation input with values and errors
 * @param param0.values Address values containing phone number
 * @param param0.errors Current validation errors
 * @return Updated validation result
 */
export const validatePhone = ( {
	values,
	errors,
}: AddressValidationInput ): AddressValidationInput => {
	const localErrors = createLocalErrors();

	const { phone, country } = values;

	if ( ! phone ) {
		localErrors.phone = __(
			'Please provide a valid phone number.',
			'woocommerce-shipping'
		);
	} else {
		// Input sanitization: trim whitespace and validate input
		const trimmedPhone = phone.trim();

		// Security enhancement: basic format check to prevent injection
		if ( trimmedPhone.length > 50 ) {
			localErrors.phone = __(
				'Please provide a valid phone number.',
				'woocommerce-shipping'
			);
			return createValidationResult( values, errors, localErrors );
		}

		// Only allow digits, spaces, dashes, parentheses, dots, and plus sign
		const allowedPattern = /^[\d\s\-\(\)\.\+]+$/;
		if ( ! allowedPattern.test( trimmedPhone ) ) {
			localErrors.phone = __(
				'Please provide a valid phone number.',
				'woocommerce-shipping'
			);
			return createValidationResult( values, errors, localErrors );
		}

		// Extract only digits from the phone number
		const digitsOnly = trimmedPhone.replace( /\D/g, '' );

		// Check if we have exactly 10 digits, or 11 digits starting with 1 (US country code)
		let isValid =
			digitsOnly.length === 10 ||
			( digitsOnly.length === 11 && digitsOnly.startsWith( '1' ) );

		// Check if we have at least 7 digits for other countries
		if ( country.toUpperCase() !== 'US' ) {
			isValid = digitsOnly.length >= 7;
		}

		if ( ! isValid ) {
			localErrors.phone = __(
				'Please provide a valid phone number.',
				'woocommerce-shipping'
			);
		}
	}

	return createValidationResult( values, errors, localErrors );
};

export const validateDestinationPhone =
	( originCountry: string ) =>
	( { values, errors }: AddressValidationInput ) => {
		const localErrors = createLocalErrors();
		const { phone, country } = values;
		const isCrossBorderDestination = originCountry !== country;

		if ( ! phone ) {
			if ( isCrossBorderDestination ) {
				localErrors.phone = __(
					"Phone number can't be empty for this address.",
					'woocommerce-shipping'
				);
			}

			return createValidationResult( values, errors, localErrors );
		}

		// Phone is optional for domestic destination shipments.
		// If present, apply standard format validation rules.
		return validatePhone( { values, errors } );
	};

export const validateEmail = ( {
	values,
	errors,
}: AddressValidationInput ): AddressValidationInput => {
	const localErrors = createLocalErrors();
	if ( values.email && ! isEmail( values.email ) ) {
		localErrors.email = __(
			'Please, enter a valid email address.',
			'woocommerce-shipping'
		);
	}

	return createValidationResult( values, errors, localErrors );
};

export const hasInvalidChar = ( text: string ) => {
	// Convert to string to ensure we're working with a string.
	const textStr = String( text );

	// Array of regex patterns to test.
	const patterns = [
		/:([^\s:]+):/gi, // Emoji pattern like :smile:.
		/\p{Extended_Pictographic}/gu, // Unicode emojis.
	];

	// Loop through each pattern and return true if any match is found.
	for ( const pattern of patterns ) {
		if ( pattern.test( textStr ) ) {
			return true;
		}
	}

	// Return false if no pattern matches.
	return false;
};

export const validateEmojiString = ( {
	values,
	errors,
}: AddressValidationInput ): AddressValidationInput => {
	const localErrors = createLocalErrors();

	const errorMessages: Partial< {
		[ K in keyof AddressValidationInput[ 'errors' ] ]: string;
	} > = {
		address: __(
			'Address contains invalid characters.',
			'woocommerce-shipping'
		),
		city: __( 'City contains invalid characters.', 'woocommerce-shipping' ),
		postcode: __(
			'Postal code contains invalid characters.',
			'woocommerce-shipping'
		),
		state: __(
			'State contains invalid characters.',
			'woocommerce-shipping'
		),
	};

	Object.entries( values ).forEach( ( [ key, value ] ) => {
		const errorMessage =
			errorMessages[ key as keyof AddressValidationInput[ 'errors' ] ];
		if ( hasInvalidChar( value ) && errorMessage ) {
			localErrors[ key as keyof AddressValidationInput[ 'errors' ] ] =
				errorMessage;
		}
	} );

	return createValidationResult( values, errors, localErrors );
};
