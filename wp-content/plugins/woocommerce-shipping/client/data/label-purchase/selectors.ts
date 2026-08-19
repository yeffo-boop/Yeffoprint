import { RecordValues } from 'types';
import { removeShipmentFromKeys } from 'utils';
import { LabelPurchaseState } from '../types';
import { LABEL_RATE_TYPE } from '../constants';
import { isEmpty, mapKeys } from 'lodash';

export const getRatesForShipment = (
	state: LabelPurchaseState,
	shipmentId: string,
	type: RecordValues< typeof LABEL_RATE_TYPE > = LABEL_RATE_TYPE.DEFAULT
) => state.rates?.[ shipmentId ]?.[ type ];

export const getCustomsInformation = (
	state: LabelPurchaseState,
	shipmentId: string
) =>
	state.customsInformation &&
	! isEmpty( state.customsInformation?.[ `shipment_${ shipmentId }` ] )
		? removeShipmentFromKeys( state.customsInformation )[ shipmentId ]
		: null;

export const getOrderStatus = ( state: LabelPurchaseState ) =>
	state.order?.status;

export const getSelectedRateOptions = ( state: LabelPurchaseState ) =>
	mapKeys( state.selectedRateOptions, ( value, key ) =>
		key.replace( 'shipment_', '' )
	);
