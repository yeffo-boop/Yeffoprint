import { __, sprintf } from '@wordpress/i18n';
import type { FormErrors } from '@woocommerce/components';
import { isEmpty, isNil } from 'lodash';
import { CustomsItem, CustomsValidationInput, Destination } from 'types';
import {
	isCountryInEU,
	isHSTariffNumberValid,
	hasInvalidChar,
	isUSPSDomesticMailShipment,
	USPS_ITN_REQUIRED_DESTINATIONS,
} from 'utils';
import { getCurrentOrderItems } from 'utils/order';
import { itnMatchingRegex, MAX_ITEM_DESCRIPTION_LENGTH } from './constants';

export const createLocalErrors = (
	items: CustomsItem[]
): CustomsValidationInput[ 'errors' ] => ( {
	items: Array( items.length )
		.fill( 0 )
		.map( () => ( {} ) as FormErrors< CustomsItem > ),
} );

/**
 * Normalize the ITN to a consistent format, be sure to run through the itnMatchingRegex first.
 *
 * @param {string} itn - The ITN to normalize.
 * @return {string} The normalized ITN.
 */
export const normalizeITN = ( itn: string ): string => {
	// Trim whitespace and convert to uppercase for standardization
	const cleanITN = itn
		.trim()
		.toUpperCase()
		.replace( /^(?:AES\s*ITN:?\s*)?(?:AES\s*)?/, '' );

	// Check if the ITN is a 14-digit number, optionally prefixed with 'X'
	if ( /^X?\d{14}$/.test( cleanITN.replace( /\s+/g, '' ) ) ) {
		// Extract digits and format as 'AES X{14 digits}'
		const digits = cleanITN.replace( /[^0-9]/g, '' );
		return `AES X${ digits }`;
	}

	// If the ITN starts with 'NOEEI', normalize spacing and lowercase text within brackets
	if ( cleanITN.startsWith( 'NOEEI' ) ) {
		// Convert text within brackets to lowercase
		return cleanITN
			.replace( /\s+/g, ' ' )
			.replace(
				/\(([^)]+)\)/g,
				( match, p1 ) => `(${ p1.toLowerCase() })`
			);
	}

	// Return the cleaned ITN if no specific format is matched
	return cleanITN;
};

export const validateContentTypes = ( {
	values: { contentsType, contentsExplanation, items, ...rest },
	errors,
}: CustomsValidationInput ): CustomsValidationInput => {
	const localErrors = createLocalErrors( items );
	if ( contentsType === 'other' ) {
		if ( ! contentsExplanation || contentsExplanation.length < 3 ) {
			localErrors.contentsExplanation = __(
				'Please describe what kind of goods this package contains.',
				'woocommerce-shipping'
			);
		}
	}

	return {
		errors: {
			...errors,
			...localErrors,
		},
		values: { contentsType, contentsExplanation, items, ...rest },
	};
};

export const validateRestrictionType = ( {
	values: { restrictionType, restrictionComments, items, ...rest },
	errors,
}: CustomsValidationInput ): CustomsValidationInput => {
	const localErrors = createLocalErrors( items );
	if ( restrictionType === 'other' ) {
		if ( ! restrictionComments || restrictionComments.length < 3 ) {
			localErrors.restrictionComments = __(
				'Please describe what kind of restrictions this package must have.',
				'woocommerce-shipping'
			);
		}
	}

	return {
		errors: {
			...errors,
			...localErrors,
		},
		values: { restrictionType, restrictionComments, items, ...rest },
	};
};

export const calculateTotalPrice = (
	orderItems: { total: string }[]
): number => {
	return orderItems.reduce(
		( accu: number, curr: { total: string } ) =>
			accu + parseFloat( curr.total ),
		0
	);
};

export const calculateValuesByProductId = (
	items: { product_id: number; price: string; quantity: number }[]
): Record< number, number > => {
	return items.reduce(
		( acc: Record< number, number >, { product_id, price, quantity } ) => {
			acc[ product_id ] = parseFloat( `${ price }` ) * quantity;
			return acc;
		},
		{}
	);
};

export const calculateValuesByTariffNumber = (
	items: { product_id: number; hsTariffNumber: string }[],
	valuesByProductId: Record< number, number >
): Record< string, number > => {
	return items.reduce(
		( acc: Record< string, number >, { product_id, hsTariffNumber } ) => {
			if ( hsTariffNumber?.length === 6 ) {
				if ( ! acc[ hsTariffNumber ] ) {
					acc[ hsTariffNumber ] = 0;
				}
				acc[ hsTariffNumber ] += valuesByProductId[ product_id ];
			}
			return acc;
		},
		{}
	);
};

export const findClassesAbove2500usd = (
	items: { product_id: number; hsTariffNumber: string }[],
	valuesByTariffNumber: Record< string, number >
): Set< string > => {
	return items.reduce( ( acc: Set< string >, { hsTariffNumber } ) => {
		if (
			hsTariffNumber !== '' &&
			valuesByTariffNumber[ hsTariffNumber ] > 2500
		) {
			acc.add( hsTariffNumber );
		}
		return acc;
	}, new Set< string >() );
};

