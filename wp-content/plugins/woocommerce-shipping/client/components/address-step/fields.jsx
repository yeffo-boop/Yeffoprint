import { useEffect } from '@wordpress/element';
import {
	CheckboxControl,
	Flex,
	FlexBlock,
	FlexItem,
	SelectControl,
	TextControl,
	__experimentalSpacer as Spacer,
} from '@wordpress/components';
import { useFormContext } from '@woocommerce/components';
import { __ } from '@wordpress/i18n';
import { getCountryNames, getStateNames, isMailAndPhoneRequired } from 'utils';
import { useAddressContext } from './context';
import { withBoundary } from 'components/HOC';
import { ADDRESS_TYPES } from 'data/constants';

export const AddressFields = withBoundary(
	( { group, errorCallback, originCountry } ) => {
		const { isUpdating, validationErrors } = useAddressContext();

		const {
			values,
			getInputProps,
			getCheckboxControlProps,
			getSelectControlProps,
			errors,
		} = useFormContext();
		const allowChangeCountry = true;
		const countryNames = getCountryNames( group, values.country );

		const stateNames = values.country
			? getStateNames( values.country )
			: [];

		const getProps = ( key, props ) => {
			// "Props" is an optional argument which is why we have a fallback to get input props.
			props ??= getInputProps( key );

			// Mutate the props object to display input errors.
			if ( validationErrors[ key ] ?? errors[ key ] ) {
				return {
					...props,
					help: validationErrors[ key ] ?? errors[ key ],
					className: 'has-error',
				};
			}

			return props;
		};

		useEffect( () => {
			if ( 'general' in validationErrors ) {
				errorCallback( validationErrors.general );
			}
		}, [ errorCallback, validationErrors ] );

		const isPhoneAndEmailRequired = isMailAndPhoneRequired( {
			type: group,
			originCountry,
			destinationCountry: values.country,
		} );

		return (
			<div>
				<Flex direction="column">
					<TextControl
						{ ...getProps( 'name' ) }
						label={ __( 'Name', 'woocommerce-shipping' ) }
						required={ ! values.company || values.name }
						// Opting into the new styles for margin bottom
						__nextHasNoMarginBottom={ true }
						// Opting into the new styles for height
						__next40pxDefaultSize={ true }
					/>
					<Spacer marginTop={ 0 } marginBottom={ 1 } />
					<TextControl
						{ ...getProps( 'company' ) }
						label={ __( 'Company', 'woocommerce-shipping' ) }
						required={ ! values.name }
						disabled={ isUpdating }
						// Opting into the new styles for margin bottom
						__nextHasNoMarginBottom={ true }
						// Opting into the new styles for height
						__next40pxDefaultSize={ true }
					/>
					<Spacer marginTop={ 0 } marginBottom={ 1 } />
					<FlexItem>
						<Flex>
							<FlexBlock>
								<TextControl
									{ ...getProps( 'email' ) }
									label={
										group === ADDRESS_TYPES.ORIGIN
											? __(
													'Email address',
													'woocommerce-shipping'
											  )
											: __(
													'Email address',
													'woocommerce-shipping'
											  )
									}
									disabled={ isUpdating }
									required={ isPhoneAndEmailRequired }
									// Opting into the new styles for margin bottom
									__nextHasNoMarginBottom={ true }
									// Opting into the new styles for height
									__next40pxDefaultSize={ true }
								/>
							</FlexBlock>
							<FlexBlock>
								<TextControl
									{ ...getProps( 'phone' ) }
									label={
										group === ADDRESS_TYPES.ORIGIN
											? __(
													'Phone',
													'woocommerce-shipping'
											  )
											: __(
													'Phone',
													'woocommerce-shipping'
											  )
									}
									placeholder={ __(
										'e.g., +1 (212) 555–0123 or 2125550123',
										'woocommerce-shipping'
									) }
									disabled={ isUpdating }
									required={ isPhoneAndEmailRequired }
									// Opting into the new styles for margin bottom
									__nextHasNoMarginBottom={ true }
									// Opting into the new styles for height
									__next40pxDefaultSize={ true }
								/>
							</FlexBlock>
						</Flex>
					</FlexItem>
					<Spacer marginTop={ 0 } marginBottom={ 1 } />
					<SelectControl
						label={ __( 'Country', 'woocommerce-shipping' ) }
						options={ countryNames }
						{ ...getProps(
							'country',
							getSelectControlProps( 'country' )
						) }
						// eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing -- boolean OR is intentional
						disabled={ isUpdating || ! allowChangeCountry }
						required
						// Opting into the new styles for margin bottom
						__nextHasNoMarginBottom={ true }
						// Opting out of the new styles for height
						__next40pxDefaultSize={ true }
					/>
					<Spacer marginTop={ 0 } marginBottom={ 1 } />
					<TextControl
						label={ __( 'Address', 'woocommerce-shipping' ) }
						{ ...getProps( 'address' ) }
						disabled={ isUpdating }
						required
						// Opting into the new styles for margin bottom
						__nextHasNoMarginBottom={ true }
						// Opting into the new styles for height
						__next40pxDefaultSize={ true }
					/>
					<Spacer marginTop={ 0 } marginBottom={ 1 } />
					<TextControl
						label={ __( 'City', 'woocommerce-shipping' ) }
						{ ...getProps( 'city' ) }
						disabled={ isUpdating }
						required
						// Opting into the new styles for margin bottom
						__nextHasNoMarginBottom={ true }
						// Opting into the new styles for height
						__next40pxDefaultSize={ true }
					/>
					<Spacer marginTop={ 0 } marginBottom={ 1 } />
					<FlexItem direction="column">
						<Flex>
							<FlexBlock>
								<TextControl
									label={ __(
										'State',
										'woocommerce-shipping'
									) }
									{ ...getProps(
										'state',
										getSelectControlProps( 'state' )
									) }
									disabled={ isUpdating }
									required={ stateNames.length > 0 }
									// Opting into the new styles for margin bottom
									__nextHasNoMarginBottom={ true }
									// Opting into the new styles for height
									__next40pxDefaultSize={ true }
								/>
							</FlexBlock>
							<FlexBlock>
								<TextControl
									label={ __(
										'Postal code',
										'woocommerce-shipping'
									) }
									{ ...getProps( 'postcode' ) }
									disabled={ isUpdating }
									required
									// Opting into the new styles for margin bottom
									__nextHasNoMarginBottom={ true }
									// Opting into the new styles for height
									__next40pxDefaultSize={ true }
								/>
							</FlexBlock>
						</Flex>
						{ group === ADDRESS_TYPES.ORIGIN && (
							<>
								<Flex>
									<FlexBlock>
										<CheckboxControl
											label={ __(
												'Save as default sender address',
												'woocommerce-shipping'
											) }
											disabled={ isUpdating }
											{ ...getProps(
												'defaultAddress',
												getCheckboxControlProps(
													'defaultAddress'
												)
											) }
											__nextHasNoMarginBottom={ true }
										/>
									</FlexBlock>
								</Flex>
								<Flex>
									<FlexBlock>
										<CheckboxControl
											label={ __(
												'Save as default return address',
												'woocommerce-shipping'
											) }
											disabled={ isUpdating }
											{ ...getProps(
												'defaultReturnAddress',
												getCheckboxControlProps(
													'defaultReturnAddress'
												)
											) }
											__nextHasNoMarginBottom={ true }
										/>
									</FlexBlock>
								</Flex>
							</>
						) }
					</FlexItem>
				</Flex>
			</div>
		);
	}
)( 'AddressFields' );
