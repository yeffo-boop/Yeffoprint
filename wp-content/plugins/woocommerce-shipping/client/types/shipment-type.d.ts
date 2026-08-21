import { SHIPMENT_TYPE } from 'utils/shipment';

export type ShipmentType =
	( typeof SHIPMENT_TYPE )[ keyof typeof SHIPMENT_TYPE ];