export const validateITN =
	( {
		country,
		countryName,
	}: {
		country: Destination[ 'country' ];
		countryName: string;
	} ) =>
	( {
		values: { itn, items, contentsType, ...rest },
		errors,
	}: CustomsValidationInput ): CustomsValidationInput => {
		const localErrors = createLocalErrors( items );

		// Document mailings carry no itemized customs declaration, so the
		// value/tariff-based ITN requirements don't apply. A manually
		// entered ITN is still format-checked below.
		const isDocuments = contentsType === 'documents';

		if ( ! isDocuments ) {
			const orderItems = getCurrentOrderItems();
			const totalPrice = calculateTotalPrice( orderItems );

			if ( ! itn && totalPrice > 2500 ) {
				localErrors.itn = __(
					'For shipments exceeding $2,500, obtaining a 14-digit AES ITN is required for U.S. export reporting.',
					'woocommerce-shipping'
				);
			}
		}

		if ( itn && itn.length > 0 ) {
			if ( ! itnMatchingRegex.test( itn.trim() ) ) {
				localErrors.itn = __(
					'Please enter a valid ITN in one of these formats: X12345678901234, AES X12345678901234, or NOEEI 30.37(a)',
					'woocommerce-shipping'
				);
			}
		} else if ( ! isDocuments && country !== 'CA' ) {
			const valuesByProductId = calculateValuesByProductId( items );
			const valuesByTariffNumber = calculateValuesByTariffNumber(
				items,
				valuesByProductId
			);
			const classesAbove2500usd = findClassesAbove2500usd(
				items,
				valuesByTariffNumber
			);

			if ( ! isEmpty( classesAbove2500usd ) ) {
				localErrors.itn = sprintf(
					// translators: %s is the tariff number
					__(
						'International Transaction Number is required for shipping items valued over $2,500 per tariff number. Products with tariff number %s add up to more than $2,500.',
						'woocommerce-shipping'
					),
					classesAbove2500usd.values().next().value ?? ''
				);
			} else if ( USPS_ITN_REQUIRED_DESTINATIONS.includes( country ) ) {
				localErrors.itn = sprintf(
					// translators: %s is the country name
					__(
						'International Transaction Number is required for shipments to %s',
						'woocommerce-shipping'
					),
					countryName
				);
			}
		}

		return {
			errors: {
				...errors,
				...localErrors,
			},
			values: {
				...rest,
				items,
				contentsType,
				// Normalize the ITN before sending to the API
				itn: itn ? normalizeITN( itn ) : itn,
			},
		};
	};

export const validateItems =
	( {
		country,
		originCountry,
	}: Pick< Destination, 'country' > & { originCountry?: string } ) =>
	( {
		values: { items, contentsType, ...rest },
		errors,
	}: CustomsValidationInput ): CustomsValidationInput => {
		const localErrors = createLocalErrors( items );

		// Document mailings carry no itemized customs declaration — the label
		// omits the customs items entirely — so the per-item description/value/
		// weight fields are hidden and must not block purchase.
		if ( contentsType === 'documents' ) {
			return {
				errors: {
					...errors,
					...localErrors,
				},
				values: { items, contentsType, ...rest },
			};
		}

		items.forEach(
			( { description, weight, hsTariffNumber, price }, index ) => {
				if ( ! description ) {
					localErrors.items[ index ].description = __(
						'This field is required',
						'woocommerce-shipping'
					);
				} else if ( hasInvalidChar( description ) ) {
					localErrors.items[ index ].description = __(
						'This field contains invalid characters.',
						'woocommerce-shipping'
					);
				} else if (
					isUSPSDomesticMailShipment( originCountry, country ) &&
					description.length > MAX_ITEM_DESCRIPTION_LENGTH
				) {
					localErrors.items[ index ].description = sprintf(
						// translators: %d is the maximum number of characters allowed in a customs item description.
						__(
							'Description must be %d characters or fewer.',
							'woocommerce-shipping'
						),
						MAX_ITEM_DESCRIPTION_LENGTH
					);
				}

				if ( isNil( weight ) || weight === '' ) {
					localErrors.items[ index ].weight = __(
						'This field is required',
						'woocommerce-shipping'
					);
				} else if ( ! ( parseFloat( weight ) > 0 ) ) {
					localErrors.items[ index ].weight = __(
						'Weight must be greater than zero',
						'woocommerce-shipping'
					);
				}
				if ( isNil( price ) || price === '' ) {
					localErrors.items[ index ].price = __(
						'This field is required',
						'woocommerce-shipping'
					);
				} else if ( ! ( parseFloat( price ) > 0 ) ) {
					localErrors.items[ index ].price = __(
						'Declared value must be greater than zero',
						'woocommerce-shipping'
					);
				}

				const shouldValidateHSTariffNumber = isCountryInEU( country )
					? true
					: Boolean( hsTariffNumber );
				if (
					shouldValidateHSTariffNumber &&
					! isHSTariffNumberValid( hsTariffNumber ) &&
					localErrors?.items?.[ index ]
				) {
					localErrors.items[ index ].hsTariffNumber = __(
						'The tariff number must be between 6 and 12 digits long',
						'woocommerce-shipping'
					);
				}
				return errors;
			}
		);

		return {
			errors: {
				...errors,
				...localErrors,
			},
			values: { items, contentsType, ...rest },
		};
	};
