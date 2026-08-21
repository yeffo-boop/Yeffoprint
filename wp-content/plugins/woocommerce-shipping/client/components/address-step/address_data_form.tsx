import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import {
	__experimentalSpacer as Spacer,
	Button,
	Flex,
	Notice,
} from '@wordpress/components';
import { __, _n } from '@wordpress/i18n';
import {
	DataForm,
	DeepPartial,
	Field,
	Form,
	FormField,
	FormValidity,
	useFormValidity,
} from '@wordpress/dataviews/wp';
import { AddressContextProvider } from './context';
import { getCountryNames, getStateNames, isMailAndPhoneRequired } from 'utils';
import { recordEvent } from 'utils/tracks';
import { ADDRESS_TYPES } from 'data/constants';
import {
	CamelCaseType,
	Destination,
	LocationResponse,
	AddressTypes,
} from 'types';
import {
	validateName,
	validateCompany,
	validateAddress,
	validateCity,
	validateState,
	validatePostcode,
	validateCountry,
	validateEmailField,
	validatePhone,
} from './validations';

/**
 * Check if all fields in a FormValidity object are valid.
 * Mirrors the logic from useFormValidity's internal isFormValid.
 */
function isFormValidCheck( formValidity: FormValidity | undefined ): boolean {
	if ( ! formValidity ) {
		return true;
	}
	return Object.values( formValidity ).every( ( fieldValidation ) =>
		Object.entries( fieldValidation ).every( ( [ key, validation ] ) => {
			if (
				key === 'children' &&
				validation &&
				typeof validation === 'object'
			) {
				return isFormValidCheck( validation as FormValidity );
			}
			return validation.type === 'valid';
		} )
	);
}

/**
 * Keeps a field's `custom` validity in sync with shared validators even when
 * another field changes (for example, country toggling destination phone/email
 * requiredness). Inserts/updates invalid custom state when a message exists,
 * and removes stale custom errors when the field is valid again.
 */
const upsertCustomFieldValidity = (
	formValidity: Record< string, FormValidityEntry >,
	fieldId: string,
	message: string | null
): Record< string, FormValidityEntry > => {
	const updated = { ...formValidity };
	const fieldValidity = updated[ fieldId ];

	if ( message ) {
		updated[ fieldId ] = {
			...( fieldValidity ?? {} ),
			custom: {
				type: 'invalid',
				message,
			},
		};
	} else if ( fieldValidity?.custom?.type === 'invalid' ) {
		const { custom: _custom, ...restFieldValidity } = fieldValidity;

		if ( Object.keys( restFieldValidity ).length === 0 ) {
			const { [ fieldId ]: _removed, ...rest } = updated;
			return rest;
		}

		updated[ fieldId ] = restFieldValidity;
	}

	return updated;
};

interface FormValidityCustom {
	type?: 'valid' | 'invalid';
	message?: string;
}

interface FormValidityEntry {
	custom?: FormValidityCustom;
	[ key: string ]: unknown;
}

interface AddressDataFormProps< T = Destination > {
	type: AddressTypes;
	initialValue: T;
	onSubmit: ( formData: T ) => Promise< void >;
	onCancel?: () => void;
	isUpdating: boolean;
	isVerified: boolean;
	validationErrors: Record< string, string >;
	originCountry?: string;
	onSaveWithoutValidation?: ( values: T ) => Promise< void >;
	surfaceArea?: string;
}

export function AddressDataForm<
	T extends CamelCaseType< LocationResponse >,
