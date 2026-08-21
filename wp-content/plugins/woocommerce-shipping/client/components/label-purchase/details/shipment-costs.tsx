import React from 'react';
import {
	__experimentalText as Text,
	BaseControl,
	Flex,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { camelCase, isEmpty } from 'lodash';

import {
	CamelCaseType,
	Label,
	RateExtraOptionNames,
	RateExtraOptions,
	RateWithParent,
} from 'types';
import {
	extraRateTypeToTitle,
	filterToNonSignatureExtraOptions,
	getPromoDiscount,
} from 'utils';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { Badge } from 'components/wp';

interface ExtraOptionLabelProps {
	option: CamelCaseType< RateExtraOptionNames >;
}

const ExtraOptionLabel = ( { option }: ExtraOptionLabelProps ) => (
	<Flex direction="row" gap={ 1 } as="section" justify="flex-start">
		<span className="subtotal-extra-bit" />
		{ extraRateTypeToTitle( option ) }
	</Flex>
);

interface ShipmentCostsProps {
	selectedRate: RateWithParent | null | undefined;
	label: Label | undefined;
	rateOptions: RateExtraOptions;
}

export const ShipmentCosts = ( {
	selectedRate,
	label,
	rateOptions,
}: ShipmentCostsProps ) => {
	const { storeCurrency } = useLabelPurchaseContext();
	const nonSignatureRateOptions = filterToNonSignatureExtraOptions(
		selectedRate?.shipmentOptions ?? rateOptions
	);

	let subTotal = selectedRate?.parent
		? selectedRate?.parent?.rate
		: selectedRate?.rate?.rate;

	if ( label?.isLegacy ) {
		subTotal = label.rate;
	}

	let subTotalLabel =
		( selectedRate?.parent
			? sprintf(
					// translators: %s is the parent rate title
					__( '%s (base fee)', 'woocommerce-shipping' ),
					selectedRate?.parent?.title
			  )
			: selectedRate?.rate?.title ) ??
		__( 'Subtotal', 'woocommerce-shipping' );

	const signatureCost =
		// If signature surcharge is persisted, use it
		rateOptions?.signature?.surcharge ??
		// If selected rate has a signature surcharge, use it
		selectedRate?.shipmentOptions?.signature?.surcharge ??
		// If selected rate is a signature rate, deduct the parent rate from the rate to get the signature cost
		( ( selectedRate?.rate?.type ?? '' )
			.toLowerCase()
			.includes( 'signature' )
			? selectedRate!.rate!.rate - ( selectedRate?.parent?.rate ?? 0 )
			: 0 );

	if ( label?.isLegacy ) {
		subTotal = label.rate;
		subTotalLabel = label.serviceName;
	}

	// If label is purchased, use the label rate
	let totalLabelCost = label?.rate ?? selectedRate?.rate?.rate ?? 0;

	/**
	 * If label is not purchased, add the extra option surcharges to the total
	 */
	if ( ! label && Object.keys( nonSignatureRateOptions ).length > 0 ) {
		totalLabelCost = Object.values( nonSignatureRateOptions ).reduce(
			( acc, { surcharge } ) => acc + surcharge,
			totalLabelCost
		);
	}

	const promoDiscount = label
		? label.promoDiscount
		: getPromoDiscount( totalLabelCost, selectedRate?.rate.promoId );

	// If a promo discount is applied and label is not purchased, subtract it from the total cost
	if ( promoDiscount && ! label ) {
		totalLabelCost -= promoDiscount;
	}

	return (
		<>
			<BaseControl
				id="sub-total"
				label={ subTotalLabel }
				// Opting into the new styles for margin bottom
				__nextHasNoMarginBottom={ true }
			>
				{ Boolean( subTotal ) && (
					<Text align="right">
						{ storeCurrency.formatAmount( subTotal! ) }
					</Text>
				) }
				{ ! Boolean( selectedRate ) && ! label && (
					<div className="cost-placeholder" />
				) }
			</BaseControl>
			{ ( Boolean( selectedRate?.parent ) ||
				Object.keys( nonSignatureRateOptions ).length > 0 ) && (
				<BaseControl
					id="sub-total-extra"
					label={
						<>
							{ selectedRate?.parent && (
								<ExtraOptionLabel
									option={ selectedRate.rate.type }
								/>
							) }

							{ Object.keys( nonSignatureRateOptions ).map(
								( option ) => (
									<ExtraOptionLabel
										key={ option }
										option={ camelCase( option ) }
									/>
								)
							) }
						</>
					}
					// Opting into the new styles for margin bottom
					__nextHasNoMarginBottom={ true }
				>
					<Flex direction="column" gap={ 1 }>
						{ selectedRate?.parent && (
							<Text align="right">
								{ storeCurrency.formatAmount( signatureCost ) }
							</Text>
						) }

						{ Object.entries( nonSignatureRateOptions ).map(
							( [ option, { surcharge } ] ) => (
								<Text key={ option } align="right">
									{ storeCurrency.formatAmount( surcharge ) }
								</Text>
							)
						) }
					</Flex>
				</BaseControl>
			) }
			{ promoDiscount && (
				<BaseControl
					id="promo-discount"
					label={ __( 'Discount', 'woocommerce-shipping' ) }
					__nextHasNoMarginBottom={ true }
				>
					<Badge intent="success">
						-{ storeCurrency.formatAmount( promoDiscount ) }
					</Badge>
				</BaseControl>
			) }
			<BaseControl
				id="total"
				label={
					<strong>{ __( 'Total', 'woocommerce-shipping' ) }</strong>
				}
				// Opting into the new styles for margin bottom
				__nextHasNoMarginBottom={ true }
			>
				{ ( ! isEmpty( selectedRate ) || ! isEmpty( label ) ) && (
					<Text weight={ 600 } align="right">
						{ storeCurrency.formatAmount( totalLabelCost ) }
					</Text>
				) }

				{ ! Boolean( selectedRate ) && ! label && (
					<div className="cost-placeholder" />
				) }
			</BaseControl>
		</>
	);
};
