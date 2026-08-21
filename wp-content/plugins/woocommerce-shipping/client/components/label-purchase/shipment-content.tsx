import React, { JSX, useEffect, useRef } from 'react';
import { isEmpty } from 'lodash';
import { useSelect } from '@wordpress/data';
import {
	__experimentalDivider as Divider,
	__experimentalHeading as Heading,
	__experimentalSpacer as Spacer,
	Animate,
	Flex,
	FlexBlock,
	Notice,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	getCurrentOrder,
	hasUPSPackages,
	getSubItems,
	hasSubItems,
	getSelectablesCount,
} from 'utils';
import { labelPurchaseStore } from 'data/label-purchase';
import { Items } from 'components/label-purchase/items';
import { Packages } from 'components/label-purchase/packages';
import { ShipmentDetails } from 'components/label-purchase/details';
import { Hazmat } from './hazmat';
import { ShippingRates } from './shipping-service';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { Customs } from './customs';
import { PurchaseNotice } from './label';
import { PaymentButtons } from './purchase';
import { RefundedNotice } from './label/refunded-notice';
import { NoRatesAvailable } from './shipping-service/no-rates-available';
import { LABEL_PURCHASE_STATUS } from 'data/constants';
import { PurchaseErrorNotice } from './purchase/purchase-error-notice';
import { ShipmentItem, ShipmentSubItem } from 'types';
import { ITEMS_SECTION } from './essential-details/constants';
import { mainModalContentSelector } from './constants';
import { SelectableItems } from './split-shipment/selectable-items';
import { StaticHeader } from './split-shipment/header/static-header';
import { ServiceStatusNotices } from 'components/service-status';

interface ShipmentContentProps {
	items: unknown[];
	children?: string | JSX.Element | JSX.Element[] | boolean;
}

