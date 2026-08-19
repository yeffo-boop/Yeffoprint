import { OrderItem, ShipmentItem, ShipmentSubItem } from 'types';

export const hasSubItems = ( item: OrderItem | ShipmentItem ) =>
	item.quantity > 1;
export const getSubItemId = ( item: ShipmentItem, index: number ) =>
	`${ item.id }-sub-${ index }`;
export const createSubItemOfCount = ( count: number, item: ShipmentItem ) =>
	new Array( count ).fill( 1 ).map( ( _, index ) => ( {
		...item,
		id: getSubItemId( item, index ),
		parentId: item.id,
		quantity: 1,
		subItems: [],
	} ) );

export const getSubItems = ( item: ShipmentItem ) =>
	hasSubItems( item ) ? createSubItemOfCount( item.quantity, item ) : [];

export const getSubItemIds = ( item: ShipmentItem ): string[] =>
	( item.subItems || getSubItems( item ) ).map( ( { id } ) => `${ id }` );

export const isSubItem = ( item: {
	id: string | number;
} ): item is ShipmentSubItem => `${ item.id }`.includes( 'sub' );

export const getSelectablesCount = ( items: ( OrderItem | ShipmentItem )[] ) =>
	items.reduce( ( acc, { quantity } ) => acc + quantity, 0 );

export const getParentIdFromSubItemId = ( id: string ): number =>
	isSubItem( { id } )
		? parseInt( id.split( '-sub-' )[ 0 ], 10 )
		: Number( id );
