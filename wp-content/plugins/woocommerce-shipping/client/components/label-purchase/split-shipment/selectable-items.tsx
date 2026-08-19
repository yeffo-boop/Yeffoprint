import React from 'react';
import { CheckboxControl, Flex } from '@wordpress/components';
import {
	getSelectablesCount,
	getSubItemIds,
	hasSubItems,
	isSubItem,
} from 'utils';
import { ShipmentItem, ShipmentSubItem } from 'types';
import { Items } from '../items';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { SelectionHeader, SplitHeader } from './header';

interface SelectableItemsProps {
	selections: ShipmentItem[];
	select: ( selection: ( ShipmentItem | ShipmentSubItem )[] ) => void;
	orderItems: ShipmentItem[];
	isSplit: boolean;
	selectAll: ( add: boolean ) => void;
	shipmentIndex: number;
	isDisabled: boolean;
}

export const SelectableItems = ( {
	selections,
	select,
	orderItems,
	isSplit,
	selectAll,
	shipmentIndex,
	isDisabled,
}: SelectableItemsProps ) => {
	const {
		labels: { isCurrentTabPurchasingExtraLabel },
		shipment: { shipments },
	} = useLabelPurchaseContext();

	const selectablesCount = getSelectablesCount( orderItems );

	const toggleSelection =
		( item: ShipmentItem | ShipmentSubItem ) => ( add: boolean ) => {
			let subject = item;
			let localSelections = [ ...selections ];
			if ( add && isSubItem( item ) ) {
				const parent = (
					shipments[ shipmentIndex ] || orderItems
				).find( ( { id } ) => id === item?.parentId );
				const areAllSubItemsSelected = ( parent?.subItems ?? [] )
					.filter( ( { id } ) => item.id !== id )
					.every( ( subItem ) =>
						localSelections.find( ( { id } ) => id === subItem.id )
					);

				if ( areAllSubItemsSelected && parent ) {
					subject = parent;
					const subjectSubItemIds = getSubItemIds( subject );
					localSelections = localSelections.filter(
						( { id } ) => ! subjectSubItemIds.includes( `${ id }` )
					);
				}
			}

			if (
				! add &&
				isSubItem( item ) &&
				selections.find( ( { id } ) => id === item.parentId )
			) {
				// At this stage we are sure that parent is defined
				const parent = orderItems.find(
					( { id } ) => id === item.parentId
				)!;
				localSelections = [ ...localSelections, ...parent!.subItems ];
			}

			const subItemIds = getSubItemIds( item );

			select( [
				...localSelections.filter(
					( { id } ) =>
						id !== item.id &&
						! subItemIds.includes( `${ id }` ) &&
						( isSubItem( item ) ? id !== item.parentId : true )
				),
				...( add ? [ subject ] : [] ),
			] );
		};

	const hasMultipleShipments = Object.values( shipments ).length > 1;
	return (
		<Items
			orderItems={ orderItems }
			isExpandable={ true }
			header={
				<>
					{ ( ! hasMultipleShipments ||
						isCurrentTabPurchasingExtraLabel() ) && (
						<Flex className="selection-header-wrapper">
							{ selections.length > 0 && (
								<SelectionHeader
									selectAll={ selectAll }
									selectionsCount={ getSelectablesCount(
										selections
									) }
									selectablesCount={ selectablesCount }
									isDisabled={ isDisabled }
								/>
							) }
						</Flex>
					) }
					{ isSplit && (
						<SplitHeader
							selectAll={ selectAll }
							selections={ selections }
							selectablesCount={ selectablesCount }
							shipmentIndex={ shipmentIndex }
							isDisabled={ isDisabled }
						/>
					) }
				</>
			}
			renderPrefix={ ( item: ShipmentItem | ShipmentSubItem ) => {
				const subItemIds = getSubItemIds( item );

				return (
					<CheckboxControl
						onChange={ toggleSelection( item ) }
						disabled={ isDisabled }
						checked={
							Boolean(
								selections.find( ( { id } ) => id === item.id )
							) ||
							( hasSubItems( item ) && // all of its subItems are selected
								item.subItems.every( ( { id: subItemId } ) =>
									selections.find(
										( { id } ) => id === subItemId
									)
								) ) ||
							( isSubItem( item ) && // it's a subItem and its parent is selected
								Boolean(
									isSubItem( item )
										? selections.find(
												( { id } ) =>
													id === item.parentId
										  )
										: false
								) ) ||
							false
						}
						indeterminate={
							subItemIds.some( ( subItemId ) =>
								selections.find(
									( { id } ) => id === subItemId
								)
							) &&
							! selections.find( ( { id } ) => id === item.id ) &&
							! subItemIds.every( ( subItemId ) =>
								selections.find(
									( { id } ) => subItemId === id
								)
							)
						}
						// Opting into the new styles for margin bottom
						__nextHasNoMarginBottom={ true }
					/>
				);
			} }
		/>
	);
};
