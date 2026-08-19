/**
 * Return Shipment Address Validation
 *
 * Enhanced validation utilities specifically for return shipment scenarios,
 * including address swapping validation, domestic shipment checks, and
 * return-specific address requirements.
 */

import { __ } from '@wordpress/i18n';
import {
	validateRequiredFields,
	validatePostalCode,
	validateCountryAndState,
	validateEmail,
	validatePhone,
	validateEmojiString,
} from './validators';
import { createLocalErrors, createValidationResult } from './validation';
import { AddressValidationInput, OriginAddress } from 'types';

export interface ReturnShipmentAddress extends OriginAddress {
	isVerified?: boolean;
	verificationStatus?: 'verified' | 'unverified' | 'failed' | 'pending';
	addressType?: 'origin' | 'destination';
}

export interface ReturnShipmentValidationContext {
	isReturn: boolean;
	isDomestic: boolean;
	originAddress: ReturnShipmentAddress;
	destinationAddress: ReturnShipmentAddress;
	operation?: 'create' | 'purchase' | 'validate';
	allowUnverifiedAddresses?: boolean;
}

export interface ReturnShipmentValidationResult {
	isValid: boolean;
	errors: string[];
	warnings: string[];
	addressErrors: {
		origin: Partial< Record< keyof ReturnShipmentAddress, string > >;
		destination: Partial< Record< keyof ReturnShipmentAddress, string > >;
	};
	canProceed: boolean;
	requiresConfirmation: boolean;
}

/**
 * Validates that return shipment addresses meet domestic-only requirements
 */
export const validateDomesticReturnShipment = (
	context: ReturnShipmentValidationContext
): ReturnShipmentValidationResult => {
	const errors: string[] = [];
	const warnings: string[] = [];
	const addressErrors: {
		origin: Partial< Record< keyof ReturnShipmentAddress, string > >;
		destination: Partial< Record< keyof ReturnShipmentAddress, string > >;
	} = { origin: {}, destination: {} };

	if ( ! context.isReturn ) {
		return {
			isValid: true,
			errors: [],
			warnings: [],
			addressErrors,
			canProceed: true,
			requiresConfirmation: false,
		};
	}

	// Check domestic-only requirement for returns
	const originCountry = context.originAddress.country;
	const destinationCountry = context.destinationAddress.country;

	if ( originCountry !== destinationCountry ) {
		errors.push(
			__(
				'Return labels are currently only available for domestic shipments (same country).',
				'woocommerce-shipping'
			)
		);
	}

	// US-specific validation for return shipments
	if ( originCountry === 'US' && destinationCountry === 'US' ) {
		// Both addresses should be in US
		context.isDomestic = true;
	} else if ( originCountry !== 'US' || destinationCountry !== 'US' ) {
		errors.push(
			__(
				'Return labels are currently only supported for US domestic shipments.',
				'woocommerce-shipping'
			)
		);
	}

	return {
		isValid: errors.length === 0,
		errors,
		warnings,
		addressErrors,
		canProceed: errors.length === 0,
		requiresConfirmation: false,
	};
};

/**
 * Validates return shipment address swapping is correct
 */
export const validateReturnAddressSwapping = (
	context: ReturnShipmentValidationContext
): ReturnShipmentValidationResult => {
	const errors: string[] = [];
	const warnings: string[] = [];
	const addressErrors: {
		origin: Partial< Record< keyof ReturnShipmentAddress, string > >;
		destination: Partial< Record< keyof ReturnShipmentAddress, string > >;
	} = { origin: {}, destination: {} };

	if ( ! context.isReturn ) {
		return {
			isValid: true,
			errors: [],
			warnings: [],
			addressErrors,
			canProceed: true,
			requiresConfirmation: false,
		};
	}

	// For return shipments, validate that addresses make sense
	// Origin (customer) should have residential characteristics
	// Destination (merchant) should be the business/return address

	// Check if origin address looks residential vs business
	const originLooksResidential =
		! context.originAddress.company ||
		context.originAddress.company.trim().length === 0;

	const destinationLooksBusiness =
		context.destinationAddress.company &&
		context.destinationAddress.company.trim().length > 0;

	if ( ! originLooksResidential && ! destinationLooksBusiness ) {
		warnings.push(
			__(
				'Return shipment addresses may be swapped. Please verify the customer is shipping from their address to your business address.',
				'woocommerce-shipping'
			)
		);
	}

	// Validate required fields for return addresses
	if ( ! context.originAddress.name && ! context.originAddress.company ) {
		addressErrors.origin.name = __(
			'Customer name or company is required for return shipments.',
			'woocommerce-shipping'
		);
		errors.push( addressErrors.origin.name );
	}

	if (
		! context.destinationAddress.name &&
		! context.destinationAddress.company
	) {
		addressErrors.destination.name = __(
			'Return-to business name or company is required.',
			'woocommerce-shipping'
		);
		errors.push( addressErrors.destination.name );
	}

	return {
		isValid: errors.length === 0,
		errors,
		warnings,
		addressErrors,
		canProceed: true, // Warnings don't prevent proceeding
		requiresConfirmation: warnings.length > 0,
	};
};

