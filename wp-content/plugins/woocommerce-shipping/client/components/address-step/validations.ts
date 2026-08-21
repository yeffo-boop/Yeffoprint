/**
 * Address Field Validators — Adapter for @wordpress/dataviews DataForm
 *
 * WHY THIS FILE EXISTS:
 *
 * The canonical address validation logic lives in `client/utils/validators.ts`.
 * Those validators use a pipeline pattern: each validator receives
 * `{ values, errors }` and returns `{ values, errors }`, accumulating errors
 * across all fields in a single pass. This pattern is used by the old-design
 * `<Form>` component and by `validateAddressSection` in `address_step.tsx`.
 *
 * The new-design `<DataForm>` component (from @wordpress/dataviews) uses a
 * different validation model: each field defines an `isValid.custom` function
 * that receives the full form item and returns `string | null` — a per-field
 * error message or null if valid.
 *
 * Rather than duplicating all the validation logic and error messages to match
 * DataForm's per-field signature, this file acts as a thin adapter layer:
 *
 *   1. `runPipelineValidation()` runs the EXACT same validator chain from
 *      `utils/validators.ts` (validateRequiredFields → validateCountryAndState
 *      → validateEmail → validatePostalCode → validateEmojiString →
 *      validatePhone/validateDestinationPhone).
 *
 *   2. `getFieldError()` runs the pipeline for a given item and extracts the
 *      error for a single field by key.
 *
 *   3. Each exported function (e.g. `validateAddress`, `validateCity`) simply
 *      delegates to `getFieldError` — zero duplicated validation logic.
 *
 * This ensures a SINGLE SOURCE OF TRUTH for all validation rules and error
 * messages. If a validation rule changes in `utils/validators.ts`, it
 * automatically applies to both the old-design Form and the new-design
 * DataForm without any additional changes here.
 *
 * EXCEPTIONS:
 *
 * - `validateName` adds a minimum-length check (3 chars) that the pipeline
 *   doesn't enforce, since the pipeline only checks "name or company required".
 *
 * PERFORMANCE NOTE:
 *
 * Each per-field validator runs the full pipeline, which means the pipeline
 * executes once per field per validation cycle. This is intentional — the
 * pipeline is lightweight (synchronous string checks) and the trade-off for
 * correctness and maintainability is worth it. DataForm's `useFormValidity`
 * hook also only re-validates fields whose values have changed, so in practice
 * only 1-2 fields are validated per keystroke.
 */

import { __ } from '@wordpress/i18n';
import { createLocalErrors, isMailAndPhoneRequired } from 'utils';
import {
	validateRequiredFields,
	validateCountryAndState,
	validateEmail,
	validatePostalCode,
	validateEmojiString,
	validatePhone as validatePhonePipeline,
	validateDestinationPhone,
} from 'utils/validators';
import { ADDRESS_TYPES } from 'data/constants';
import { CamelCaseType, LocationResponse, AddressTypes } from 'types';

type AddressItem = CamelCaseType< LocationResponse >;

/**
 * Runs the full validation pipeline from utils/validators.ts
 * and returns per-field errors as a flat record.
 */
const runPipelineValidation = (
	item: AddressItem,
	type: AddressTypes,
	originCountry?: string
): Record< string, string > => {
	const isCrossBorder = isMailAndPhoneRequired( {
		type,
		originCountry,
		destinationCountry: item.country,
	} );

	let result = validateRequiredFields( isCrossBorder )( {
		values: item,
		errors: createLocalErrors(),
	} );

	result = validateCountryAndState( result );
	result = validateEmail( result );
	result = validatePostalCode( result );
	result = validateEmojiString( result );

	if ( type === ADDRESS_TYPES.DESTINATION && originCountry ) {
		result = validateDestinationPhone( originCountry )( result );
	} else {
		result = validatePhonePipeline( result );
	}

	return result.errors as Record< string, string >;
};

/**
 * Returns the error for a specific field by running the shared pipeline.
 */
const getFieldError = (
	item: AddressItem,
	fieldId: string,
	type: AddressTypes,
	originCountry?: string
): string | null => {
	const errors = runPipelineValidation( item, type, originCountry );
	return errors[ fieldId ] || null;
};

export const validateName = (
	item: AddressItem,
	type: AddressTypes
): string | null => {
	// The pipeline only checks "name or company required" — it doesn't
	// enforce a minimum length. Check that first.
	if ( item.name && item.name.length < 3 ) {
		return __(
			'Name must be at least 3 characters long.',
			'woocommerce-shipping'
		);
	}
	return getFieldError( item, 'name', type );
};

export const validateCompany = ( item: AddressItem, type: AddressTypes ) =>
	getFieldError( item, 'company', type );

export const validateAddress = (
	item: AddressItem,
	type: AddressTypes,
	originCountry?: string
) => getFieldError( item, 'address', type, originCountry );

export const validateCity = (
	item: AddressItem,
	type: AddressTypes,
	originCountry?: string
) => getFieldError( item, 'city', type, originCountry );

export const validateState = (
	item: AddressItem,
	type: AddressTypes,
	originCountry?: string
) => getFieldError( item, 'state', type, originCountry );

export const validatePostcode = (
	item: AddressItem,
	type: AddressTypes,
	originCountry?: string
) => getFieldError( item, 'postcode', type, originCountry );

export const validateCountry = (
	item: AddressItem,
	type: AddressTypes,
	originCountry?: string
) => getFieldError( item, 'country', type, originCountry );

export const validateEmailField = (
	item: AddressItem,
	type: AddressTypes,
	originCountry?: string
) => getFieldError( item, 'email', type, originCountry );

export const validatePhone = (
	item: AddressItem,
	type: AddressTypes,
	originCountry?: string
) => getFieldError( item, 'phone', type, originCountry );