export const ShipmentContent = ( {
	items,
	children,
}: ShipmentContentProps ): JSX.Element => {
	const order = getCurrentOrder();

	const {
		labels: {
			hasPurchasedLabel,
			hasRequestedRefund,
			showRefundedNotice,
			getCurrentShipmentLabel,
			isCurrentTabPurchasingExtraLabel,
		},
		customs: { isCustomsNeeded },
		shipment: {
			shipments,
			selections,
			setSelection,
			getSelectionItems,
			currentShipmentId,
			getShipmentDestination,
			hasVariations,
			getCurrentShipmentIsReturn,
		},
		rates: { isFetching },
		packages: { isCustomPackageTab },
		hazmat: { getShipmentHazmat },
		essentialDetails: {
			focusArea: essentialDetailsFocusArea,
			setExtraLabelPurchaseCompleted,
		},
	} = useLabelPurchaseContext();
	const availableRates = useSelect(
		( select ) =>
			select( labelPurchaseStore ).getRatesForShipment(
				currentShipmentId
			),
		[ currentShipmentId ]
	);

	/**
	 * Manages auto-scrolling behavior when users click on options in the Essential Details checklist.
	 * When the items section link is clicked, smoothly scrolls the modal to bring the items section
	 * into view, adjusting for header height (72px) and shipment tabs (68px) when multiple shipments exist.
	 * Triggered by the Essential Details component updating essentialDetailsFocusArea to ITEMS_SECTION.
	 */
	const itemsRef = useRef< HTMLDivElement >( null );
	useEffect( () => {
		if ( essentialDetailsFocusArea === ITEMS_SECTION && itemsRef.current ) {
			if ( ! itemsRef.current ) {
				return;
			}
			const modalContent = document.querySelector(
				mainModalContentSelector
			);
			const header = modalContent?.querySelector( '.items-header' );
			const headerHeight = header
				? header.getBoundingClientRect().height
				: 0;
			const tabs = modalContent?.querySelector( '.shipment-tabs' );
			const tabsHeight =
				Object.keys( shipments ).length > 1 && tabs
					? tabs.getBoundingClientRect().height
					: 0;
			modalContent?.scrollTo( {
				left: 0,
				top: itemsRef.current.offsetTop - ( headerHeight + tabsHeight ),
				behavior: 'smooth',
			} );
		}
	}, [ essentialDetailsFocusArea, shipments ] );

	useEffect( () => {
		if ( isCurrentTabPurchasingExtraLabel() ) {
			setExtraLabelPurchaseCompleted( getSelectionItems()?.length > 0 );
		}
	}, [
		setExtraLabelPurchaseCompleted,
		isCurrentTabPurchasingExtraLabel,
		getSelectionItems,
	] );

	const addSelectionForShipment =
		( index: string | number ) =>
		( selection: ShipmentItem[] | ShipmentSubItem[] ) => {
			setSelection( { ...selections, [ index ]: selection } );
		};

	const selectAll = ( index: number | string ) => ( add: boolean ) => {
		if ( add ) {
			setSelection( {
				...selections,
				[ index ]: shipments[ index ]
					.map( ( item ) =>
						hasSubItems( item ) ? getSubItems( item ) : item
					)
					.flat() as ShipmentItem[],
			} );
		} else {
			setSelection( {
				[ currentShipmentId ]: [],
			} );
		}
	};

	return (
		<Flex
			className="label-purchase-modal__content"
			direction={ [ 'column', 'row' ] }
			expanded={ true }
			wrap={ true }
			gap={ 12 }
			align="flex-start"
		>
			<FlexBlock className="shipment-items">
				{ hasPurchasedLabel( false ) &&
					getCurrentShipmentLabel()?.status !==
						LABEL_PURCHASE_STATUS.PURCHASE_ERROR && (
						<>
							<PurchaseNotice />
							<Divider margin="12" />
						</>
					) }
				<PurchaseErrorNotice label={ getCurrentShipmentLabel() } />
				{ hasRequestedRefund() &&
					showRefundedNotice &&
					! hasPurchasedLabel() && (
						<>
							<RefundedNotice />
							<Spacer marginBottom="12" />
						</>
					) }

				<Flex className="items-header" ref={ itemsRef }>
					<Heading level={ 3 }>
						{ __( 'Items', 'woocommerce-shipping' ) }
					</Heading>
					{ children }
				</Flex>
				<Flex
					className="label-purchase-list-items"
					direction="column"
					expanded={ true }
				>
					{ isCurrentTabPurchasingExtraLabel() ||
					getCurrentShipmentIsReturn() ? (
						<Flex
							className="label-purchase__additional-label"
							direction="column"
							expanded={ true }
						>
							<Notice status="info" isDismissible={ false }>
								{ getCurrentShipmentIsReturn() ? (
									<>
										<strong>
											{ __(
												'This is a return shipment.',
												'woocommerce-shipping'
											) }
										</strong>{ ' ' }
										{ __(
											'You can select the products the customer wishes to return to help gauge the package criteria of the shipping label.',
											'woocommerce-shipping'
										) }
									</>
								) : (
									<>
										<strong>
											{ __(
												'Select the items you want to include in the new shipment.',
												'woocommerce-shipping'
											) }
										</strong>{ ' ' }
										{ __(
											'The following lists shows all the items in the current order. You can select multiple items from the list.',
											'woocommerce-shipping'
										) }
									</>
								) }
							</Notice>
							<Flex className="selectable-items__header">
								<StaticHeader
									hasVariations={ hasVariations }
									selectAll={ selectAll( currentShipmentId ) }
									hasMultipleShipments={ false }
									selections={
										selections[ currentShipmentId ]
									}
									selectablesCount={ getSelectablesCount(
										shipments[ currentShipmentId ]
									) }
								/>
							</Flex>
							<SelectableItems
								isSplit={ false }
								select={ addSelectionForShipment(
									currentShipmentId
								) }
								selections={
									selections[ currentShipmentId ] || []
								}
								orderItems={ items as ShipmentItem[] }
								selectAll={ selectAll( currentShipmentId ) }
								shipmentIndex={ parseInt(
									currentShipmentId,
									10
								) }
								isDisabled={ hasPurchasedLabel(
									true,
									true,
									currentShipmentId
								) }
							/>
						</Flex>
					) : (
						<>
							<StaticHeader hasVariations={ hasVariations } />
							<Items orderItems={ items } />
						</>
					) }
				</Flex>
				{ Boolean( getCurrentShipmentLabel()?.isLegacy ) === false && (
					<Flex className="label-purchase-hazmat">
						<Hazmat />
					</Flex>
				) }
				{ isCustomsNeeded() &&
					Boolean( getCurrentShipmentLabel()?.isLegacy ) ===
						false && (
						<>
							<Divider margin="12" />
							<Customs key={ currentShipmentId } />
							<Divider margin="12" />
						</>
					) }
				{ ! hasPurchasedLabel( false ) && (
					<>
						{ ! isCustomsNeeded() && <Divider margin="12" /> }
						<Packages />
						<Divider margin="12" />
						<ServiceStatusNotices />
						{ ! Boolean( availableRates ) && (
							<Animate
								type={ isFetching ? 'loading' : undefined }
							>
								{ ( { className } ) => (
									<NoRatesAvailable className={ className } />
								) }
							</Animate>
						) }
						{ availableRates && isEmpty( availableRates ) && (
							<Animate
								type={ isFetching ? 'loading' : undefined }
							>
								{ ( { className } ) => (
									<Notice
										status="info"
										isDismissible={ false }
										className={ className }
									>
										<p>
											{ sprintf(
												// translators: %1$s: HAZMAT part, %2$s: package part
												__(
													'No shipping rates were found based on the combination of %1$s%2$s and the total shipment weight.',
													'woocommerce-shipping'
												),
												getShipmentHazmat().isHazmat
													? __(
															'the selected HAZMAT category,',
															'woocommerce-shipping'
													  )
													: '',
												isCustomPackageTab()
													? __(
															'the package type, package dimensions',
															'woocommerce-shipping'
													  )
													: __(
															'the selected package',
															'woocommerce-shipping'
													  )
											) }
										</p>
										<p>
											{ sprintf(
												// translators: %1$s: HAZMAT part, %2$s: package part
												__(
													`We couldn't find a shipping service for the combination of %1$s%2$s and the total shipment weight. Please adjust your input and try again.`,
													'woocommerce-shipping'
												),
												getShipmentHazmat().isHazmat
													? __(
															'the selected HAZMAT category,',
															'woocommerce-shipping'
													  )
													: '',
												isCustomPackageTab()
													? __(
															'selected package type, package dimensions',
															'woocommerce-shipping'
													  )
													: __(
															'the selected package',
															'woocommerce-shipping'
													  )
											) }
										</p>
									</Notice>
								) }
							</Animate>
						) }

						{ Boolean( availableRates ) &&
							! isEmpty( availableRates ) &&
							( isFetching ? (
								<Animate type="loading">
									{ ( { className } ) => (
										<ShippingRates
											availableRates={ availableRates }
											isFetching={ isFetching }
											className={ className }
										/>
									) }
								</Animate>
							) : (
								<ShippingRates
									availableRates={ availableRates }
									isFetching={ isFetching }
								/>
							) ) }
					</>
				) }
				{ hasUPSPackages() && (
					<>
						<p className="upsdap-trademark-notice upsdap-trademark-notice--desktop">
							{ __(
								'UPS, the UPS brandmark, UPS Ready®, and the color brown are trademarks of United Parcel Service of America, Inc. All Rights Reserved.',
								'woocommerce-shipping'
							) }
						</p>
					</>
				) }
			</FlexBlock>
			<FlexBlock>
				<ShipmentDetails
					order={ order }
					destinationAddress={ getShipmentDestination() }
				/>
				<PaymentButtons order={ order } />
				{ hasUPSPackages() && (
					<p className="upsdap-trademark-notice upsdap-trademark-notice--mobile">
						{ __(
							'UPS, the UPS brandmark, UPS Ready®, and the color brown are trademarks of United Parcel Service of America, Inc. All Rights Reserved.',
							'woocommerce-shipping'
						) }
					</p>
				) }
			</FlexBlock>
		</Flex>
	);
};
