import './style.scss';
import { __ } from '@wordpress/i18n';
import React from 'react';
import { createInterpolateElement } from '@wordpress/element';
import { withBoundary } from 'components/HOC/error-boundary';
import { useLabelPurchaseContext } from 'context/label-purchase';
import {
	FOCUS_AREA_HAZMAT,
	CUSTOMS_SECTION,
	PACKAGE_SECTION,
	SHIPPING_SERVICE_SECTION,
	ITEMS_SECTION,
} from './constants';
import EssentialDetailListItem from './essential-detail-list-item';
import FlatButton from './flat-button';
import { recordEvent } from 'utils/tracks';

export const EssentialDetails = withBoundary( () => {
	const {
		essentialDetails,
		customs: { isCustomsNeeded },
		shipment: { getShipmentOrigin },
		rates: { getSelectedRate },
		hazmat: { getShipmentHazmat },
		packages: { isPackageSpecified },
		labels: { getCurrentShipmentLabel, isCurrentTabPurchasingExtraLabel },
	} = useLabelPurchaseContext();

	const onClickHandler = ( focusArea: string ) => {
		essentialDetails.setFocusArea( focusArea );
		recordEvent( 'label_purchase_essential_details_cta_clicked', {
			section_name: focusArea,
		} );
	};

	const CustomsCompletedSection = ( isCompleted: boolean ) => {
		return (
			<EssentialDetailListItem isCompleted={ isCompleted }>
				{ createInterpolateElement(
					__(
						'Complete <fb1>the Customs section</fb1>.',
						'woocommerce-shipping'
					),
					{
						fb1: (
							<FlatButton
								onClick={ () =>
									onClickHandler( CUSTOMS_SECTION )
								}
							/>
						),
					}
				) }
			</EssentialDetailListItem>
		);
	};

	const packageSectionSection = ( isCompleted: boolean ) => {
		return (
			<EssentialDetailListItem isCompleted={ isCompleted }>
				{ createInterpolateElement(
					__(
						'Complete <fb1>the Package section</fb1>.',
						'woocommerce-shipping'
					),
					{
						fb1: (
							<FlatButton
								onClick={ () =>
									onClickHandler( PACKAGE_SECTION )
								}
							/>
						),
					}
				) }
			</EssentialDetailListItem>
		);
	};

	const shippingServiceSection = ( isCompleted: boolean ) => {
		return (
			<EssentialDetailListItem isCompleted={ isCompleted }>
				{ createInterpolateElement(
					__(
						'Choose <fb1>a shipping service</fb1> after getting shipping rate.',
						'woocommerce-shipping'
					),
					{
						fb1: (
							<FlatButton
								onClick={ () =>
									onClickHandler( SHIPPING_SERVICE_SECTION )
								}
							/>
						),
					}
				) }
			</EssentialDetailListItem>
		);
	};

	const hazardousMaterialSection = ( isCompleted: boolean ) => {
		return (
			<EssentialDetailListItem isCompleted={ isCompleted }>
				{ createInterpolateElement(
					__(
						'Select a <fb>HAZMAT</fb> category.',
						'woocommerce-shipping'
					),
					{
						fb: (
							<FlatButton
								onClick={ () =>
									onClickHandler( FOCUS_AREA_HAZMAT )
								}
							/>
						),
					}
				) }
			</EssentialDetailListItem>
		);
	};

	const extraLabelPurchaseSection = ( isCompleted: boolean ) => {
		return (
			<EssentialDetailListItem isCompleted={ isCompleted }>
				{ createInterpolateElement(
					__(
						'Select <fb>the products</fb> you want to ship with the new label.',
						'woocommerce-shipping'
					),
					{
						fb: (
							<FlatButton
								onClick={ () => {
									onClickHandler( ITEMS_SECTION );
								} }
							/>
						),
					}
				) }
			</EssentialDetailListItem>
		);
	};

	const showEssentialDetails = () => {
		if ( getCurrentShipmentLabel()?.isLegacy ) {
			return false;
		}
		return ! getSelectedRate() || ! getShipmentOrigin().isVerified;
	};

	return (
		showEssentialDetails() && (
			<div className="essential-details">
				<div className="essential-details__h3">
					<h3>Essential details to provide</h3>
				</div>
				<ul className="essential-details__ul">
					{ isCurrentTabPurchasingExtraLabel() &&
						extraLabelPurchaseSection(
							essentialDetails.isExtraLabelPurchaseCompleted()
						) }
					{ getShipmentHazmat()?.isHazmat &&
						hazardousMaterialSection(
							Boolean( getShipmentHazmat()?.category )
						) }
					{ isCustomsNeeded() &&
						CustomsCompletedSection(
							essentialDetails.isCustomsCompleted()
						) }
					{ packageSectionSection( isPackageSpecified() ) }
					{ shippingServiceSection(
						essentialDetails.isShippingServiceCompleted()
					) }
				</ul>
			</div>
		)
	);
} )( 'EssentialDetails' );
