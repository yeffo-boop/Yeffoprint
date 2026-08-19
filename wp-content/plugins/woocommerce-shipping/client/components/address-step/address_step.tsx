import React from 'react';
import { isEmpty } from 'lodash';

import { Button, Flex, FlexItem, Modal } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { dispatch, useSelect } from '@wordpress/data';
import { Form } from '@woocommerce/components';

import { AddressFields } from './fields';
import { AddressSuggestion } from './suggestion';
import { AddressVerifiedIcon } from '../address-verified-icon';
import {
	createLocalErrors,
	isMailAndPhoneRequired,
	validateCountryAndState,
	validateDestinationPhone,
	validateEmail,
	validatePhone,
	validatePostalCode,
	validateRequiredFields,
	validateEmojiString,
} from 'utils';
import { hasOnlyCheckboxChanges } from 'utils/address';
import { AddressContextProvider } from './context';
import { ADDRESS_TYPES } from 'data/constants';
import {
	AddressTypes,
	CamelCaseType,
	Destination,
	LocationResponse,
	OriginAddress,
} from 'types';
import { addressStore } from 'data/address';
import { withBoundary } from 'components/HOC';
import { AddressDataForm } from './address_data_form';

interface AddressStepProps< T = Destination > {
	type: AddressTypes;
	address: T;
	onCompleteCallback: ( address: T ) => void;
	onCancelCallback?: () => void;
	orderId?: string; // order id is only needed when dealing with destination address
	isAdd: boolean; // if the form is used to add an address
	originCountry?: string; // origin country is only needed for destination address for validations
	nextDesign?: boolean; // whether to use the next design for the address step
	surfaceArea?: string; // telemetry surface area identifier for CIAB tracking
	clearStoreAddressDriftOnSave?: boolean;
}

