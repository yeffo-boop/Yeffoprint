import { mapValues } from 'lodash';
import { getConfig, removeShipmentFromKeys } from 'utils';
import { camelCaseKeys } from '../common';

export const getLabelOrigins = () => {
	const origins = getConfig().shippingLabelData.storedData.selected_origin;
	return origins
		? removeShipmentFromKeys(
				mapValues( origins, ( o ) => camelCaseKeys( o ) )
		  )
		: null;
};

export const getLabelDestinations = () => {
	const destinations =
		getConfig().shippingLabelData.storedData.selected_destination;

	return destinations
		? removeShipmentFromKeys(
				mapValues( destinations, ( d ) => camelCaseKeys( d ) )
		  )
		: null;
};
