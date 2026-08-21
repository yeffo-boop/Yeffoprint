import { groupBy, memoize, isEmpty, omit, camelCase } from 'lodash';
import { __ } from '@wordpress/i18n';
import { camelCaseKeys } from './common';
import {
	Hazmat,
	LabelRequestPackages,
	Rate,
	RatesResponse,
	RateWithParent,
	RequestPackage,
	ShipmentRatesResponse,
	LabelRateType,
	SnakeToCamelCase,
	ExtraOptionCharges,
} from 'types';
import { getRatesForShipment } from 'data/label-purchase/selectors';

const groupByServiceId = memoize( ( rates ) => groupBy( rates, 'serviceId' ) );

export const getExtraOptionRate = (
	serviceId: string,
	optionRates: Rate[],
	baseCost: number,
	type: SnakeToCamelCase< LabelRateType > = 'signatureRequired'
): ( Rate & { type: typeof type } ) | null => {
	const serviceRates = groupByServiceId( optionRates )[ serviceId ];

	// Skip if there was no rate for this service type.
	if ( ! serviceRates || serviceRates.length === 0 ) {
		return null;
	}

	const serviceRate = serviceRates[ 0 ];

	/**
	 * USPS returns signature rates that are not valid. These can be identified
	 * by the fact that the price with signature required is the same as the
	 * base cost. Priority Express service is the exception because signature
	 * can be required free-of-charge.
	 */
	if ( serviceRate.rate === baseCost && serviceId !== 'Express' ) {
		return null;
	}
	return {
		...serviceRate,
		type,
	};
};

export const groupRatesByCarrier = ( allRates: RatesResponse ) =>
	Object.entries( allRates ).reduce(
		( acc, [ shipmentId, shipmentRates ] ) => ( {
			[ shipmentId ]: Object.entries( shipmentRates ).reduce(
				( ratesAcc, [ key, { rates } ] ) => ( {
					...ratesAcc,
					[ key as LabelRateType ]: {
						...( ratesAcc[ key as LabelRateType ] || {} ),
						...groupBy( rates.map( camelCaseKeys ), 'carrierId' ),
					},
				} ),
				{} as ShipmentRatesResponse
			),
		} ),
		{}
	);

export const applyShipmentHazmat = (
	shipmentPackage: RequestPackage | LabelRequestPackages,
	shipmentHazmat: Hazmat
) => {
	if ( shipmentHazmat?.isHazmat && ! isEmpty( shipmentHazmat.category ) ) {
		return {
			...shipmentPackage,
			hazmat: shipmentHazmat.category,
		};
	}

	return shipmentPackage;
};

export const extraRateTypeToTitle = (
	extraType: SnakeToCamelCase< Exclude< LabelRateType, 'default' > >
) => {
	const mapping: Record<
		SnakeToCamelCase< Exclude< LabelRateType, 'default' > >,
		string
	> = {
		signatureRequired: __( 'Signature Required', 'woocommerce-shipping' ),
		adultSignatureRequired: __(
			'Adult Signature Required',
			'woocommerce-shipping'
		),
		carbonNeutral: __( 'Carbon Neutral', 'woocommerce-shipping' ),
		additionalHandling: __( 'Additional Handling', 'woocommerce-shipping' ),
		saturdayDelivery: __( 'Saturday Delivery', 'woocommerce-shipping' ),
	};

	return mapping[ extraType ];
};

export const filterToNonSignatureExtraOptions = < T >(
	options: ExtraOptionCharges< T >
): ExtraOptionCharges< T > => omit( options, 'signature' );

export const getExtraOptionSurcharge = (
	option: LabelRateType,
	extraOption: ReturnType< typeof getRatesForShipment >,
	selectedRate?: RateWithParent
) => {
	const extraOptionRate = selectedRate
		? getExtraOptionRate(
				selectedRate.rate.serviceId,
				extraOption?.[ selectedRate.rate.carrierId ] ?? [],
				selectedRate.rate.rate ?? 0,
				camelCase( option ) as SnakeToCamelCase< LabelRateType >
		  )?.rate ?? 0
		: 0;

	if ( extraOptionRate === 0 ) {
		return 0;
	}

	return (
		extraOptionRate -
		( selectedRate?.parent?.rate ?? selectedRate?.rate.rate ?? 0 )
	);
};
