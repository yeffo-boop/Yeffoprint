import { createInterpolateElement, useState } from '@wordpress/element';
import {
	__experimentalSpacer as Spacer,
	Button,
	CheckboxControl,
	Flex,
	Modal,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { addressToString, recordEvent } from 'utils';
import { UPSDAP_TOS_TYPES } from './constants';
import { LabelPurchaseError, OriginAddress } from 'types';
import { uniq } from 'lodash';

interface UPSDAPTosProps {
	close: () => void;
	confirm: ( confirm: boolean ) => void;
	shipmentOrigin: OriginAddress;
	acceptedVersions: string[];
	error?: LabelPurchaseError | null;
	isConfirming?: boolean;
	setIsConfirming?: ( isConfirming: boolean ) => void;
}

export const UPSDAPTos = ( {
	close,
	confirm,
	shipmentOrigin,
	error,
	isConfirming = false,
	setIsConfirming = () => undefined,
	acceptedVersions,
}: UPSDAPTosProps ) => {
	const [ selectedItems, setSelectedItem ] = useState<
		( typeof UPSDAP_TOS_TYPES )[ keyof typeof UPSDAP_TOS_TYPES ][]
	>( [
		/* If the user has already agreed to the TOS v2, only the UPSDAP_TOS_TYPES.LEGAL needs to be approved by them again,
		 * so that they can use the UPS Ground Saver® shipping method.
		 * Otherwise, all TOS types need to be approved.
		 */
		...( acceptedVersions.includes( 'v2' )
			? [
					UPSDAP_TOS_TYPES.PROHIBITED_ITEMS,
					UPSDAP_TOS_TYPES.TECHNOLOGY_AGREEMENT,
			  ]
			: [] ),
	] );
	const toggleItem =
		(
			type: ( typeof UPSDAP_TOS_TYPES )[ keyof typeof UPSDAP_TOS_TYPES ]
		) =>
		( select: boolean ) => {
			if ( select ) {
				setSelectedItem( ( prevSelections ) => [
					...prevSelections,
					type,
				] );
			} else {
				setSelectedItem( ( prevSelections ) =>
					prevSelections.filter( ( item ) => item !== type )
				);
			}
		};

	const onConfirm = async () => {
		setIsConfirming( true );
		recordEvent( 'label_purchase_upsdap_tos_confirmed', {
			name: shipmentOrigin?.name ?? '',
			company: shipmentOrigin?.company ?? '',
			address1: shipmentOrigin?.address1 ?? '',
			address2: shipmentOrigin?.address2 ?? '',
			city: shipmentOrigin?.city ?? '',
			state: shipmentOrigin?.state ?? '',
			postcode: shipmentOrigin?.postcode ?? '',
			country: shipmentOrigin?.country ?? '',
			phone: shipmentOrigin?.phone ?? '',
			email: shipmentOrigin?.email ?? '',
			previously_confirmed_versions: acceptedVersions,
		} );
		confirm( true );
	};

	const onClose = () => {
		recordEvent( 'label_purchase_upsdap_tos_closed', {
			name: shipmentOrigin?.name ?? '',
			company: shipmentOrigin?.company ?? '',
			address1: shipmentOrigin?.address1 ?? '',
			address2: shipmentOrigin?.address2 ?? '',
			city: shipmentOrigin?.city ?? '',
			state: shipmentOrigin?.state ?? '',
			postcode: shipmentOrigin?.postcode ?? '',
			country: shipmentOrigin?.country ?? '',
			phone: shipmentOrigin?.phone ?? '',
			email: shipmentOrigin?.email ?? '',
			previously_confirmed_versions: acceptedVersions,
		} );
		close();
	};

	return (
		<Modal
			overlayClassName="wcshipping-ups-tos-overlay"
			className="wcshipping-ups-tos-modal"
			onRequestClose={ onClose }
			focusOnMount
			shouldCloseOnClickOutside={ false }
			shouldCloseOnEsc={ false }
			size="medium"
			contentLabel={ __(
				'UPS® Terms and Conditions',
				'woocommerce-shipping'
			) }
			title={ __( 'UPS® Terms and Conditions', 'woocommerce-shipping' ) }
		>
			<Flex direction="column" gap={ 4 } as="section">
				<Flex as="header" direction="column" gap={ 4 }>
					<div className="ups-shipping-from">
						<h3>
							{ __( 'Shipping from', 'woocommerce-shipping' ) }
						</h3>
						<p className="address">
							{ addressToString( shipmentOrigin ) }
						</p>
					</div>
					{ acceptedVersions.length === 0 && (
						<p>
							{ __(
								'To start shipping from this address with UPS®, we need you to agree to the following terms and conditions:',
								'woocommerce-shipping'
							) }
						</p>
					) }
					{ acceptedVersions.length > 0 &&
						acceptedVersions.includes( 'v2' ) && (
							<div
								className="ups-ground-saver-notice"
								data-testid="ups-ground-saver-notice"
							>
								<p>
									{ createInterpolateElement(
										__(
											'<strong>UPS Ground Saver®</strong>, an economy, ground delivery service, for your lightweight, non-time sensitive packages, is now available on WooCommerce Shipping.',
											'woocommerce-shipping'
										),
										{
											strong: (
												<strong>
													{ __(
														'UPS Ground Saver®',
														'woocommerce-shipping'
													) }
												</strong>
											),
										}
									) }
								</p>
								<p>
									{ createInterpolateElement(
										__(
											'To start shipping from this address with <strong>UPS Ground Saver</strong>, please review and accept the following terms and conditions:',
											'woocommerce-shipping'
										),
										{
											strong: (
												<strong>
													{ __(
														'UPS Ground Saver®',
														'woocommerce-shipping'
													) }
												</strong>
											),
										}
									) }
								</p>
							</div>
						) }
				</Flex>
				<Flex direction="column" gap={ 4 } justify="space-between">
					<CheckboxControl
						// @ts-ignore
						label={ createInterpolateElement(
							__(
								'I agree to the <a>UPS® Terms of Service</a>.',
								'woocommerce-shipping'
							),
							{
								a: (
									<a
										href="https://www.ups.com/assets/resources/webcontent/en_US/ups_dap_supplemental_tc.pdf"
										target="_blank"
										rel="noreferrer"
									>
										{ __(
											'UPS® Terms of Service',
											'woocommerce-shipping'
										) }
									</a>
								),
							}
						) }
						value={ UPSDAP_TOS_TYPES.LEGAL }
						checked={ selectedItems.includes(
							UPSDAP_TOS_TYPES.LEGAL
						) }
						onChange={ toggleItem( UPSDAP_TOS_TYPES.LEGAL ) }
						__nextHasNoMarginBottom={ true }
					/>
					<CheckboxControl
						// @ts-ignore
						label={ createInterpolateElement(
							__(
								'I will not ship any <a>Prohibited Items</a> that UPS® disallows, nor any regulated items without the necessary permissions.',
								'woocommerce-shipping'
							),
							{
								a: (
									<a
										href="https://www.ups.com/us/en/support/shipping-support/shipping-special-care-regulated-items/prohibited-items.page"
										target="_blank"
										rel="noreferrer"
									>
										{ __(
											'Prohibited Items',
											'woocommerce-shipping'
										) }
									</a>
								),
							}
						) }
						value={ UPSDAP_TOS_TYPES.PROHIBITED_ITEMS }
						checked={ selectedItems.includes(
							UPSDAP_TOS_TYPES.PROHIBITED_ITEMS
						) }
						onChange={ toggleItem(
							UPSDAP_TOS_TYPES.PROHIBITED_ITEMS
						) }
						__nextHasNoMarginBottom={ true }
					/>
					<CheckboxControl
						// @ts-ignore
						label={ createInterpolateElement(
							__(
								'I also agree to the <a>UPS® Technology Agreement</a>.',
								'woocommerce-shipping'
							),
							{
								a: (
									<a
										href="https://www.ups.com/assets/resources/webcontent/en_US/UTA.pdf"
										target="_blank"
										rel="noreferrer"
									>
										{ __(
											'UPS Technology Agreement',
											'woocommerce-shipping'
										) }
									</a>
								),
							}
						) }
						checked={ selectedItems.includes(
							UPSDAP_TOS_TYPES.TECHNOLOGY_AGREEMENT
						) }
						value={ UPSDAP_TOS_TYPES.TECHNOLOGY_AGREEMENT }
						onChange={ toggleItem(
							UPSDAP_TOS_TYPES.TECHNOLOGY_AGREEMENT
						) }
						__nextHasNoMarginBottom={ true }
					/>
				</Flex>
			</Flex>
			<Spacer marginTop={ 6 } marginBottom={ 0 } />
			<Flex justify="flex-end">
				<Button
					variant="primary"
					disabled={
						selectedItems.length <
							Object.keys( UPSDAP_TOS_TYPES ).length ||
						isConfirming
					}
					isBusy={ isConfirming }
					onClick={ onConfirm }
					className="ups-confirm-button"
				>
					{ __( 'Confirm and continue', 'woocommerce-shipping' ) }
				</Button>
			</Flex>
			{ error && (
				<>
					<Spacer marginTop={ 4 } marginBottom={ 0 } />
					<Notice status="error" isDismissible={ false }>
						{ uniq( error.message ).map( ( m, index ) => (
							<p key={ index }>{ m }</p>
						) ) }
					</Notice>
				</>
			) }
		</Modal>
	);
};
