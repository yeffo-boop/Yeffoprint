import {
	RawShipmentSubItem,
	ShipmentItem,
	ShipmentSubItem,
} from './shipment-item.d';

export type Shipments<
	T extends ShipmentSubItem | RawShipmentSubItem = ShipmentSubItem,
> = Record< string, ShipmentItem< T >[] >;
