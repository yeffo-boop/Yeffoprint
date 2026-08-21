import {
	__experimentalText as Text,
	Card,
	CardBody,
	Flex,
	Notice,
	Tooltip,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { mainModalContentSelector } from 'components/label-purchase/constants';
import { ITEMS_SECTION } from 'components/label-purchase/essential-details/constants';
import { StaticHeader } from 'components/label-purchase/split-shipment/header';
import { SelectableItems } from 'components/label-purchase/split-shipment/selectable-items';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { useEffect, useRef } from 'react';
import { ShipmentItem, ShipmentSubItem, Order } from 'types';
import {
	getSelectablesCount,
	getSubItems,
	getWeightUnit,
	hasSubItems,
} from 'utils';
import { ItemsList } from '../internal/items-list';
import { useCollapsibleCard } from '../internal/useCollapsibleCard';
import { formatCurrency, getCurrencyObject } from '../utils';
import { withBoundaryNext } from 'components/HOC/error-boundary/with-boundary-next';

const getItemsSummary = (
	items: ( ShipmentItem | ShipmentSubItem )[],
	order: Order
) => {
	const totalItems = order.total_line_items_quantity;

	const itemsPart = sprintf(
		/* translators: %d: number of items */
		_n( '%d item', '%d items', totalItems, 'woocommerce-shipping' ),
		totalItems
	);

	const totalWeight = items.reduce( ( total, item ) => {
		const itemWeight = item.weight ? parseFloat( item.weight ) : 0;
		return total + itemWeight * item.quantity;
	}, 0 );

	const weightUnit = getWeightUnit();

	const weightPart =
		totalWeight > 0 ? ` · ${ totalWeight } ${ weightUnit }` : '';

	const costPart = formatCurrency(
		parseFloat( order.total ),
		getCurrencyObject().code
	);

	const deliveryOptionPart = `${ order.shipping_methods } ${ formatCurrency(
		parseFloat( order.total_shipping ),
		getCurrencyObject().code
	) }`;

	return `${ itemsPart }${ weightPart } · ${ deliveryOptionPart } · ${ costPart } total`;
};

const ItemsCard = ( {
	order,
	items,
}: {
	order: Order;
	items: ( ShipmentItem | ShipmentSubItem )[];
} ) => {
	const { CardHeader, isOpen } = useCollapsibleCard( true );
	const {
		shipment: {
			shipments,
			selections,
			setSelection,
			currentShipmentId,
			hasVariations,
		},
		essentialDetails: { focusArea: essentialDetailsFocusArea },
		labels: { isCurrentTabPurchasingExtraLabel, hasPurchasedLabel },
	} = useLabelPurchaseContext();

	const addSelectionForShipment =
		( index: string | number ) =>
		( selection: ShipmentItem[] | ShipmentSubItem[] ) => {
			setSelection( { ...selections, [ index ]: selection } );
		};

	const selectAll = ( index: number | string ) => ( add: boolean ) => {
		if ( add ) {
			setSelection( {
				...selections,
				[ index ]: shipments[ index ]
					.map( ( item: ShipmentItem | ShipmentSubItem ) =>
						hasSubItems( item ) ? getSubItems( item ) : item
					)
					.flat() as ShipmentItem[],
			} );
		} else {
			setSelection( {
				[ currentShipmentId ]: [],
			} );
		}
	};

	/**
	 * Manages auto-scrolling behavior when users click on options in the Essential Details checklist.
	 * When the items section link is clicked, smoothly scrolls the modal to bring the items section
	 * into view, adjusting for header height (72px) and shipment tabs (68px) when multiple shipments exist.
	 * Triggered by the Essential Details component updating essentialDetailsFocusArea to ITEMS_SECTION.
	 */
	const itemsRef = useRef< HTMLDivElement >( null );
	useEffect( () => {
		if ( essentialDetailsFocusArea === ITEMS_SECTION && itemsRef.current ) {
			if ( ! itemsRef.current ) {
				return;
			}
			const modalContent = document.querySelector(
				mainModalContentSelector
			);
			const header = modalContent?.querySelector( '.items-header' );
			const headerHeight = header
				? header.getBoundingClientRect().height
				: 0;
			const tabs = modalContent?.querySelector( '.shipment-tabs' );
			const tabsHeight =
				Object.keys( shipments ).length > 1 && tabs
					? tabs.getBoundingClientRect().height
					: 0;
			modalContent?.scrollTo( {
				left: 0,
				top: itemsRef.current.offsetTop - ( headerHeight + tabsHeight ),
				behavior: 'smooth',
			} );
		}
	}, [ essentialDetailsFocusArea, shipments ] );

	return (
		<Card>
			<CardHeader iconSize={ 'small' } isBorderless>
				<Flex direction={ 'row' } align="space-between">
					<Text as="span" weight={ 500 } size={ 15 }>
						{ __( 'Items', 'woocommerce-shipping' ) }
					</Text>
					{ ! isOpen && (
						<Tooltip text={ getItemsSummary( items, order ) }>
							<Text
								as="span"
								weight={ 400 }
								size={ 13 }
								style={ {
									maxWidth: '350px',
									overflow: 'hidden',
									textOverflow: 'ellipsis',
									whiteSpace: 'nowrap',
								} }
							>
								{ getItemsSummary( items, order ) }
							</Text>
						</Tooltip>
					) }
				</Flex>
			</CardHeader>
			{ isOpen && (
				<CardBody style={ { padding: '0 24px' } }>
					{ isCurrentTabPurchasingExtraLabel() ? (
						<Flex
							className="label-purchase__additional-label"
							direction="column"
							expanded={ true }
						>
							<Notice status="info" isDismissible={ false }>
								<strong>
									{ __(
										'Select the items you want to include in the new shipment.',
										'woocommerce-shipping'
									) }
								</strong>{ ' ' }
								{ __(
									'The following lists shows all the items in the current order. You can select multiple items from the list.',
									'woocommerce-shipping'
								) }
							</Notice>
							<Flex className="selectable-items__header">
								<StaticHeader
									hasVariations={ hasVariations }
									selectAll={ selectAll( currentShipmentId ) }
									hasMultipleShipments={ false }
									selections={
										selections[ currentShipmentId ]
									}
									selectablesCount={ getSelectablesCount(
										shipments[ currentShipmentId ]
									) }
								/>
							</Flex>
							<SelectableItems
								isSplit={ false }
								select={ addSelectionForShipment(
									currentShipmentId
								) }
								selections={
									selections[ currentShipmentId ] || []
								}
								orderItems={ items as ShipmentItem[] }
								selectAll={ selectAll( currentShipmentId ) }
								shipmentIndex={ parseInt(
									currentShipmentId,
									10
								) }
								isDisabled={ hasPurchasedLabel(
									true,
									true,
									currentShipmentId
								) }
							/>
						</Flex>
					) : (
						<ItemsList items={ items } />
					) }
				</CardBody>
			) }
		</Card>
	);
};

export default withBoundaryNext( ItemsCard )();
