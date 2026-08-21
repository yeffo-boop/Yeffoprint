import { Button, FlexItem } from '@wordpress/components';
import { _n } from '@wordpress/i18n';
import { getShipmentSummaryText } from './utils';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { getCurrentOrder, setUrlParamValue } from 'utils';
import { recordEvent } from 'utils/tracks';
import { ShippingIcon } from './shipping-icon';

const labelsModalPersistKey = 'labels-modal';
const labelsModalPersistValue = 'open';

interface LabelPurchaseMetaBoxProps {
	setIsOpen: ( isOpen: boolean ) => void;
}

export const LabelPurchaseMetaBox = ( {
	setIsOpen,
}: LabelPurchaseMetaBoxProps ) => {
	const {
		labels: { purchasedLabelsProductIds, getShipmentsWithoutLabel },
	} = useLabelPurchaseContext();

	const order = getCurrentOrder();
	const count = order.total_line_items_quantity;
	const orderFulfilled = getShipmentsWithoutLabel().length === 0;

	const openLabelsModal = () => {
		setIsOpen( true );

		setUrlParamValue( labelsModalPersistKey, labelsModalPersistValue );

		const tracksProps = {
			order_fulfilled: orderFulfilled,
			order_product_count: count,
		};
		recordEvent( 'order_create_shipping_label_clicked', tracksProps );
	};

	return (
		<>
			<FlexItem className="wcshipping-shipping-label-meta-box__content">
				<ShippingIcon />
				{ getShipmentSummaryText(
					orderFulfilled,
					purchasedLabelsProductIds().length,
					count
				) }
			</FlexItem>
			<FlexItem className="wcshipping-shipping-label-meta-box__button-container">
				<Button variant="primary" onClick={ openLabelsModal }>
					{ orderFulfilled
						? _n(
								'View or add shipment',
								'View or add shipments',
								count,
								'woocommerce-shipping'
						  )
						: _n(
								'Create shipping label',
								'Create shipping labels',
								count,
								'woocommerce-shipping'
						  ) }
				</Button>
			</FlexItem>
		</>
	);
};