/**
 * Validates address verification status for return shipments
 */
export const validateReturnAddressVerification = (
	context: ReturnShipmentValidationContext
): ReturnShipmentValidationResult => {
	const errors: string[] = [];
	const warnings: string[] = [];
	const addressErrors: {
		origin: Partial< Record< keyof ReturnShipmentAddress, string > >;
		destination: Partial< Record< keyof ReturnShipmentAddress, string > >;
	} = { origin: {}, destination: {} };

	if ( ! context.isReturn ) {
		return {
			isValid: true,
			errors: [],
			warnings: [],
			addressErrors,
			canProceed: true,
			requiresConfirmation: false,
		};
	}

	// Check origin address verification
	if ( context.originAddress.verificationStatus === 'failed' ) {
		warnings.push(
			__(
				'Customer address could not be verified. This may cause delivery issues.',
				'woocommerce-shipping'
			)
		);
	} else if ( context.originAddress.verificationStatus === 'unverified' ) {
		if ( ! context.allowUnverifiedAddresses ) {
			warnings.push(
				__(
					'Customer address has not been verified. Consider verifying before creating return label.',
					'woocommerce-shipping'
				)
			);
		}
	}

	// Check destination address verification (return-to address)
	if ( context.destinationAddress.verificationStatus === 'failed' ) {
		errors.push(
			__(
				'Return-to address verification failed. Please update the address before proceeding.',
				'woocommerce-shipping'
			)
		);
		addressErrors.destination.address = __(
			'Address verification failed',
			'woocommerce-shipping'
		);
	} else if (
		context.destinationAddress.verificationStatus === 'unverified'
	) {
		if ( context.operation === 'purchase' ) {
			warnings.push(
				__(
					'Return-to address is not verified. This may cause delivery issues.',
					'woocommerce-shipping'
				)
			);
		}
	}

	return {
		isValid: errors.length === 0,
		errors,
		warnings,
		addressErrors,
		canProceed: errors.length === 0,
		requiresConfirmation: warnings.length > 0,
	};
};

/**
 * Validates complete return shipment address requirements
 */
export const validateReturnShipmentAddresses = (
	context: ReturnShipmentValidationContext
): ReturnShipmentValidationResult => {
	if ( ! context.isReturn ) {
		return {
			isValid: true,
			errors: [],
			warnings: [],
			addressErrors: { origin: {}, destination: {} },
			canProceed: true,
			requiresConfirmation: false,
		};
	}

	// Run all validation checks
	const domesticValidation = validateDomesticReturnShipment( context );
	const swappingValidation = validateReturnAddressSwapping( context );
	const verificationValidation = validateReturnAddressVerification( context );

	// Combine results
	const allErrors = [
		...domesticValidation.errors,
		...swappingValidation.errors,
		...verificationValidation.errors,
	];

	const allWarnings = [
		...domesticValidation.warnings,
		...swappingValidation.warnings,
		...verificationValidation.warnings,
	];

	const combinedAddressErrors = {
		origin: {
			...domesticValidation.addressErrors.origin,
			...swappingValidation.addressErrors.origin,
			...verificationValidation.addressErrors.origin,
		},
		destination: {
			...domesticValidation.addressErrors.destination,
			...swappingValidation.addressErrors.destination,
			...verificationValidation.addressErrors.destination,
		},
	};

	return {
		isValid: allErrors.length === 0,
		errors: allErrors,
		warnings: allWarnings,
		addressErrors: combinedAddressErrors,
		canProceed:
			domesticValidation.canProceed &&
			swappingValidation.canProceed &&
			verificationValidation.canProceed,
		requiresConfirmation:
			domesticValidation.requiresConfirmation ||
			swappingValidation.requiresConfirmation ||
			verificationValidation.requiresConfirmation,
	};
};

/**
 * Enhanced address field validation for return shipments
 */
