import { CheckboxControl, Flex } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ShipmentItem } from 'types';

interface StaticHeaderProps {
	hasVariations: boolean;
	selectAll?: ( isChecked: boolean ) => void;
	selections?: ShipmentItem[];
	selectablesCount?: number;
	hasMultipleShipments?: boolean;
	selectable?: boolean;
}

export const StaticHeader = ( {
	hasVariations,
	selectAll,
	selections = [],
	selectablesCount = 0,
	hasMultipleShipments = false,
	selectable = true,
}: StaticHeaderProps ) => (
	<Flex as="dl" gap={ 0 }>
		{ selectable && selectAll && (
			<CheckboxControl
				onChange={ selectAll }
				checked={ selections.length === selectablesCount }
				indeterminate={
					selections.length > 0 &&
					selections.length < selectablesCount
				}
				style={ {
					visibility: ! hasMultipleShipments ? 'visible' : 'hidden',
				} }
				// Opting into the new styles for margin bottom
				__nextHasNoMarginBottom={ true }
			/>
		) }
		<dt className="item-name">
			{ __( 'Product', 'woocommerce-shipping' ) }
		</dt>
		<dt className="item-quantity">
			{ __( 'Qty', 'woocommerce-shipping' ) }
		</dt>
		{ hasVariations && (
			<dt className="item-variation">
				{ __( 'Variation', 'woocommerce-shipping' ) }
			</dt>
		) }
		<dt className="item-dimensions"></dt>
		<dt className="item-weight">
			{ __( 'Weight', 'woocommerce-shipping' ) }
		</dt>
		<dt className="item-price">
			{ __( 'Price', 'woocommerce-shipping' ) }
		</dt>
	</Flex>
);
