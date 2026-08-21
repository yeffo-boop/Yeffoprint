import clsx from 'clsx';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import {
	__experimentalInputControl as InputControl,
	__experimentalInputControlSuffixWrapper as InputControlSuffixWrapper,
	__experimentalSpacer as Spacer,
	Button,
	CheckboxControl,
	Flex,
	FlexBlock,
	FlexItem,
	Notice,
	SelectControl,
	RadioControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { dispatch, useSelect } from '@wordpress/data';
import { getDimensionsUnit, getWeightUnit } from 'utils';
import { FetchNotice } from './fetch-notice';
import { TAB_NAMES, CUSTOM_PACKAGE_TYPES } from '../constants';
import { labelPurchaseStore } from 'data/label-purchase';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { TotalWeight } from '../../total-weight';
import { CustomsWeightWarning } from './customs-weight-warning';
import { GetRatesButton } from '../../get-rates-button';
import { PACKAGE_SECTION } from 'components/label-purchase/essential-details/constants';
import { recordEvent } from 'utils/tracks';
import { withBoundary } from 'components/HOC/error-boundary';
import { PACKAGES_UPDATE_ERROR } from 'data/label-purchase/packages';

export const CustomPackage = withBoundary(
	( { rawPackageData, setRawPackageData, setSelectedPackage, setTab } ) => {
		const dimensionsUnit = getDimensionsUnit();
		const weightUnit = getWeightUnit();
		const [ saveAsTemplate, setSaveAsTemplate ] = useState( false );
		const [ isSaving, setIsSaving ] = useState( false );
		const [ isSaved, setIsSaved ] = useState( false );
		const containerRef = useRef( null );
		const {
			rates: { errors, isFetching, setErrors, fetchRates },
			customs: { hasErrors: hasCustomsErrors },
			essentialDetails: {
				focusArea: essentialDetailsFocusArea,
				resetFocusArea: resetEssentialDetailsFocusArea,
			},
			packages: { currentPackageTab },
			hazmat: { isHazmatSpecified },
			weight: { getShipmentTotalWeight },
			labels: { hasMissingPurchase },
			shipment: { isExtraLabelPurchaseValid },
			nextDesign,
		} = useLabelPurchaseContext();
		const setData = useCallback(
			( newData ) => {
				setIsSaved( false );
				setRawPackageData( { ...rawPackageData, ...newData } );
			},
			[ rawPackageData, setRawPackageData ]
		);

		const updateErrors = useSelect(
			( select ) => select( labelPurchaseStore ).getPackageUpdateErrors(),
			[]
		);

		useEffect( () => {
			if ( Object.keys( updateErrors ).length > 0 ) {
				setErrors( {
					name: updateErrors,
				} );
			}
		}, [ updateErrors, setErrors ] );

		const invalidDimensionError = nextDesign
			? __(
					'Invalid dimension value. Value should be a number greater than 0.',
					'woocommerce-shipping'
			  )
			: __( 'Invalid dimension value.', 'woocommerce-shipping' );

		const setErrorForInvalidDimension = ( value, fieldName ) => {
			if ( ! [ 'width', 'height', 'length' ].includes( fieldName ) ) {
				return;
			}

			const parsedVal = parseFloat( value );

			if ( parsedVal <= 0 || Number.isNaN( parsedVal ) ) {
				setErrors( {
					...errors,
					[ fieldName ]: {
						message: invalidDimensionError,
						className:
							'components-validated-control__indicator is-invalid',
					},
				} );
			}
		};

		useEffect(
			() => {
				if (
					currentPackageTab === TAB_NAMES.CUSTOM_PACKAGE &&
					essentialDetailsFocusArea === PACKAGE_SECTION
				) {
					// Show probable errors for the dimensions.
					setErrorForInvalidDimension(
						rawPackageData.width,
						'width'
					);
					setErrorForInvalidDimension(
						rawPackageData.height,
						'height'
					);
					setErrorForInvalidDimension(
						rawPackageData.length,
						'length'
					);

					// Scroll to the container so the user can see probable errors.
					window.scrollTo( {
						left: 0,
						top: containerRef.current.offsetTop,
						behavior: 'smooth',
					} );

					// Reset the focus area so that the next setting of the focus area will work.
					resetEssentialDetailsFocusArea();
				}
			},
			// eslint-disable-next-line react-hooks/exhaustive-deps -- we want this to only update when the currentPackageTab, essentialDetailsFocusArea, setErrors change
			[ currentPackageTab, essentialDetailsFocusArea, setErrors ]
		);

		const hasFormErrors = useCallback( () => {
			// eslint-disable-next-line no-unused-vars
			const { endpoint, ...formErrors } = errors;
			return Object.values( formErrors ).some( ( v ) => !! v );
		}, [ errors ] );

		const isAnyFieldEmpty = useCallback(
			() => Object.values( rawPackageData ).some( ( val ) => val === '' ),
			[ rawPackageData ]
		);

		const saveCustomPackage = useCallback( async () => {
			Object.entries( rawPackageData ).forEach( ( [ key, val ] ) => {
				if ( val === '' ) {
					setErrors( { ...errors, [ key ]: true } );
				}
			} );

			if ( rawPackageData.name.length < 3 ) {
				setErrors( {
					...errors,
					name: {
						message: __(
							'Package name should be at least 3 characters long.',
							'woocommerce-shipping'
						),
					},
				} );
				return;
			}
			setErrors( {
				...errors,
				name: false,
			} );

			if ( hasFormErrors() || isAnyFieldEmpty() > 0 ) {
				return;
			}

			setIsSaving( true );
			const {
				type: responseType,
				payload: { custom: updatedListOfPackages },
			} =
				await dispatch( labelPurchaseStore ).saveCustomPackage(
					rawPackageData
				);
			setIsSaving( false );

			// Bail the rest of the "save" logic if we have an error.
			// Displaying and validating errors are handled by the inputs themselves.
			if (
				responseType === PACKAGES_UPDATE_ERROR ||
				typeof updatedListOfPackages !== 'object' ||
				updatedListOfPackages.length === 0
			) {
				return;
			}

			// At this point, we're already verified that we did not have any errors etc.,
			// so it should be safe to assume that the package was saved stored and available
			// in the response.
			setIsSaved( true );

			const newlySavedPackage = updatedListOfPackages.find(
				( pkg ) => pkg.name === rawPackageData.name
			);

			if ( newlySavedPackage ) {
				// Set the new saved template as the selected package and redirect the user to the saved templates tab.
				setSelectedPackage( newlySavedPackage );
			}
		}, [
			rawPackageData,
			errors,
			hasFormErrors,
			setErrors,
			isAnyFieldEmpty,
			setSelectedPackage,
		] );

		/*
		 * Redirect to the saved templates tab after saving a custom package.
		 */
		useEffect( () => {
			if ( isSaved ) {
				setTab( TAB_NAMES.SAVED_TEMPLATES );
				setIsSaved( false );
			}
		}, [ isSaved, setIsSaved, setTab ] );

		const getControlProps = ( fieldName, className = '' ) => {
			const error = errors[ fieldName ];
			let helpContent = [];

			if ( error?.message ) {
				if ( error.className ) {
					helpContent = (
						<span className={ error.className }>
							{ error.message }
						</span>
					);
				} else {
					helpContent = error.message;
				}
			}

			return {
				onChange: ( val ) => {
					const { ...newErrors } = errors;
					delete newErrors[ fieldName ];
					setErrors( newErrors );
					setData( { ...rawPackageData, [ fieldName ]: val } );
					resetEssentialDetailsFocusArea();
					setErrorForInvalidDimension( val, fieldName );
				},
				value: rawPackageData[ fieldName ],
				className: clsx( className, {
					'has-error': errors[ fieldName ],
				} ),
				onValidate: ( value ) => {
					setErrorForInvalidDimension( value, fieldName );
				},
				help: helpContent,
			};
		};

		const getRates = useCallback( async () => {
			const tracksProperties = {
				package_id: rawPackageData?.id,
				is_letter:
					rawPackageData?.type === CUSTOM_PACKAGE_TYPES.ENVELOPE,
				width: rawPackageData?.width,
				height: rawPackageData?.height,
				length: rawPackageData?.length,
				box_weight: rawPackageData?.boxWeight,
			};
			recordEvent( 'label_purchase_get_rates_clicked', tracksProperties );
			fetchRates( rawPackageData );
		}, [ rawPackageData, fetchRates ] );

		const isExtraLabelPurchase = useCallback( () => {
			return ! hasMissingPurchase();
		}, [ hasMissingPurchase ] );

		const disableFetchButton = useMemo( () => {
			return (
				isFetching ||
				! rawPackageData.length ||
				! rawPackageData.width ||
				! rawPackageData.height ||
				hasFormErrors() ||
				hasCustomsErrors() ||
				! isHazmatSpecified() ||
				( isExtraLabelPurchase() && ! isExtraLabelPurchaseValid() )
			);
		}, [
			isFetching,
			rawPackageData.length,
			rawPackageData.width,
			rawPackageData.height,
			hasFormErrors,
			hasCustomsErrors,
			isHazmatSpecified,
			isExtraLabelPurchase,
			isExtraLabelPurchaseValid,
		] );

		const disableTemplateSaveButton = useCallback( () => {
			return (
				isSaving ||
				! rawPackageData.length ||
				! rawPackageData.width ||
				! rawPackageData.height ||
				! rawPackageData.boxWeight ||
				hasFormErrors()
			);
		}, [
			isSaving,
			rawPackageData.length,
			rawPackageData.width,
			rawPackageData.height,
			rawPackageData.boxWeight,
			hasFormErrors,
		] );

		return (
			<Flex
				direction="column"
				gap={ nextDesign ? 4 : 6 }
				ref={ containerRef }
			>
				<FlexItem>
					<Flex
						direction="column"
						className="custom-package__details"
						expanded
						gap={ nextDesign ? 4 : 8 }
						justify="space-between"
					>
						<Flex justify="space-between" gap={ 8 }>
							<FlexBlock>
								{ nextDesign ? (
									<RadioControl
										label={ __(
											'Package type',
											'woocommerce-shipping'
										) }
										selected={ rawPackageData.type }
										options={ [
											{
												label: __(
													'Box',
													'woocommerce-shipping'
												),
												value: CUSTOM_PACKAGE_TYPES.BOX,
											},
											{
												label: __(
													'Poly mailer',
													'woocommerce-shipping'
												),
												value: CUSTOM_PACKAGE_TYPES.ENVELOPE,
											},
										] }
										onChange={ ( type ) =>
											setData( {
												...rawPackageData,
												type,
											} )
										}
									/>
								) : (
									<SelectControl
										options={ [
											{
												label: __(
													'Box',
													'woocommerce-shipping'
												),
												value: CUSTOM_PACKAGE_TYPES.BOX,
											},
											{
												label: __(
													'Envelope',
													'woocommerce-shipping'
												),
												value: CUSTOM_PACKAGE_TYPES.ENVELOPE,
											},
										] }
										label={ __(
											'Package type',
											'woocommerce-shipping'
										) }
										style={ { flex: 2 } }
										onChange={ ( type ) =>
											setData( {
												...rawPackageData,
												type,
											} )
										}
										__nextHasNoMarginBottom={ true }
										__next40pxDefaultSize={ true }
									></SelectControl>
								) }
							</FlexBlock>
						</Flex>
						<Flex
							direction="row"
							justify="space-between"
							align="flex-start"
							gap={ nextDesign ? 4 : 0 }
							style={ { width: nextDesign ? '497px' : 'auto' } }
						>
							<InputControl
								label={ __( 'Length', 'woocommerce-shipping' ) }
								suffix={
									<InputControlSuffixWrapper>
										{ dimensionsUnit }
									</InputControlSuffixWrapper>
								}
								type="number"
								min={ 0 }
								placeholder="0"
								{ ...getControlProps( 'length' ) }
								__next40pxDefaultSize={ true }
							/>
							{ nextDesign ? null : (
								<Spacer
									direction="vertical"
									marginLeft={ 3 }
									marginRight={ 3 }
									paddingTop={ 8 }
								>
									{ 'x' }
								</Spacer>
							) }
							<InputControl
								label={ __( 'Width', 'woocommerce-shipping' ) }
								type="number"
								suffix={
									<InputControlSuffixWrapper>
										{ dimensionsUnit }
									</InputControlSuffixWrapper>
								}
								min={ 0 }
								placeholder="0"
								{ ...getControlProps( 'width' ) }
								__next40pxDefaultSize={ true }
							/>
							{ nextDesign ? null : (
								<Spacer
									direction="vertical"
									marginLeft={ 3 }
									marginRight={ 3 }
									paddingTop={ 8 }
								>
									{ 'x' }
								</Spacer>
							) }
							<InputControl
								label={ __( 'Height', 'woocommerce-shipping' ) }
								suffix={
									<InputControlSuffixWrapper>
										{ dimensionsUnit }
									</InputControlSuffixWrapper>
								}
								type="number"
								min={ 0 }
								placeholder="0"
								{ ...getControlProps( 'height' ) }
								__next40pxDefaultSize={ true }
							/>
						</Flex>
					</Flex>
				</FlexItem>
				{ ! nextDesign && (
					<FlexItem className="save-custom-template">
						<Flex
							direction="row"
							gap={ 12 }
							justify="space-between"
							align="flex-start"
						>
							<FlexItem isBlock>
								<CheckboxControl
									className="save-custom-template__toggle"
									label={ __(
										'Save this as a new package template',
										'woocommerce-shipping'
									) }
									onChange={ () =>
										setSaveAsTemplate( ! saveAsTemplate )
									}
									checked={ saveAsTemplate }
									__nextHasNoMarginBottom={ true }
								/>
								{ isSaved && ! saveAsTemplate && (
									<Notice
										status={ 'success' }
										politeness="polite"
										isDismissible={ false }
									>
										{ __(
											'Successfully saved to Saved templates.',
											'woocommerce-shipping'
										) }
									</Notice>
								) }
								{ saveAsTemplate && (
									<>
										<Spacer
											marginTop={ 3 }
											marginBottom={ 0 }
										/>

										<Flex
											className="save-template-form"
											gap={ 6 }
											direction="row"
											justify="space-between"
											align="flex-start"
										>
											<InputControl
												label={ __(
													'Template name',
													'woocommerce-shipping'
												) }
												placeholder={ __(
													'Enter a unique package name',
													'woocommerce-shipping'
												) }
												{ ...getControlProps(
													'name',
													'save-template-form__name'
												) }
												__next40pxDefaultSize={ true }
											/>
											<InputControl
												label={ __(
													'Package weight',
													'woocommerce-shipping'
												) }
												suffix={ weightUnit }
												type="number"
												min={ 0 }
												{ ...getControlProps(
													'boxWeight'
												) }
												__next40pxDefaultSize={ true }
											/>
											<Button
												isSecondary
												className="save-template-form__save-button"
												type="submit"
												isBusy={ isSaving }
												onClick={ () =>
													saveCustomPackage()
												}
												disabled={ disableTemplateSaveButton() }
											>
												{ __(
													'Save',
													'woocommerce-shipping'
												) }
											</Button>
										</Flex>
									</>
								) }
							</FlexItem>
						</Flex>
					</FlexItem>
				) }
				<FlexItem>
					<Flex align="flex-end" gap={ nextDesign ? 0 : 6 }>
						<TotalWeight
							packageWeight={ rawPackageData?.boxWeight ?? 0 }
						/>
						{ ! nextDesign && (
							<GetRatesButton
								onClick={ getRates }
								isBusy={ isFetching }
								disabled={
									disableFetchButton ||
									! getShipmentTotalWeight()
								}
							/>
						) }
					</Flex>
					<CustomsWeightWarning />
					<FetchNotice margin="before" />
				</FlexItem>
			</Flex>
		);
	}
)( 'CustomPackage' );
