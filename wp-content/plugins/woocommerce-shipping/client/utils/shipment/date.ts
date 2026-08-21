import { mapValues, mapKeys, isObject } from 'lodash';
import { camelCaseKeys } from '../common';
import { getConfig } from '../config';
import { Label } from 'types';
import { getDateTS } from 'utils';

export const getShipmentDates = () => {
	const shipmentDates =
		getConfig().shippingLabelData.storedData.shipment_dates;

	if ( isObject( shipmentDates ) ) {
		return mapKeys( mapValues( shipmentDates, camelCaseKeys ), ( _, key ) =>
			key.replace( 'shipment_', '' )
		);
	}
	return {};
};

/**
 * Retrieves or calculates the default dates for a shipment.
 *
 * The shipping date always defaults to today on init, so reopening the label
 * purchase flow never carries over a stale or remembered date. The only
 * exception is an already purchased label, whose shipping date is fixed to the
 * date the label was created with (exposed as `label_date` by our provider).
 *
 * @param shipmentId           - The ID of the shipment
 * @param activePurchasedLabel - The active purchased label
 * @return An object containing the shipping date and optional estimated delivery date
 */
export const getShipmentDefaultDates = (
	shipmentId: string,
	activePurchasedLabel?: Label
): { shippingDate: Date; estimatedDeliveryDate?: Date } => {
	const shipmentDates = getShipmentDates();
	const estimatedDeliveryDate =
		shipmentDates[ shipmentId ]?.estimatedDeliveryDate;

	// For an already purchased label, keep the date the label was created with.
	const labelCreatedDate = activePurchasedLabel?.createdDate
		? new Date( activePurchasedLabel.createdDate ).toISOString()
		: undefined;

	return {
		shippingDate: labelCreatedDate
			? getDateTS( labelCreatedDate )
			: getDateTS( null, true ),
		// Not setting estimatedDeliveryDate if it's not defined
		estimatedDeliveryDate: estimatedDeliveryDate
			? getDateTS( estimatedDeliveryDate )
			: undefined,
	};
};