>( {
	type,
	initialValue,
	onSubmit,
	onCancel,
	isUpdating,
	validationErrors,
	originCountry,
	surfaceArea,
}: AddressDataFormProps< T > ) {
	// State for DataForm
	const [ formData, setFormData ] = useState< T >( initialValue );
	const formRef = useRef< HTMLFormElement >( null );

	// Get country and state options
	const countryNames = useMemo(
		() => getCountryNames( type, formData.country ),
		[ type, formData.country ]
	);
	const stateNames = useMemo(
		() => ( formData.country ? getStateNames( formData.country ) : [] ),
		[ formData.country ]
	);

	// Check if phone and email are required
	const isPhoneAndEmailRequired = useMemo(
		() =>
			isMailAndPhoneRequired( {
				type,
				originCountry,
				destinationCountry: formData.country,
			} ),
		[ type, originCountry, formData.country ]
	);

	const fields: Field< T >[] = useMemo(
		() => [
			{
				id: 'name',
				type: 'text',
				label:
					type === ADDRESS_TYPES.DESTINATION
						? __( 'Recipient Name', 'woocommerce-shipping' )
						: __( 'Name', 'woocommerce-shipping' ),
				isValid: {
					custom: ( item: T ) => validateName( item, type ),
				},
			},
			{
				id: 'company',
				type: 'text',
				label: __( 'Company', 'woocommerce-shipping' ),
				isVisible: () => type === ADDRESS_TYPES.ORIGIN,
				isValid: {
					custom: ( item: T ) => validateCompany( item, type ),
				},
			},
			{
				id: 'address',
				type: 'text',
				placeholder: __( 'Address', 'woocommerce-shipping' ),
				label:
					type === ADDRESS_TYPES.DESTINATION
						? __( 'Shipping Address', 'woocommerce-shipping' )
						: __( 'Address', 'woocommerce-shipping' ),
				isValid: {
					custom: ( item: T ) =>
						validateAddress( item, type, originCountry ),
				},
			},
			{
				id: 'address2',
				type: 'text',
				placeholder: __(
					'Apartment, suite, etc.',
					'woocommerce-shipping'
				),
				isVisible: () => type === ADDRESS_TYPES.DESTINATION,
				label: ' ', // Empty label to hide the label visually
			},
			{
				id: 'city',
				type: 'text',
				placeholder: __( 'City', 'woocommerce-shipping' ),
				isValid: {
					custom: ( item: T ) =>
						validateCity( item, type, originCountry ),
				},
			},
			{
				id: 'state',
				type: 'text',
				label: __( 'State', 'woocommerce-shipping' ),
				placeholder: __( 'State', 'woocommerce-shipping' ),
				elements: stateNames,
				isValid: {
					elements: false,
					custom: ( item: T ) =>
						validateState( item, type, originCountry ),
				},
			},
			{
				id: 'postcode',
				type: 'text',
				placeholder: __( 'Postal Code', 'woocommerce-shipping' ),
				isValid: {
					custom: ( item: T ) =>
						validatePostcode( item, type, originCountry ),
				},
			},
			{
				id: 'country',
				type: 'text',
				label: __( 'Country', 'woocommerce-shipping' ),
				elements: countryNames,
				isValid: {
					elements: false,
					custom: ( item: T ) =>
						validateCountry( item, type, originCountry ),
				},
			},
			{
				id: 'email',
				type: 'email',
				label: isPhoneAndEmailRequired
					? __( 'Email', 'woocommerce-shipping' )
					: __( 'Email (optional)', 'woocommerce-shipping' ),
				isValid: {
					custom: ( item: T ) =>
						validateEmailField( item, type, originCountry ),
				},
			},
			{
				id: 'phone',
				type: 'telephone',
				label: isPhoneAndEmailRequired
					? __( 'Phone', 'woocommerce-shipping' )
					: __( 'Phone (optional)', 'woocommerce-shipping' ),
				isValid: {
					custom: ( item: T ) =>
						validatePhone( item, type, originCountry ),
				},
			},
			// Add Save as default checkbox only for origin addresses
			{
				Edit: 'checkbox',
				id: 'defaultAddress',
				type: 'boolean',
				label: __(
					'Save as default sender address',
					'woocommerce-shipping'
				),
				isVisible: () => type === ADDRESS_TYPES.ORIGIN,
			},
			{
				Edit: 'checkbox',
				id: 'defaultReturnAddress',
				type: 'boolean',
				label: __(
					'Save as default return address',
					'woocommerce-shipping'
				),
				isVisible: () => type === ADDRESS_TYPES.ORIGIN,
			},
		],
		[
			countryNames,
			stateNames,
			isPhoneAndEmailRequired,
			type,
			originCountry,
		]
	);

	const form: Form = {
		fields: [
			'name',
			'company',
			'address',
			'address2',
			{
				id: 'cityStateRow',
				layout: {
					type: 'row',
					alignment: 'start',
				},
				children: [ 'city', 'state' ],
			} as FormField,
			{
				id: 'postcodeCountryRow',
				layout: {
					type: 'row',
					alignment: 'start',
				},
				children: [ 'postcode', 'country' ],
			} as FormField,
			'phone',
			'email',
			'defaultAddress',
			'defaultReturnAddress',
		].filter( Boolean ) as FormField[],
	};

	// useFormValidity processes all isValid rules (required, elements, custom, etc.)
	// and produces the validity object that DataForm uses to display inline errors.
	const { validity: rawValidity } = useFormValidity( formData, fields, form );

	// useFormValidity only re-validates fields whose own value changed,
	// so cross-field dependencies (name ↔ company) can leave stale errors.
	// Post-process to clear them when the sibling now satisfies the condition.
	const { validity, isValid: dataFormIsValid } = useMemo( () => {
		let adjusted: Record< string, FormValidityEntry > = {
			...( ( rawValidity ?? {} ) as Record< string, FormValidityEntry > ),
		};

		// Email/phone requiredness for destination depends on origin/destination country,
		// so enforce the shared validator result even when only country changes.
		adjusted = upsertCustomFieldValidity(
			adjusted,
			'email',
			validateEmailField( formData, type, originCountry )
		);
		adjusted = upsertCustomFieldValidity(
			adjusted,
			'phone',
			validatePhone( formData, type, originCountry )
		);

		if ( type === ADDRESS_TYPES.ORIGIN ) {
			const hasName = !! formData.name;
			const hasCompany = !! formData.company;

			// If either name or company is now provided, clear the
			// cross-field "required" error on the other field.
			if ( hasName && adjusted.company?.custom?.type === 'invalid' ) {
				const { company: _removed, ...rest } = adjusted;
				adjusted = rest;
			}

			if ( hasCompany && adjusted.name?.custom?.type === 'invalid' ) {
				const { name: _removed, ...rest } = adjusted;
				adjusted = rest;
			}
		}

		const adjustedValidity = Object.keys( adjusted ).length
			? ( adjusted as FormValidity )
			: undefined;

		return {
			validity: adjustedValidity,
			isValid: isFormValidCheck( adjustedValidity ),
		};
	}, [ rawValidity, formData, type, originCountry ] );

	/**
	 * Trigger inline validation on mount for pre-filled fields.
	 *
	 * When the form opens with existing address data (e.g. editing a saved
	 * address), any invalid values should surface errors right away rather
	 * than waiting for the user to interact with each field.
	 *
	 * By the time this effect runs, DataForm has already evaluated every
	 * field's isValid.custom validator and called setCustomValidity() on
	 * the underlying <input> elements. Calling checkValidity() on the
	 * <form> fires the native `invalid` event on every input whose custom
	 * validity message is non-empty, which ControlWithError listens for
	 * to render the inline error message.
	 */
	useEffect( () => {
		if (
			Object.values( initialValue ).some(
				( value ) => `${ value }`.length > 2
			)
		) {
			formRef.current?.checkValidity();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- Intentionally track current form node reference for mount-time validity checks.
	}, [ initialValue, formRef.current ] );

	const handleChange = useCallback(
		( value: DeepPartial< T > ) => {
			setFormData( ( prev ) => ( {
				...prev,
				...value,
			} ) );
		},
		[ setFormData ]
	);

	// Handle form submission
	const handleDataFormSubmit = async () => {
		if ( surfaceArea ) {
			recordEvent( `${ surfaceArea }_button_click`, {
				button_label: 'validate_and_save',
				card_section: 'sender_addresses',
			} );
		}
		if ( ! dataFormIsValid ) {
			// Trigger native invalid events on all inputs so that
			// ControlWithError shows the inline error messages.
			formRef.current?.checkValidity();
			return;
		}
		try {
			await onSubmit( formData );
		} catch ( error ) {
			if ( surfaceArea ) {
				const errorCode =
					error instanceof Error
						? error.message
						: ( error as Record< string, unknown > )?.code ??
						  'unknown_error';
				recordEvent( `${ surfaceArea }_button_error`, {
					button_label: 'validate_and_save',
					error_code: String( errorCode ),
					card_section: 'sender_addresses',
				} );
			}
		}
	};

	return (
		<form ref={ formRef } noValidate>
			<AddressContextProvider
				initialValue={ {
					isUpdating,
					validationErrors,
				} }
			>
				<Spacer marginBottom={ 6 }>
					<DataForm< T >
						data={ formData }
						fields={ fields }
						onChange={ handleChange }
						form={ form }
						validity={ validity }
					/>
				</Spacer>
			</AddressContextProvider>
			{ Object.keys( validationErrors ).length > 0 && ! isUpdating && (
				<>
					<Notice status="error" isDismissible={ false }>
						<strong>
							{ _n(
								'Please fix the error below to continue:',
								'Please fix the errors below to continue:',
								Object.keys( validationErrors ).length,
								'woocommerce-shipping'
							) }
						</strong>
						{ Object.keys( validationErrors ).length === 1 ? (
							<p style={ { margin: 0 } }>
								{ Object.values( validationErrors )[ 0 ] }
							</p>
						) : (
							<ul style={ { margin: 0, paddingLeft: '1em' } }>
								{ Object.values( validationErrors ).map(
									( message, index ) => (
										<li key={ index }>{ message }</li>
									)
								) }
							</ul>
						) }
					</Notice>
					<Spacer marginBottom={ 3 } />
				</>
			) }
			<Flex justify="flex-end" align={ 'center' } as="footer">
				<Button
					type="button"
					onClick={ () => {
						if ( surfaceArea ) {
							recordEvent( `${ surfaceArea }_button_click`, {
								button_label: 'cancel',
								card_section: 'sender_addresses',
							} );
						}
						onCancel?.();
					} }
					isBusy={ isUpdating }
					variant="tertiary"
				>
					{ __( 'Cancel', 'woocommerce-shipping' ) }
				</Button>
				<Button
					type="button"
					variant="primary"
					disabled={ isUpdating || ! dataFormIsValid }
					isBusy={ isUpdating }
					onClick={ handleDataFormSubmit }
				>
					{ __( 'Validate and save', 'woocommerce-shipping' ) }
				</Button>
			</Flex>
		</form>
	);
}
