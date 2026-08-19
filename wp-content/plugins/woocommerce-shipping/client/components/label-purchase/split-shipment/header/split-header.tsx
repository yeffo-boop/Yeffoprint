import React from 'react';
import {
	__experimentalText as Text,
	Button,
	CheckboxControl,
	Flex,
} from '@wordpress/components';
import { closeSmall } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { getShipmentTitle } from '../../utils';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { SelectionHeader } from './selection-header';
import { findClosestIndex, isSubItem, normalizeSubItems } from 'utils';
import { LabelShipmentIdMap, ShipmentItem, ShipmentSubItem } from 'types';

interface SplitHeaderProps {
	selectAll: ( select: boolean ) => void;
	shipmentIndex: number;
	selections: ShipmentItem[];
	selectablesCount: number;
	isDisabled: boolean;
}

export const SplitHeader = ( {
	selectAll,
	shipmentIndex,
	selections,
	selectablesCount,
	isDisabled,
}: SplitHeaderProps ) => {
	const {
		shipment: {
			shipments,
			setShipments,
			setCurrentShipmentId,
			getShipmentType,
		},
		labels: { getShipmentsWithoutLabel },
	} = useLabelPurchaseContext();

	const removeShipment = () => {
		const shipmentItems = shipments[ shipmentIndex ];
		const newShipments = { ...shipments };
		delete newShipments[ shipmentIndex ];
		const possibleNewHives = getShipmentsWithoutLabel()
			.filter( ( shipmentId ) => shipmentId !== `${ shipmentIndex }` )
			.map( ( shipmentId ) => parseInt( shipmentId, 10 ) );

		const newHiveIndex = findClosestIndex(
			shipmentIndex,
			possibleNewHives,
			possibleNewHives[ 0 ]
		);

		// Partition items and subItems
		const { subItems, items } = shipmentItems.reduce(
			( acc, item ) => {
				if ( isSubItem( item ) && item.parentId ) {
					acc.subItems.push( item );
				} else {
					acc.items.push( item );
				}

				return acc;
			},
			{
				subItems: [] as ShipmentSubItem[],
				items: [] as ShipmentItem[],
			}
		);

		newShipments[ newHiveIndex ] = (
			newShipments[ newHiveIndex ] || []
		).concat( items );

		// Add subItems to the parent
		subItems.forEach( ( subItem ) => {
			Object.keys( newShipments ).forEach( ( shipmentId ) => {
				newShipments[ shipmentId ].forEach( ( item ) => {
					if ( item && item.id === subItem.parentId ) {
						item.subItems.push( subItem );
					}
				} );
			} );
		} );

		const shipmentsWithNormalizedSubItems = normalizeSubItems(
			newShipments
		) as Record< string, ShipmentItem[] >;

		const updatedShipmentIds: LabelShipmentIdMap = {};
		const normalizedShipments = {} as Record< string, ShipmentItem[] >;
		Object.entries( shipmentsWithNormalizedSubItems ).forEach(
			( [ key, shipment ], i ) => {
				const keyAsNumber = parseInt( key, 10 );
				normalizedShipments[ i ] = shipment;

				if ( keyAsNumber !== i ) {
					updatedShipmentIds[ key ] = i;
				}
			}
		);

		// Todo: define the type of normalizedShipments
		setShipments( { ...normalizedShipments }, updatedShipmentIds );
		/**
		 * We need to reset the current shipment id to the first shipment
		 * to prevent the app from using a shipmentId that no longer exists.
		 */
		setCurrentShipmentId( `0` );
	};

	/**
	 * We can only remove a shipment if there are more than one shipment without
	 * a valid label.
	 */
	const canRemoveShipment =
		Object.keys( getShipmentsWithoutLabel() ).length > 1;

	return (
		<Flex className="selectable-items__split-header">
			<CheckboxControl
				onChange={ selectAll }
				checked={ selections.length === selectablesCount }
				indeterminate={
					selections.length > 0 &&
					selections.length < selectablesCount
				}
				disabled={ isDisabled }
				aria-disabled={ isDisabled }
				title={ __(
					'Select all items in this shipment',
					'woocommerce-shipping'
				) }
				aria-label={ __(
					'Select all items in this shipment',
					'woocommerce-shipping'
				) }
				// Opting into the new styles for margin bottom
				__nextHasNoMarginBottom={ true }
			/>
			<SelectionHeader
				selectAll={ selectAll }
				selectionsCount={ selections.length }
				selectablesCount={ selectablesCount }
				isDisabled={ isDisabled }
			/>
			<Text upperCase>
				{ getShipmentTitle(
					shipmentIndex.toString(),
					Object.values( shipments ).length,
					getShipmentType( shipmentIndex.toString() )
				) }
			</Text>
			{ ! isDisabled && canRemoveShipment && (
				<Button
					icon={ closeSmall }
					onClick={ removeShipment }
					aria-label={ __(
						'Remove shipment',
						'woocommerce-shipping'
					) }
					title={ __( 'Remove shipment', 'woocommerce-shipping' ) }
				/>
			) }
		</Flex>
	);
};