export const validateReturnShipmentAddressFields = (
	address: ReturnShipmentAddress,
	addressType: 'origin' | 'destination',
	context: ReturnShipmentValidationContext
): AddressValidationInput => {
	// Start with base validation
	let validationInput: AddressValidationInput = {
		values: address,
		errors: {},
	};

	// Apply standard validators
	const validators = [
		validateRequiredFields( false ), // Returns are domestic, so no cross-border requirements
		validatePostalCode,
		validateCountryAndState,
		validateEmojiString,
	];

	// Apply email and phone validation based on address type
	if ( addressType === 'origin' && context.isReturn ) {
		// Customer address for return - may need contact info
		if ( address.email ) {
			validators.push( validateEmail );
		}
		if ( address.phone ) {
			validators.push( validatePhone );
		}
	} else if ( addressType === 'destination' && context.isReturn ) {
		// Business return address - should have contact info
		validators.push( validateEmail );
		validators.push( validatePhone );
	}

	// Apply all validators in sequence
	for ( const validator of validators ) {
		validationInput = validator( validationInput );
	}

	// Add return-specific validation
	const localErrors = createLocalErrors();

	if ( context.isReturn ) {
		// Return-specific validations
		if ( addressType === 'destination' ) {
			// Return-to address should be a business address
			if ( ! address.company && address.name?.includes( 'LLC' ) ) {
				localErrors.company = __(
					'Please enter the company name in the Company field.',
					'woocommerce-shipping'
				);
			}

			// Require email for return-to address
			if ( ! address.email ) {
				localErrors.email = __(
					'Email is required for the return-to address.',
					'woocommerce-shipping'
				);
			}

			// Require phone for return-to address
			if ( ! address.phone ) {
				localErrors.phone = __(
					'Phone number is required for the return-to address.',
					'woocommerce-shipping'
				);
			}
		}

		// Validate domestic requirement
		if ( address.country !== 'US' ) {
			localErrors.country = __(
				'Return shipments are currently only available for US addresses.',
				'woocommerce-shipping'
			);
		}
	}

	return createValidationResult(
		validationInput.values,
		validationInput.errors,
		localErrors
	);
};

/**
 * Quick validation check for return shipment feasibility
 */
export const canCreateReturnShipment = (
	originAddress: ReturnShipmentAddress,
	destinationAddress: ReturnShipmentAddress
): { canCreate: boolean; reason?: string } => {
	// Check basic requirements
	if ( ! originAddress || ! destinationAddress ) {
		return {
			canCreate: false,
			reason: __(
				'Both origin and destination addresses are required.',
				'woocommerce-shipping'
			),
		};
	}

	// Check domestic requirement
	if ( originAddress.country !== destinationAddress.country ) {
		return {
			canCreate: false,
			reason: __(
				'Return labels are only available for domestic shipments.',
				'woocommerce-shipping'
			),
		};
	}

	// Check US requirement
	if (
		originAddress.country !== 'US' ||
		destinationAddress.country !== 'US'
	) {
		return {
			canCreate: false,
			reason: __(
				'Return labels are currently only available for US shipments.',
				'woocommerce-shipping'
			),
		};
	}

	// Check minimum address requirements
	if (
		! originAddress.address ||
		! originAddress.city ||
		! originAddress.postcode
	) {
		return {
			canCreate: false,
			reason: __(
				'Origin address is incomplete.',
				'woocommerce-shipping'
			),
		};
	}

	if (
		! destinationAddress.address ||
		! destinationAddress.city ||
		! destinationAddress.postcode
	) {
		return {
			canCreate: false,
			reason: __(
				'Return-to address is incomplete.',
				'woocommerce-shipping'
			),
		};
	}

	return { canCreate: true };
};

/**
 * Get user-friendly error messages for return address validation
 */
export const getReturnAddressErrorMessage = (
	field: keyof ReturnShipmentAddress,
	addressType: 'origin' | 'destination'
): string => {
	const fieldMessages: {
		origin: Partial< Record< keyof ReturnShipmentAddress, string > >;
		destination: Partial< Record< keyof ReturnShipmentAddress, string > >;
	} = {
		origin: {
			name: __(
				'Customer name is required for return shipments.',
				'woocommerce-shipping'
			),
			company: __(
				'Customer company name (if applicable).',
				'woocommerce-shipping'
			),
			address: __(
				'Customer address is required for return pickup.',
				'woocommerce-shipping'
			),
			city: __( 'Customer city is required.', 'woocommerce-shipping' ),
			state: __( 'Customer state is required.', 'woocommerce-shipping' ),
			postcode: __(
				'Customer postal code is required.',
				'woocommerce-shipping'
			),
			country: __(
				'Return shipments require US addresses.',
				'woocommerce-shipping'
			),
			email: __(
				'Customer email may be required for delivery notifications.',
				'woocommerce-shipping'
			),
			phone: __(
				'Customer phone number may be required for delivery.',
				'woocommerce-shipping'
			),
		},
		destination: {
			name: __(
				'Business contact name is required for returns.',
				'woocommerce-shipping'
			),
			company: __(
				'Business name is required for return address.',
				'woocommerce-shipping'
			),
			address: __(
				'Return-to address is required.',
				'woocommerce-shipping'
			),
			city: __( 'Return-to city is required.', 'woocommerce-shipping' ),
			state: __( 'Return-to state is required.', 'woocommerce-shipping' ),
			postcode: __(
				'Return-to postal code is required.',
				'woocommerce-shipping'
			),
			country: __(
				'Return-to address must be in the US.',
				'woocommerce-shipping'
			),
			email: __(
				'Business email is required for return notifications.',
				'woocommerce-shipping'
			),
			phone: __(
				'Business phone number is required for returns.',
				'woocommerce-shipping'
			),
		},
	};

	return (
		fieldMessages[ addressType ][ field ] ??
		__(
			'This field is required for return shipments.',
			'woocommerce-shipping'
		)
	);
};
