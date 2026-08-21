import { useEffect, useRef, JSX } from 'react';
import { useFormContext } from '@woocommerce/components';
import type { InputProps } from '@woocommerce/components/build-types/form';

import {
	__experimentalHeading as Heading,
	__experimentalInputControl as InputControl,
	__experimentalSpacer as Spacer,
	CheckboxControl,
	ExternalLink,
	Flex,
	FlexBlock,
	Icon,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';
import { contentTypes, restrictionTypes } from './constants';
import { ControlledPopover } from '../../controlled-popover';
import { getCountryNames, getWeightUnit } from '../../../utils';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { CustomsItem, CustomsState } from 'types';
import { CUSTOMS_SECTION } from '../essential-details/constants';

export const CustomsForm = (): JSX.Element => {
	const weightUnit = getWeightUnit();
	const {
		storeCurrency: { getCurrencyConfig },
		shipment: { getShipmentOrigin },
		customs: { isHSTariffNumberRequired },
		labels: { hasPurchasedLabel },
	} = useLabelPurchaseContext();
	const { symbol: currencySymbol, symbolPosition } = getCurrencyConfig();
	const {
		values: { items, restrictionType, contentsType },
		getInputProps,
		getSelectControlProps,
		getCheckboxControlProps,
		setValue,
		errors,
	} = useFormContext< CustomsState >();
	const contentTypeRef = useRef< HTMLSelectElement >( null );
	const {
		essentialDetails: { focusArea: essentialDetailsFocusArea },
	} = useLabelPurchaseContext();

	useEffect( () => {
		if (
			essentialDetailsFocusArea === CUSTOMS_SECTION &&
			contentTypeRef.current
		) {
			contentTypeRef.current.focus();
		}
	}, [ essentialDetailsFocusArea ] );

	/**
	 * Origin is defined at this point,
	 * we're asking for all countries as there shouldn't be any limit on the destination country, countries used for origin are limited to US for now
	 */
	const countryNames = getCountryNames( 'all', getShipmentOrigin().country );

	const showOtherRestrictionType = restrictionType === 'other';
	const showOtherContentsType = contentsType === 'other';
	// Document mailings don't carry an itemized customs declaration — the label
	// omits the customs items entirely (see WOOSHIP-2283) — so hide the product
	// details fields and make it clear no item description is submitted.
	const isDocuments = contentsType === 'documents';
	const disable = hasPurchasedLabel( false );

	const getProps = < T extends CustomsState[ keyof CustomsState ] >(
		key: keyof CustomsState | keyof CustomsItem,
		productId?: number,
		defaultValue: T = '' as T,
		options?: { help: string }
	): Omit< InputProps< CustomsState, T >, 'onBlur' > => {
		if ( productId ) {
			const existingOrderItem =
				items.find( ( { id } ) => id === productId ) ??
				( {} as CustomsItem );
			const index = items.findIndex( ( { id } ) => id === productId );
			const shipmentItem = {
				...existingOrderItem,
				[ key as keyof CustomsItem ]: ( existingOrderItem?.[
					key as keyof CustomsItem
				] ?? defaultValue ) as T,
			};

			/**
			 * The type for errors (FormErrors< CustomsState >) doesn't accommodate for
			 * errors.items which is defined as { items: FormErrors< CustomsItem >[] }
			 * See CustomsValidationInput
			 */
			// @ts-ignore
			const help = errors?.items?.[ index ][ key as keyof CustomsItem ];
			return {
				value:
					( shipmentItem[
						key as keyof CustomsItem
					] as unknown as T ) || defaultValue,
				onChange: ( value ) => {
					const shipmentItems = items.map( ( item ) => {
						if ( item.id === productId ) {
							return {
								...item,
								[ key ]: value,
							};
						}
						return item;
					} );
					setValue( 'items', shipmentItems );
				},
				checked: Boolean( shipmentItem[ key as keyof CustomsItem ] ),
				// Refrain from showing errors on disabled form fields
				help: disable || help,
				className: help && ! disable ? 'has-error' : '',
			};
		}

		const props = getInputProps( key as keyof CustomsState ) as InputProps<
			CustomsState,
			T
		>;

		return errors[ key as keyof CustomsState ]
			? {
					...props,
					help:
						( errors[ key as keyof CustomsState ] as string ) ??
						undefined,
					className: 'has-error',
					onChange: ( value ) => {
						props.onChange( value );
					},
			  }
			: {
					...props,
					help: options?.help,
					onChange: ( value ) => {
						props.onChange( value );
					},
			  };
	};

	const symbolProp =
		symbolPosition === 'left'
			? { prefix: currencySymbol }
			: { suffix: currencySymbol };

	return (
		<>
			<Flex gap={ 6 } align="flex-start">
				<FlexBlock>
					<SelectControl
						label={ __( 'Content type', 'woocommerce-shipping' ) }
						{ ...getSelectControlProps( 'contentsType' ) }
						options={ contentTypes }
						disabled={ disable }
						required={ true }
						ref={ contentTypeRef }
						// Opting into the new styles for margin bottom
						__nextHasNoMarginBottom={ true }
						// Opting into the new styles for height
						__next40pxDefaultSize={ true }
					/>
					{ showOtherContentsType && (
						<TextControl
							label={ __(
								'Content details',
								'woocommerce-shipping'
							) }
							required={ true }
							{ ...getProps< string >( 'contentsExplanation' ) }
							disabled={ disable }
							// Opting into the new styles for margin bottom
							__nextHasNoMarginBottom={ true }
							// Opting into the new styles for height
							__next40pxDefaultSize={ true }
						/>
					) }
				</FlexBlock>
				<FlexBlock>
					<SelectControl
						label={ __(
							'Restriction type',
							'woocommerce-shipping'
						) }
						options={ restrictionTypes }
						{ ...getSelectControlProps( 'restrictionType' ) }
						disabled={ disable }
						required={ true }
						// Opting into the new styles for margin bottom
						__nextHasNoMarginBottom={ true }
						// Opting into the new styles for height
						__next40pxDefaultSize={ true }
					/>
					{ showOtherRestrictionType && (
						<TextControl
							label={ __(
								'Restriction details',
								'woocommerce-shipping'
							) }
							required={ true }
							{ ...getProps< string >( 'restrictionComments' ) }
							disabled={ disable }
							// Opting into the new styles for margin bottom
							__nextHasNoMarginBottom={ true }
							// Opting into the new styles for height
							__next40pxDefaultSize={ true }
						/>
					) }
				</FlexBlock>
			</Flex>
			<Flex>
				<TextControl
					label={ createInterpolateElement(
						__(
							'International transaction number (<a>more info about ITN</a>)',
							'woocommerce-shipping'
						),
						{
							a: (
								<ExternalLink href="https://pe.usps.com/text/imm/immc5_010.htm">
									{ ' ' }
								</ExternalLink>
							),
						}
					) }
					{ ...getProps< string >( 'itn', undefined, '', {
						help: __(
							'Please enter a valid ITN in one of these formats: X12345678901234, AES X12345678901234, or NOEEI 30.37(a)',
							'woocommerce-shipping'
						),
					} ) }
					disabled={ disable }
					// Opting into the new styles for margin bottom
					__nextHasNoMarginBottom={ true }
					// Opting into the new styles for height
					__next40pxDefaultSize={ true }
				/>
			</Flex>
			<Flex>
				<CheckboxControl
					label={ __(
						'Return package to sender if undeliverable',
						'woocommerce-shipping'
					) }
					{ ...getCheckboxControlProps( 'isReturnToSender' ) }
					help={ __(
						'Sender will be charged all return shipping costs and any applicable customs duties, taxes, or fees.',
						'woocommerce-shipping'
					) }
					disabled={ disable }
					// Opting into the new styles for height
					__nextHasNoMarginBottom={ true }
				/>
			</Flex>
			{ isDocuments ? (
				<Flex>
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Document mailings don’t include a customs item declaration, so no product details are submitted for this label.',
							'woocommerce-shipping'
						) }
					</Notice>
				</Flex>
			) : (
				<>
					<Flex>
						<Heading level={ 4 }>
							{ __( 'Product details', 'woocommerce-shipping' ) }
						</Heading>
					</Flex>
					<Spacer margin={ 10 } />
					<Flex direction="column">
						{ items.map( ( { id }, index ) => (
							<Flex gap={ 6 } direction="column" key={ id }>
								<FlexBlock>
									<Flex
										align="normal"
										className="customs-items"
									>
										<TextControl
											label={
												<>
													{ __(
														'Description',
														'woocommerce-shipping'
													) }{ ' ' }
													<ControlledPopover icon="info-outline">
														{ createInterpolateElement(
															__(
																`When shipping to countries that follow European Union (EU) customs rules, you must provide a clear, specific description on every item. For example, if you are sending clothing, you must indicate what type of clothing (e.g. men\'s shirts, girl\'s vest, boy\'s jacket) for the description to be acceptable. Otherwise, shipments may be delayed or interrupted at customs. <a>Learn more about customs rules <i></i></a>`,
																'woocommerce-shipping'
															),
															{
																a: (
																	<ExternalLink href="https://www.usps.com/international/new-eu-customs-rules.htm">
																		{ ' ' }
																	</ExternalLink>
																),
																i: (
																	<Icon
																		icon="external"
																		size={
																			16
																		}
																	/>
																),
															}
														) }
													</ControlledPopover>
												</>
											}
											hideLabelFromVision={ index !== 0 }
											{ ...getProps<
												CustomsItem[ 'description' ]
											>( 'description', Number( id ) ) }
											disabled={ disable }
											required={ true }
											// Opting into the new styles for margin bottom
											__nextHasNoMarginBottom={ true }
											// Opting into the new styles for height
											__next40pxDefaultSize={ true }
										/>
										<TextControl
											label={ createInterpolateElement(
												__(
													'HS tariff number (<a>more…</a>)',
													'woocommerce-shipping'
												),
												{
													a: (
														<ExternalLink href="https://woocommerce.com/document/woocommerce-shipping-and-tax/woocommerce-shipping/#section-30">
															{ ' ' }
														</ExternalLink>
													),
												}
											) }
											hideLabelFromVision={ index !== 0 }
											{ ...getProps< string >(
												'hsTariffNumber',
												Number( id )
											) }
											placeholder={
												isHSTariffNumberRequired()
													? ''
													: __(
															'Optional',
															'woocommerce-shipping'
													  )
											}
											required={ isHSTariffNumberRequired() }
											disabled={ disable }
											// Opting into the new styles for height
											__next40pxDefaultSize={ true }
											// Opting into the new styles for margin bottom
											__nextHasNoMarginBottom={ true }
										/>
										<InputControl
											label={ __(
												'Value per unit',
												'woocommerce-shipping'
											) }
											hideLabelFromVision={ index !== 0 }
											type="number"
											min={ 0 }
											step={ 0.01 }
											{ ...symbolProp }
											{ ...getProps(
												'price',
												Number( id )
											) }
											disabled={ disable }
											// Opting into the new styles for height
											__next40pxDefaultSize={ true }
										/>
										<InputControl
											label={ __(
												'Weight per unit',
												'woocommerce-shipping'
											) }
											hideLabelFromVision={ index !== 0 }
											type="number"
											min={ 0 }
											step={ 0.01 }
											suffix={ weightUnit }
											{ ...getProps(
												'weight',
												Number( id )
											) }
											disabled={ disable }
											required={ true }
											// Opting into the new styles for height
											__next40pxDefaultSize={ true }
										/>
										<SelectControl
											label={
												<>
													{ __(
														'Origin country',
														'woocommerce-shipping'
													) }
													<ControlledPopover icon="info-outline">
														{ __(
															'Country where the product was manufactured or assembled.',
															'woocommerce-shipping'
														) }
													</ControlledPopover>{ ' ' }
												</>
											}
											hideLabelFromVision={ index !== 0 }
											options={ countryNames }
											{ ...getProps(
												'originCountry',
												Number( id )
											) }
											disabled={ disable }
											required={ true }
											// Opting into the new styles for margin bottom
											__nextHasNoMarginBottom={ true }
											// Opting into the new styles for height
											__next40pxDefaultSize={ true }
										/>
									</Flex>
								</FlexBlock>
							</Flex>
						) ) }
					</Flex>
				</>
			) }
		</>
	);
};
