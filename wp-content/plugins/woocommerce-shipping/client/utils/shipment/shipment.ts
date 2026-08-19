import { isEmpty } from 'lodash';
import {
	createSubItemOfCount,
	getParentIdFromSubItemId,
	getSubItems,
	isSubItem,
} from 'utils/order-items';
import { getConfig } from 'utils/config';
import {
	OrderItem,
	ShipmentItem,
	ShipmentSubItem,
	Shipments,
	RawShipmentSubItem,
} from 'types';

const removeShipmentsWithNoMatchingItems = (
	shipments: Shipments< RawShipmentSubItem >,
	orderItems: OrderItem[]
): Shipments =>
	Object.entries( shipments ).reduce( ( acc, [ key, shipmentItems ] ) => {
		const items = shipmentItems.filter( ( shipmentItem ) => {
			const { id, parentId } = shipmentItem;
			// Check if this item or its parent exists in order items
			return orderItems.some( ( orderItem ) => {
				// For regular items, check direct id match
				if ( orderItem.id === id ) {
					return true;
				}
				// For subItems, check if parent exists
				if ( parentId !== undefined && orderItem.id === parentId ) {
					return true;
				}
				// For orphan subItems (no parentId), check if parent exists via id parsing
				if ( isSubItem( { id } ) ) {
					const derivedParentId = getParentIdFromSubItemId(
						`${ id }`
					);
					return orderItem.id === derivedParentId;
				}
				return false;
			} );
		} );

		return items.length
			? {
					...acc,
					[ key ]: items,
			  }
			: acc;
	}, {} );

export const getCurrentOrderShipments = ( config = getConfig() ): Shipments => {
	const {
		shipments,
		order: { line_items: orderItems },
	} = config;
	let storedShipments: Shipments< RawShipmentSubItem > = {};
	try {
		if ( shipments && typeof shipments === 'string' ) {
			storedShipments = JSON.parse( shipments );
		}
	} catch ( e ) {
		// eslint-disable-next-line no-console
		console.warn( e );
		// Reset to empty object on parse error
		storedShipments = {};
	}

	if ( isEmpty( storedShipments ) ) {
		return {
			0: orderItems.map( ( orderItem ) => ( {
				...orderItem,
				subItems: getSubItems( orderItem as ShipmentItem ),
			} ) ),
		};
	}

	// remove items from shipments that are not in orderItems anymore
	const shipmentsFilteredToAvailableOrderItems =
		removeShipmentsWithNoMatchingItems( storedShipments, orderItems );

	// create shipments with items as objects
	const shipmentsAugmentedWithDetails: Shipments = Object.entries(
		shipmentsFilteredToAvailableOrderItems
	).reduce(
		( acc, [ key, shipmentItems ] ) => ( {
			...acc,
			[ key ]: shipmentItems
				.map( ( shipmentItem ) => {
					if ( isSubItem( { id: shipmentItem.id } ) ) {
						const item = orderItems.find(
							( orderItem ) =>
								orderItem.id ===
								getParentIdFromSubItemId(
									`${ shipmentItem.id }`
								)
						);
						// Skip if no matching order item found for subItem
						if ( ! item ) {
							return null;
						}
						return {
							...item,
							id: shipmentItem.id, // setting id from shipmentItem to keep subItem id intact
							subItems: [],
							quantity: 1,
							parentId: getParentIdFromSubItemId(
								`${ shipmentItem.id }`
							),
						};
					}

					// For regular items, find by direct ID match
					const item = orderItems.find(
						( orderItem ) => orderItem.id === shipmentItem.id
					);

					// Skip if no matching order item found for regular item
					if ( ! item ) {
						return null;
					}

					const subItems = shipmentItem.subItems?.map( ( id ) => ( {
						...orderItems.find(
							( orderItem ) =>
								orderItem.id ===
								getParentIdFromSubItemId( `${ id }` )
						),
						id,
						parentId: getParentIdFromSubItemId( `${ id }` ),
						subItems: [],
						quantity: 1,
					} ) );

					return {
						...item,
						subItems,
						quantity: Math.max( 1, subItems?.length ),
					};
				} )
				.filter( ( item ) => item !== null ),
		} ),
		{}
	);

	const itemsFromShipments = Object.values(
		shipmentsAugmentedWithDetails
	).flat();
	const itemsNotInShipments = orderItems.filter(
		( orderItem ) =>
			! itemsFromShipments.find(
				( item ) => item.id.toString() === `${ orderItem.id }`
			)
	);

	shipmentsAugmentedWithDetails[ '0' ] = [
		/**
		 * Make sure a malformed shipments object doesn't throw an error
		 * if shipmentsAugmentedWithDetails the returned value will look like the early return when isEmpty( storedShipments ) is true
		 */
		...( shipmentsAugmentedWithDetails[ '0' ] ?? [] ),
		...itemsNotInShipments.map( ( item ) => ( {
			...item,
			subItems: getSubItems( item as ShipmentItem ),
		} ) ),
	];

	return shipmentsAugmentedWithDetails;
};

