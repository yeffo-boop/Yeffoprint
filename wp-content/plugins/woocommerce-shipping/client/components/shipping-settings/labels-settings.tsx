import clsx from 'clsx';
import PaymentCard from './payment-card';
import ExternalInfo from './external-info';
import {
	__experimentalHeading as Heading,
	__experimentalSpacer as Spacer,
	__experimentalText as Text,
	Button,
	Card,
	CardBody,
	CheckboxControl,
	ExternalLink,
	Flex,
	__experimentalInputControl as InputControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import React, { useState } from 'react';
import { dispatch, select } from '@wordpress/data';
import { useSettings } from 'data/settings/hooks';
import { settingsStore } from 'data/settings';
// Shouldn't import from index to keep label-purchase decoupled from shipping-settings.
import { getPaperSizes } from 'components/label-purchase/label/utils';
import { getStoreOrigin } from 'utils/location';
import { SETTINGS_KEYS } from './constants';
import { createInterpolateElement } from '@wordpress/element';

export const LabelsSettingsComponent = () => {
	const [ isLoading, setIsLoading ] = useState( false );
	const paperSizes = getPaperSizes( getStoreOrigin().country );
	const {
		labelSize,
		emailReceiptEnabled,
		rememberServiceEnabled,
		rememberPackageEnabled,
		checkoutAddressValidation,
		taxIdentifiers,
		storeOwnerUsername,
		storeOwnerLogin,
		storeOwnerEmail,
		automaticallyOpenPrintDialog,
		rememberLastUsedShippingDate,
		returnToSenderDefault,
		scanformEnabled,
	} = useSettings();

	const maybeConfirmExit = ( isChanged: boolean ) => {
		if ( isChanged ) {
			window.onbeforeunload = function () {
				return true;
			};
		} else {
			window.onbeforeunload = null;
		}
	};

	const updateFormData =
		( formInputKey: string ) =>
		async ( formInputvalue: boolean | string | undefined ) => {
			await dispatch( settingsStore ).updateFormData(
				formInputKey,
				formInputvalue ?? null
			);
			maybeConfirmExit( true );
		};

	const getLabelSizeOptions = () => {
		const sizes = [];
		sizes.push( {
			disabled: true,
			label: __( 'Select an Option', 'woocommerce-shipping' ),
			value: '',
		} );

		paperSizes.map( ( size ) => {
			return sizes.push( {
				label: size.name,
				value: size.key,
			} );
		} );
		return sizes;
	};

	const saveButtonHandler = async () => {
		setIsLoading( true );
		const storeConfig = select( settingsStore ).getConfigSettings();

		const saveResult = await dispatch( settingsStore ).saveSettings( {
			payload: storeConfig,
		} );

		if (
			saveResult &&
			'result' in saveResult && // @ts-ignore
			saveResult.result.success
		) {
			// @ts-ignore dispatch wants a descriptor object
			dispatch( 'core/notices' ).createSuccessNotice(
				__(
					'WooCommerce Shipping settings have been saved.',
					'woocommerce-shipping'
				)
			);
		}

		setIsLoading( false );
		maybeConfirmExit( false );
	};

	const className = clsx( 'wcshipping-settings__card', {
		loading: isLoading,
	} );

	return (
		<Flex
			align="flex-start"
			gap={ 6 }
			justify="flex-start"
			className="wcshipping-settings"
		>
			{ isLoading && (
				<Spinner className="wcshipping-settings__spinner" />
			) }

			<Flex direction="column">
				<Spacer marginTop={ 6 } marginBottom={ 0 } />
				<Heading level={ 4 }>
					{ __( 'Shipping Labels', 'woocommerce-shipping' ) }
				</Heading>
				<Text>
					{ __(
						'Print shipping labels right from your WooCommerce dashboard and instantly save on shipping.',
						'woocommerce-shipping'
					) }
				</Text>
			</Flex>
			<Flex direction="column">
				<Card className={ className } size="large">
					<CardBody>
						<h4>
							{ __(
								'Select label size',
								'woocommerce-shipping'
							) }
						</h4>
						<Spacer marginTop={ 0 } marginBottom={ 4 } />
						<SelectControl
							label={ __( 'Paper size', 'woocommerce-shipping' ) }
							value={ labelSize }
							help={ __(
								'This setting determines the default printing size for shipping labels. You can select different sizes both during the purchase of a shipping label and afterward.',
								'woocommerce-shipping'
							) }
							onChange={ updateFormData(
								SETTINGS_KEYS.PAPER_SIZE
							) }
							options={ getLabelSizeOptions() }
							// Opt in to the new size for consistency with other form fields.
							__next40pxDefaultSize={ true }
							// Opt in to the new bottom margin for consistency with other form fields.
							__nextHasNoMarginBottom={ true }
						/>
						<PaymentCard />
						<ExternalInfo />

						<h4>{ __( 'Preferences', 'woocommerce-shipping' ) }</h4>

						<CheckboxControl
							label={ __(
								'Email label purchase receipts',
								'woocommerce-shipping'
							) }
							help={ sprintf(
								// translators: %1$s: store owner's username, %2$s: store owner's login, %3$s: store owner's email address.
								__(
									`Email the label purchase receipts to %1$s (%2$s) at %3$s`,
									'woocommerce-shipping'
								),
								storeOwnerUsername,
								storeOwnerLogin,
								storeOwnerEmail
							) }
							checked={ Boolean( emailReceiptEnabled ) }
							onChange={ updateFormData(
								SETTINGS_KEYS.EMAIL_RECEIPTS
							) }
							// Opt in to the new bottom margin for consistency with other form fields.
							__nextHasNoMarginBottom={ true }
						/>
						<Spacer marginTop={ 0 } marginBottom={ 3 } />
						<CheckboxControl
							label={ __(
								'Remember service selection',
								'woocommerce-shipping'
							) }
							help={ __(
								'Save the service selection from previous transaction.',
								'woocommerce-shipping'
							) }
							checked={ Boolean( rememberServiceEnabled ) }
							onChange={ updateFormData(
								SETTINGS_KEYS.USE_LAST_SERVICE
							) }
							// Opt in to the new bottom margin for consistency with other form fields.
							__nextHasNoMarginBottom={ true }
						/>
						<Spacer marginTop={ 0 } marginBottom={ 3 } />
						<CheckboxControl
							label={ __(
								'Remember package selection',
								'woocommerce-shipping'
							) }
							help={ __(
								'Save the package selection from previous transaction.',
								'woocommerce-shipping'
							) }
							checked={ Boolean( rememberPackageEnabled ) }
							onChange={ updateFormData(
								SETTINGS_KEYS.USE_LAST_PACKAGE
							) }
							// Opt in to the new bottom margin for consistency with other form fields.
							__nextHasNoMarginBottom={ true }
						/>
						<Spacer marginTop={ 0 } marginBottom={ 3 } />
						<CheckboxControl
							label={ __(
								'Enable address validation at checkout',
								'woocommerce-shipping'
							) }
							help={ __(
								'Give your customers the chance to validate their shipping address before they complete their purchase.',
								'woocommerce-shipping'
							) }
							checked={ Boolean( checkoutAddressValidation ) }
							onChange={ updateFormData(
								SETTINGS_KEYS.CHECKOUT_ADDRESS_VALIDATION
							) }
							// Opt in to the new bottom margin for consistency with other form fields.
							__nextHasNoMarginBottom={ true }
						/>
						<Spacer marginTop={ 0 } marginBottom={ 3 } />
						<CheckboxControl
							label={ __(
								'Open print dialog after successful label purchase',
								'woocommerce-shipping'
							) }
							help={ __(
								'Automatically open the print dialog after a successful label purchase.',
								'woocommerce-shipping'
							) }
							checked={ Boolean( automaticallyOpenPrintDialog ) }
							onChange={ updateFormData(
								SETTINGS_KEYS.AUTOMATICALLY_OPEN_PRINT_DIALOG
							) }
							// Opt in to the new bottom margin for consistency with other form fields.
							__nextHasNoMarginBottom={ true }
						/>
						<Spacer marginTop={ 0 } marginBottom={ 3 } />
						<CheckboxControl
							label={ __(
								'Remember last shipping date',
								'woocommerce-shipping'
							) }
							help={ __(
								'Remember the last shipping date used to purchase a label.',
								'woocommerce-shipping'
							) }
							checked={ Boolean( rememberLastUsedShippingDate ) }
							onChange={ updateFormData(
								SETTINGS_KEYS.REMEMBER_LAST_USED_SHIPPING_DATE
							) }
							// Opt in to the new bottom margin for consistency with other form fields.
							__nextHasNoMarginBottom={ true }
						/>
						<Spacer marginTop={ 0 } marginBottom={ 3 } />
						<CheckboxControl
							label={ __(
								'Enable automatic returns of undeliverable international shipments',
								'woocommerce-shipping'
							) }
							help={ __(
								'Default selection of the return to sender option for cross-border orders. You will be charged for: return shipping fees, customs duties on returned goods, import taxes, and any broker or handling fees assessed by carriers or customs authorities.',
								'woocommerce-shipping'
							) }
							checked={ Boolean( returnToSenderDefault ) }
							onChange={ updateFormData(
								SETTINGS_KEYS.RETURN_TO_SENDER_DEFAULT
							) }
							__nextHasNoMarginBottom={ true }
						/>
						<Spacer marginTop={ 0 } marginBottom={ 3 } />
						<CheckboxControl
							label={ __(
								'Enable USPS® SCAN Form feature',
								'woocommerce-shipping'
							) }
							help={ __(
								'Create a single barcode to scan all USPS packages scheduled for pickup.',
								'woocommerce-shipping'
							) }
							checked={ Boolean( scanformEnabled ) }
							onChange={ updateFormData(
								SETTINGS_KEYS.SCANFORM_ENABLED
							) }
							__nextHasNoMarginBottom={ true }
						/>

						<h4>
							{ __( 'Tax identifiers', 'woocommerce-shipping' ) }
						</h4>

						<InputControl
							label={ __(
								'Import One-Stop Shop (IOSS)',
								'woocommerce-shipping'
							) }
							help={ createInterpolateElement(
								__(
									'Enter your <a>Import One-Stop Shop (IOSS)</a> number to include it on customs forms when shipping to applicable EU countries.',
									'woocommerce-shipping'
								),
								{
									a: (
										<ExternalLink href="https://vat-one-stop-shop.ec.europa.eu/">
											{ __(
												'Import One-Stop Shop (IOSS)',
												'woocommerce-shipping'
											) }
										</ExternalLink>
									),
								}
							) }
							onChange={ updateFormData(
								SETTINGS_KEYS.TAX_IDENTIFIER_IOSS
							) }
							value={ taxIdentifiers?.ioss ?? '' }
							// Opt in to the new size for consistency with other form fields.
							__next40pxDefaultSize={ true }
						/>

						<Spacer marginTop={ 0 } marginBottom={ 3 } />

						<InputControl
							label={ __(
								'Norwegian VAT On E-Commerce (VOEC)',
								'woocommerce-shipping'
							) }
							help={ createInterpolateElement(
								__(
									'Enter your <a>VOEC number (VAT On E-Commerce)</a> number to include it on customs forms when shipping to Norway.',
									'woocommerce-shipping'
								),
								{
									a: (
										<ExternalLink href="https://www.toll.no/en/corporate/import/the-voec-scheme/">
											{ __(
												'VOEC number (VAT On E-Commerce)',
												'woocommerce-shipping'
											) }
										</ExternalLink>
									),
								}
							) }
							onChange={ updateFormData(
								SETTINGS_KEYS.TAX_IDENTIFIER_VOEC
							) }
							value={ taxIdentifiers?.voec ?? '' }
							// Opt in to the new size for consistency with other form fields.
							__next40pxDefaultSize={ true }
						/>

						<Spacer marginTop={ 0 } marginBottom={ 3 } />

						<InputControl
							label={ __(
								'Postponed VAT Accounting (PVA)',
								'woocommerce-shipping'
							) }
							help={ createInterpolateElement(
								__(
									'Enter your <a>PVA number</a> number to include it on customs forms when shipping to the United Kingdom of Great Britain and Northern Ireland.',
									'woocommerce-shipping'
								),
								{
									a: (
										<ExternalLink href="https://www.gov.uk/guidance/check-when-you-can-account-for-import-vat-on-your-vat-return">
											{ __(
												'PVA number (Postponed VAT Accounting)',
												'woocommerce-shipping'
											) }
										</ExternalLink>
									),
								}
							) }
							onChange={ updateFormData(
								SETTINGS_KEYS.TAX_IDENTIFIER_PVA
							) }
							value={ taxIdentifiers?.pva ?? '' }
							// Opt in to the new size for consistency with other form fields.
							__next40pxDefaultSize={ true }
						/>
					</CardBody>
				</Card>
				<Spacer marginTop={ 0 } marginBottom={ 1 } />
				<Flex justify="flex-end" className="submit">
					<Button variant="primary" onClick={ saveButtonHandler }>
						{ __( 'Save changes', 'woocommerce-shipping' ) }
					</Button>
				</Flex>
			</Flex>
		</Flex>
	);
};
