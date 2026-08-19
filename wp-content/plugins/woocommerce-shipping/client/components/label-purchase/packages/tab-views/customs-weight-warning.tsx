import { Notice, __experimentalSpacer as Spacer } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useLabelPurchaseContext } from 'context/label-purchase';

export const CustomsWeightWarning = () => {
	const {
		customs: { isCustomsNeeded, getCustomsState },
		labels: { hasPurchasedLabel },
		weight: { getShipmentTotalWeight },
	} = useLabelPurchaseContext();

	const hasWarning = ( () => {
		if ( ! isCustomsNeeded() || hasPurchasedLabel( false ) ) {
			return false;
		}
		const customsState = getCustomsState();
		if ( ! customsState?.items ) {
			return false;
		}
		const customsItemsTotalWeight = customsState.items.reduce(
			( total, item ) => {
				const itemWeight = item.weight ? parseFloat( item.weight ) : 0;
				return total + itemWeight * item.quantity;
			},
			0
		);
		return getShipmentTotalWeight() < customsItemsTotalWeight;
	} )();

	return hasWarning ? (
		<>
			<Spacer />
			<Notice
				status="warning"
				isDismissible={ false }
				className="customs-weight-warning"
			>
				{ __(
					'Customs items weigh more than the total shipment weight. Update the total shipment weight before purchasing.',
					'woocommerce-shipping'
				) }
			</Notice>
		</>
	) : null;
};