export const getNoneSelectedShipmentItems = (
	shipments: Shipments,
	selections: Shipments
): Shipments =>
	Object.entries( shipments ).reduce(
		( acc, [ key, shipmentItems ] ) => ( {
			...acc,
			[ key ]: shipmentItems
				.filter(
					( { id } ) =>
						! selections[ key ]?.find(
							( { id: itemId } ) => id === itemId
						)
				)
				.filter( ( { id, quantity } ) => {
					if ( selections[ key ] ) {
						const subItems = selections[ key ].filter(
							( maybeSubItems ) =>
								isSubItem( maybeSubItems ) &&
								getParentIdFromSubItemId( maybeSubItems.id ) ===
									id
						);

						return subItems.length < quantity;
					}

					return true;
				} )
				.map( ( item ) => {
					const subItems = item.subItems.filter(
						( { id: subItemId } ) =>
							! selections[ key ]?.find(
								( { id } ) => id === subItemId
							)
					);
					return {
						...item,
						subItems,
						quantity: Math.max(
							1,
							Math.min( item.subItems.length, subItems.length )
						),
					};
				} ),
		} ),
		{}
	);

/**
 * Merges subItems with their parent items if a subItem has a sibling in the same shipment
 *
 * @param {Object} shipments
 * @return {Object} normalizedShipments
 */
export const normalizeSubItems = ( shipments: Shipments ): Shipments => {
	const allItems = Object.values( shipments ).flat();
	return Object.entries( shipments ).reduce(
		( acc, [ key, shipmentItems ] ) => {
			const items = shipmentItems.reduce(
				( mergeAcc: ShipmentItem[], item ) => {
					if ( isSubItem( item ) ) {
						const parentId = getParentIdFromSubItemId( item.id );
						const siblings = shipmentItems.filter( ( { id } ) =>
							isSubItem( { id } )
								? getParentIdFromSubItemId( id.toString() ) ===
								  parentId
								: false
						);

						let parent = shipmentItems.find(
							( { id } ) => id === parentId
						);

						let subItems = siblings as ShipmentSubItem[];

						if ( ! parent ) {
							parent = allItems.find(
								( { id } ) => id === parentId
							);
						} else {
							// Parent exists, merge parent's existing subItems with orphan siblings
							const subItemsFromParent =
								parent.subItems.length > 0
									? parent.subItems
									: createSubItemOfCount(
											parent.quantity,
											parent
									  );

							// Check if any of the existing subItems or siblings have parentId
							const hasParentId =
								subItemsFromParent.some(
									( s: ShipmentSubItem ) =>
										s.parentId !== undefined
								) ||
								siblings.some(
									( s: ShipmentItem ) =>
										s.parentId !== undefined
								);

							if ( hasParentId ) {
								// Generate full subItems with parentId (for edge cases)
								const totalQuantity =
									parent.quantity + siblings.length;
								subItems = createSubItemOfCount(
									totalQuantity,
									{
										...parent,
										id: parentId,
									}
								) as ShipmentSubItem[];
							} else {
								// Preserve original structure without parentId (for main cases)
								subItems = [
									...subItemsFromParent,
									...siblings,
								].map( ( sI, index ) => {
									const cleanSubItem = { ...sI };
									delete ( cleanSubItem as ShipmentSubItem )
										.parentId;
									return {
										...cleanSubItem,
										id: `${ parentId }-sub-${ index }`,
									} as ShipmentSubItem;
								} );
							}
						}

						return [
							...mergeAcc.filter( ( { id } ) => id !== parentId ),
							{
								...( parent ?? item ),
								id: parentId,
								subItems,
								quantity: subItems.length,
							},
						];
					}

					const existingItem = mergeAcc.find(
						( { id } ) => id === item.id
					);
					const quantity =
						item.quantity + ( existingItem?.quantity ?? 0 );
					return [
						...mergeAcc.filter( ( { id } ) => id !== item.id ),
						{
							...item,
							quantity,
							subItems: getSubItems( {
								...( existingItem ?? item ),
								quantity,
							} ),
						},
					];
				},
				[] as ShipmentItem[]
			);

			return {
				...acc,
				[ key ]: items,
			};
		},
		{}
	);
};

export const removeEmptyShipments = ( shipments: Shipments ): Shipments => {
	const { normalizedIndices } = Object.entries( shipments ).reduce(
		( acc, [ , shipmentItems ] ) => {
			if ( shipmentItems.length > 0 ) {
				acc.normalizedIndices[ acc.previousIndex ] = shipmentItems;
				acc.previousIndex += 1;
			}

			return acc;
		},
		{
			normalizedIndices: {} as Shipments,
			previousIndex: 0,
		}
	);

	return normalizedIndices;
};

/**
 * Normalizes shipment indices to be sequential by removing empty shipments
 * also normalizes subItems by merging them with their parent items if
 * a subItem has a sibling in the same shipment
 *
 * @param {Object} shipments
 * @return {Object} { normalizedShipments, keyMapping }
 */
export const normalizeShipments = (
	shipments: Shipments
): {
	normalizedShipments: Shipments;
	keyMapping: Record< string, string >;
} => {
	// First remove empty shipments and get the key mapping
	const filteredShipments = Object.entries( shipments ).filter(
		( [ , shipmentItems ] ) => shipmentItems.length > 0
	);

	const keyMapping: Record< string, string > = {};
	const normalizedIndices: Shipments = {};

	filteredShipments.forEach( ( [ originalKey, shipmentItems ], index ) => {
		const newKey = index.toString();
		keyMapping[ originalKey ] = newKey;
		normalizedIndices[ newKey ] = shipmentItems;
	} );

	const normalizedShipments = normalizeSubItems( normalizedIndices );

	return { normalizedShipments, keyMapping };
};