export const AddressStep = withBoundary(
	< T extends CamelCaseType< LocationResponse > >( {
		type,
		address,
		onCompleteCallback,
		onCancelCallback,
		isAdd = false,
		orderId,
		originCountry,
		nextDesign = false,
		surfaceArea,
		clearStoreAddressDriftOnSave = false,
	}: AddressStepProps< T > ) => {
		const [ isSuggestionModalOpen, setIsSuggestionModalOpen ] =
			useState( false );
		const [ isUpdating, setIsUpdating ] = useState( false );
		const [ isConfirming, setIsConfirming ] = useState( false );
		const [ isComplete, setIsComplete ] = useState( false );
		const [ warningMessage, setWarningMessage ] = useState(
			__( 'Unvalidated address', 'woocommerce-shipping' )
		);

		const [ showBypassConfirmation, setShowBypassConfirmation ] =
			useState( false );
		const [ pendingBypassValues, setPendingBypassValues ] =
			useState< T | null >( null );

		// Assert that order id is provided for destination address
		if ( type === ADDRESS_TYPES.DESTINATION && ! orderId ) {
			throw new Error( 'Order id is required for destination address' );
		}

		if ( isAdd && type === ADDRESS_TYPES.DESTINATION ) {
			throw new Error( 'Destination address cannot be added' );
		}

		const validateAddressSection = ( values: T ) => {
			const validatables = [
				{
					values,
					errors: createLocalErrors(),
					type,
				},
			]
				.map( ( validatable ) =>
					validateRequiredFields(
						isMailAndPhoneRequired( {
							type,
							originCountry,
							destinationCountry: validatable.values.country,
						} )
					)< T >( validatable )
				)
				.map( validateCountryAndState )
				.map( validateEmail )
				.map( validatePostalCode )
				.map( validateEmojiString )
				.map( ( validatable ) => {
					if ( type === ADDRESS_TYPES.DESTINATION && originCountry ) {
						return validateDestinationPhone( originCountry )(
							validatable
						);
					}
					return validatePhone( validatable );
				} );

			return validatables[ 0 ].errors;
		};

		const validationErrors = useSelect(
			( select ) => select( addressStore ).getFormErrors( type ),
			[ type ]
		);
		const submittedAddress = useSelect(
			( select ) => select( addressStore ).getSubmittedAddress( type ),
			[ type ]
		);
		const isVerified = useSelect(
			( select ) => select( addressStore ).getIsAddressVerified( type ),
			[ type ]
		);
		const normalizedAddress = useSelect(
			( select ) => select( addressStore ).getNormalizedAddress( type ),
			[ type ]
		);

		const needsConfirmation = useSelect(
			( select ) =>
				select( addressStore ).getAddressNeedsConfirmation( type ),
			[ type ]
		);
		const warnings = useSelect(
			( select ) =>
				select( addressStore ).getAddressVerifcationWarnings(
					ADDRESS_TYPES.DESTINATION
				),
			[]
		);

		const normalizeAddress = async ( values: T ) => {
			setIsUpdating( true );
			await dispatch( addressStore ).normalizeAddress(
				{
					address: {
						...values,
						address1: values.address ?? '',
						address2: '',
					},
				},
				type
			);
			setIsUpdating( false );
		};

		const toAddressShape = ( vals: T ) => ( {
			...vals,
			address1: vals.address ?? '',
			address2: '',
		} );

		const persistAddress = async ( vals: T, verified: boolean ) => {
			setIsUpdating( true );
			try {
				if ( isAdd ) {
					await dispatch( addressStore ).addOriginAddress(
						toAddressShape( vals ) as OriginAddress
					);
				} else {
					await dispatch( addressStore ).updateShipmentAddress(
						{
							orderId: orderId ?? '',
							address: toAddressShape( vals ),
							isVerified: verified,
							clearStoreAddressDrift:
								type === ADDRESS_TYPES.ORIGIN &&
								clearStoreAddressDriftOnSave,
						},
						type
					);
				}
				setIsComplete( true );
			} finally {
				setIsUpdating( false );
			}
		};

		const handleFormSubmit = async ( values: T ) => {
			// If only checkbox fields changed and address is already verified,
			// skip normalization and directly update the address.
			if ( hasOnlyCheckboxChanges( address, values ) && isVerified ) {
				await persistAddress( values, true );
				return;
			}

			// For any actual address field changes, go through normalization.
			await normalizeAddress( values );
		};

		const handleSaveWithoutValidation = async ( values: T ) => {
			setPendingBypassValues( values );
			setShowBypassConfirmation( true );
		};

		const confirmSaveWithoutValidation = async () => {
			if ( ! pendingBypassValues ) {
				return;
			}

			setShowBypassConfirmation( false );
			await persistAddress( pendingBypassValues, true );
			setPendingBypassValues( null );
		};

		const cancelSaveWithoutValidation = () => {
			setShowBypassConfirmation( false );
			setPendingBypassValues( null );
		};

		const updateAddress = async ( isNormalizedAddress: boolean ) => {
			setIsUpdating( true );

			if ( normalizedAddress && submittedAddress ) {
				normalizedAddress.email = submittedAddress.email;
				normalizedAddress.phone = submittedAddress.phone;
				if ( type === ADDRESS_TYPES.ORIGIN ) {
					const normalizedOriginAddress =
						normalizedAddress as OriginAddress;
					const submittedOriginAddress =
						submittedAddress as OriginAddress;

					normalizedOriginAddress.id = submittedOriginAddress.id;
					normalizedOriginAddress.name = submittedOriginAddress.name;
					normalizedOriginAddress.company =
						submittedOriginAddress.company;
					normalizedOriginAddress.firstName =
						submittedOriginAddress.firstName;
					normalizedOriginAddress.lastName =
						submittedOriginAddress.lastName;
					normalizedOriginAddress.defaultAddress =
						submittedOriginAddress.defaultAddress;
					normalizedOriginAddress.defaultReturnAddress =
						submittedOriginAddress.defaultReturnAddress;
				}
			}

			const selectedAddress = isNormalizedAddress
				? normalizedAddress
				: submittedAddress;
			if ( ! selectedAddress ) {
				// eslint-disable-next-line no-console
				console.warn(
					`No address to update for ${ type }, address: ${ selectedAddress }`
				);
				return;
			}

			if ( isAdd ) {
				await dispatch( addressStore ).addOriginAddress(
					selectedAddress as OriginAddress // Only origin address can be added
				);
			} else {
				await dispatch( addressStore ).updateShipmentAddress(
					{
						orderId: orderId ?? '',
						address: selectedAddress,
						isVerified: true, // Either the address is verified or the normalized address is selected
						clearStoreAddressDrift:
							type === ADDRESS_TYPES.ORIGIN &&
							clearStoreAddressDriftOnSave,
					},
					type
				);
			}

			setIsUpdating( false );
			setIsSuggestionModalOpen( false );
			setIsConfirming( false );
			setIsComplete( true );
		};

		const returnFromSuggestion = () => {
			setIsSuggestionModalOpen( false );
			setIsConfirming( false );
		};

		useEffect( () => {
			if ( needsConfirmation && ! isConfirming ) {
				setIsConfirming( true );
				setIsSuggestionModalOpen( true );
				dispatch( addressStore ).resetAddressNormalizationResponse(
					type
				);
			}
		}, [ needsConfirmation, isConfirming, type ] );

		useEffect( () => {
			if ( isComplete && isEmpty( validationErrors ) ) {
				setIsComplete( false );
				onCompleteCallback( address );
			}

			if ( ! isEmpty( validationErrors ) ) {
				setIsComplete( false );
			}
			// eslint-disable-next-line react-hooks/exhaustive-deps -- deps omitted to avoid duplicate invocations from parent re-renders.
		}, [ isComplete, validationErrors ] );

		const isSubmitButtonDisabled = ( {
			isDirty,
			isValidForm,
		}: {
			isValidForm: boolean;
			isDirty: boolean;
		} ): boolean => {
			/**
			 * We should always allow unverified addresses to be submitted.
			 * We allow it so the user can always get feedback about what they need to do next to complete address
			 * verification.
			 */
			if ( ! isVerified ) {
				return false;
			}

			/**
			 * Disallow incomplete forms from being submitted since our inline error messages will inform users about
			 * what they need to do as next steps before sending a request.
			 */
			if ( ! isValidForm ) {
				return true;
			}

			/**
			 * We should allow the form to be submitted if there are any changes to the form.
			 */
			return ! isDirty;
		};

		return (
			<div>
				{ nextDesign ? (
					<>
						{ ! isSuggestionModalOpen && (
							<AddressDataForm
								type={ type }
								initialValue={ { ...address } }
								onSubmit={ normalizeAddress }
								onCancel={ onCancelCallback }
								isUpdating={ isUpdating }
								isVerified={ isVerified }
								validationErrors={ validationErrors }
								originCountry={ originCountry }
								onSaveWithoutValidation={
									handleSaveWithoutValidation
								}
								surfaceArea={ surfaceArea }
							/>
						) }
					</>
				) : (
					<Form< T >
						validate={ validateAddressSection }
						initialValues={ address }
						onSubmit={ handleFormSubmit }
					>
						{
							// @ts-ignore - function as child is not recognized by the Form component typings
							( {
								isValidForm,
								handleSubmit,
								isDirty,
								values,
							}: {
								isValidForm: boolean;
								handleSubmit: () => void;
								isDirty: boolean;
								values: T;
							} ) => (
								<>
									<AddressContextProvider
										initialValue={ {
											isUpdating,
											validationErrors,
										} }
									>
										<p>
											{ __(
												"Please complete all required fields and click the 'Validate and save' button below to confirm and validate your address details.",
												'woocommerce-shipping'
											) }
										</p>
										<AddressFields
											group={ type }
											errorCallback={ setWarningMessage }
											originCountry={ originCountry }
										/>
									</AddressContextProvider>
									<Flex justify="space-between" as="footer">
										<AddressVerifiedIcon
											isVerified={ isVerified }
											isFormChanged={
												isDirty &&
												! hasOnlyCheckboxChanges(
													address,
													values
												)
											}
											isFormValid={ isValidForm }
											errorMessage={ warningMessage }
											addressType={ ADDRESS_TYPES.ORIGIN }
										/>
										<FlexItem>
											<Flex gap={ 2 }>
												<Button
													onClick={ onCancelCallback }
													isBusy={ isUpdating }
													variant="tertiary"
												>
													{ __(
														'Cancel',
														'woocommerce-shipping'
													) }
												</Button>
												{ ! isEmpty(
													validationErrors
												) &&
													! normalizedAddress && (
														<Button
															onClick={ () =>
																handleSaveWithoutValidation(
																	values
																)
															}
															isBusy={
																isUpdating
															}
															variant="secondary"
														>
															{ __(
																'Save without validating',
																'woocommerce-shipping'
															) }
														</Button>
													) }
												<Button
													onClick={ handleSubmit }
													disabled={ isSubmitButtonDisabled(
														{
															isValidForm,
															isDirty,
														}
													) }
													isBusy={ isUpdating }
													variant="primary"
												>
													{ __(
														'Validate and save',
														'woocommerce-shipping'
													) }
												</Button>
											</Flex>
										</FlexItem>
									</Flex>
								</>
							)
						}
					</Form>
				) }
				{ isSuggestionModalOpen &&
					submittedAddress &&
					normalizedAddress && (
						<Modal
							className="address-suggestion-modal"
							onRequestClose={ returnFromSuggestion }
							focusOnMount
							shouldCloseOnClickOutside={ false }
							title={ __(
								'Confirm address',
								'woocommerce-shipping'
							) }
							__experimentalHideHeader={ nextDesign }
							size={ nextDesign ? 'medium' : undefined }
						>
							<AddressSuggestion
								warnings={ warnings }
								originalAddress={ submittedAddress }
								normalizedAddress={ normalizedAddress }
								editAddress={ returnFromSuggestion }
								confirmAddress={ updateAddress }
								errors={ validationErrors }
								nextDesign={ nextDesign }
								isUpdating={ isUpdating }
								surfaceArea={ surfaceArea }
							></AddressSuggestion>
						</Modal>
					) }
				{ showBypassConfirmation && (
					<Modal
						title={ __(
							'Save without validation?',
							'woocommerce-shipping'
						) }
						onRequestClose={ cancelSaveWithoutValidation }
						shouldCloseOnClickOutside={ false }
					>
						<p>
							{ __(
								'This address could not be validated. By proceeding, you confirm the address is correct and deliverable. Incorrect addresses may result in delivery issues and are not eligible for refunds.',
								'woocommerce-shipping'
							) }
						</p>
						<Flex justify="flex-end" gap={ 2 }>
							<Button
								variant="tertiary"
								onClick={ cancelSaveWithoutValidation }
							>
								{ __( 'Cancel', 'woocommerce-shipping' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ confirmSaveWithoutValidation }
							>
								{ __(
									'Approve and save anyway',
									'woocommerce-shipping'
								) }
							</Button>
						</Flex>
					</Modal>
				) }
			</div>
		);
	}
)( 'AddressStep' );
