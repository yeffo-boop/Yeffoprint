import { __experimentalText as Text } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { DataViews } from '@wordpress/dataviews/wp';
import type { Field } from '@wordpress/dataviews/wp';
import { Badge } from '@woocommerce/components';
import { OrderItem, ShipmentItem } from 'types';
import { formatCurrency, getCurrencyObject } from '../utils';
import { getCurrentOrder, getWeightUnit } from 'utils';
/* eslint-disable-next-line import/named */
import type { OrderShippingLine } from '@woocommerce/data';

type OrderItemType = OrderItem | ShipmentItem;

interface ItemMetaDataType {
	key: string;
	label: string;
	value: string;
}

interface TableDataType {
	id: string | null;
	name: string;
	weight: string | null;
	quantity: string | null;
	total: string;
	sku: string;
	image?: string;
	meta_data?: ItemMetaDataType[];
}

export const ItemsList = ( {
	items,
}: {
	items: OrderItem[] | ShipmentItem[];
} ): JSX.Element => {
	const renderLineItemMetaData = ( item: OrderItemType, limit = 3 ) => {
		const itemAsAny = item as any; // eslint-disable-line @typescript-eslint/no-explicit-any
		if ( ! itemAsAny.meta_data || itemAsAny.meta_data.length === 0 ) {
			return null;
		}

		const metaData = itemAsAny.meta_data.slice( 0, limit );
		const remaining = itemAsAny.meta_data.length - limit;

		return (
			<>
				{ metaData.map(
					( meta: { key: string; label: string; value: string } ) => (
						<Badge
							count={ 0 }
							key={ meta.key }
							title={ meta.label }
						>
							{ meta.value as string }
						</Badge>
					)
				) }
				{ remaining > 0 && (
					<Badge
						count={ 0 }
						title={ itemAsAny.meta_data
							.slice( limit )
							.map(
								( meta: {
									key: string;
									label: string;
									value: string;
								} ) => `${ meta.label }: ${ meta.value }`
							)
							.join( ', ' ) }
					>
						+{ remaining }
					</Badge>
				) }
			</>
		);
	};

	const renderLineItemSummary = ( { item }: { item: OrderItemType } ) => {
		if ( item.id === 'summary-row' || item.id === 'total-row' ) {
			return (
				<Text as="p" id={ item.id }>
					{ item.name }
				</Text>
			);
		} else if ( item.id.toString().startsWith( 'shipping-row' ) ) {
			return (
				<Text as="p" id={ String( item.id ) }>
					{ item.name }{ ' ' }
					<Text variant="muted" style={ { display: 'inline' } }>
						({ item.sku })
					</Text>
				</Text>
			);
		}
		return (
			<div
				style={ {
					display: 'flex',
					alignItems: 'center',
					gap: 4,
				} }
			>
				{ ( item as any ).image && ( // eslint-disable-line @typescript-eslint/no-explicit-any
					<img
						src={ ( item as any ).image } // eslint-disable-line @typescript-eslint/no-explicit-any
						alt={ ( item as any ).name } // eslint-disable-line @typescript-eslint/no-explicit-any
						style={ {
							width: 32,
							height: 32,
							objectFit: 'contain',
							border: '1px solid #eee',
							borderRadius: 4,
							marginRight: 8,
						} }
					/>
				) }
				<div>
					<Text
						as="p"
						style={ {
							fontWeight: 400,
							display: 'flex',
							alignItems: 'center',
							gap: 4,
							flexWrap: 'wrap',
						} }
					>
						{ item.name }
						{ renderLineItemMetaData( item ) }
					</Text>
					<Text as="p" variant="muted">
						{ item.sku }
					</Text>
				</div>
			</div>
		);
	};

	const tableData: TableDataType[] = [
		...items.map( ( item ) => ( {
			id: String( item.id ),
			name: item.name,
			weight: item.weight
				? parseFloat( item.weight ) * item.quantity + ''
				: '0',
			quantity: String( item.quantity ),
			total: item.total,
			sku: item.sku,
			image: ( item as any ).image, // eslint-disable-line @typescript-eslint/no-explicit-any
			meta_data: ( item as any ).meta_data, // eslint-disable-line @typescript-eslint/no-explicit-any
		} ) ),
	];

	// Append summary items to tableData as OrderItem type
	tableData.push( {
		id: 'summary-row',
		name: __( 'In this shipment', 'woocommerce-shipping' ),
		weight: items
			.map( ( item ) =>
				item.weight ? parseFloat( item.weight ) * item.quantity : 0
			)
			.reduce( ( total, weight ) => total + weight, 0 )
			.toString(),
		quantity: getCurrentOrder()?.total_line_items_quantity + ' items',
		total: items
			.map( ( item ) => parseFloat( item.total ) )
			.reduce( ( a, b ) => a + b, 0 )
			.toString(),
		sku: '',
	} as TableDataType );

	const shippingLines = getCurrentOrder()
		?.shipping_lines as OrderShippingLine[];
	// Append shipping row
	if ( shippingLines && shippingLines.length > 0 ) {
		shippingLines.forEach(
			( shippingLine: OrderShippingLine, index: number ) => {
				tableData.push( {
					id: `shipping-row-${ index }`,
					name: shippingLine.method_title ?? '-',
					weight: null,
					quantity: null,
					total: shippingLine.total || '0',
					sku:
						window.wcSettings?.shippingMethodTitles?.[
							shippingLine.method_id
						] ?? '',
				} as TableDataType );
			}
		);
	}

	// Order total row
	tableData.push( {
		id: 'total-row',
		name: __( 'Order total', 'woocommerce-shipping' ),
		weight: null,
		quantity: null,
		total: (
			items
				.map( ( item ) => parseFloat( item.total ) )
				.reduce( ( total, value ) => total + value, 0 ) +
			parseFloat( getCurrentOrder().total_shipping || '0' )
		).toString(),
		sku: '',
	} as TableDataType );

	return (
		<DataViews< TableDataType >
			view={ {
				type: 'table',
				layout: {
					styles: {
						order_line_item_summary: {
							align: 'start',
						},
						order_line_item_qty: {
							align: 'end',
						},
						order_line_item_weight: {
							align: 'end',
						},
						order_line_item_total: {
							align: 'end',
						},
					},
				},
				fields: [
					'order_line_item_summary',
					'order_line_item_qty',
					'order_line_item_weight',
					'order_line_item_total',
				],
			} }
			fields={
				[
					{
						id: 'order_line_item_summary',
						label: __( 'Products', 'woocommerce-shipping' ),
						render: renderLineItemSummary,
						enableSorting: false,
						enableHiding: false,
						filterBy: false,
					},
					{
						id: 'order_line_item_qty',
						label: __( 'Qty', 'woocommerce-shipping' ),
						type: 'text',
						enableSorting: false,
						enableHiding: false,
						getValue: ( { item }: { item: TableDataType } ) => {
							return item.quantity;
						},
						filterBy: false,
					},
					{
						id: 'order_line_item_weight',
						label: __( 'Total Weight', 'woocommerce-shipping' ),
						type: 'text',
						enableSorting: false,
						enableHiding: false,
						render: ( { item }: { item: TableDataType } ) => {
							const weight = item.weight;
							if ( weight === null ) {
								return '';
							}
							const weightUnit = getWeightUnit();
							return `${ weight } ${ weightUnit }`;
						},
						filterBy: false,
					},
					{
						id: 'order_line_item_total',
						label: __( 'Total Value', 'woocommerce-shipping' ),
						type: 'text',
						enableSorting: false,
						enableHiding: false,
						getValue: ( { item }: { item: TableDataType } ) => {
							const currency = getCurrencyObject();
							return (
								<Text
									variant={
										item.id === 'total-row'
											? undefined
											: 'muted'
									}
								>
									{ formatCurrency(
										parseFloat( item.total ),
										currency.code
									) }
								</Text>
							);
						},
						filterBy: false,
					},
				] as Field< TableDataType >[]
			}
			data={ tableData }
			isLoading={ false }
			onChangeView={ () => {} } // eslint-disable-line @typescript-eslint/no-empty-function
			search={ false }
			defaultLayouts={ {
				table: {},
			} }
			paginationInfo={ {
				totalItems: items.length,
				totalPages: 1,
			} }
			getItemId={ ( item: TableDataType ) => String( item.id ) }
		>
			<style>
				{ `
				.wcship-items-data-view.dataviews-view-table thead
				th:has(.dataviews-view-table-header-button)
				.dataviews-view-table-header-button {
					padding-left: 0;
					padding-right: 0;
				}
				.wcship-items-data-view colgroup col:nth-child(1) {
					width: 100% !important;
				}
				.wcship-items-data-view td:first-child,
				.wcship-items-data-view th:first-child {
					padding-inline-start: 0 !important;
				}
				.wcship-items-data-view th:last-child,
				.wcship-items-data-view td:last-child {
					padding-inline-end: 0 !important;
				}
				.wcship-items-data-view .dataviews-view-table__cell-content-wrapper:not(.dataviews-column-primary__media) {
					min-width: 10ch !important;
				}
				.wcship-items-data-view tr:has(#summary-row).is-hovered,
				.wcship-items-data-view tr:has(#shipping-row).is-hovered,
				.wcship-items-data-view tr:has(#total-row).is-hovered {
					background-color: transparent !important;
				}
				.wcship-items-data-view tr:has(#shipping-row) {
					border-top: 0px solid transparent !important;
				}
				` }
			</style>
			<DataViews.Layout className="wcship-items-data-view" />
		</DataViews>
	);
};
