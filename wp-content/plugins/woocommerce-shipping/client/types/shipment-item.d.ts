import { OrderItem } from './order-item';

export type ShipmentSubItem = ShipmentItem & {
	id: string;
	parentId: ShipmentItem.id;
};
export type RawShipmentSubItem = `${ ShipmentItem.id }-sub-${ number }`;

export interface ShipmentItem<
	SubItemType extends ShipmentSubItem | RawShipmentSubItem = ShipmentSubItem,
> extends OrderItem {
	subItems: SubItemType[];
	id: number | string;
	parentId?: number | string;
}
