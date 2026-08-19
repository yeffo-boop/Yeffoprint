import { LABEL_PURCHASE_STATUS } from 'data/constants';
import { Label } from 'types';

export const returnPurchasedLabel = (
	label: Label | undefined
): Label | null => {
	if ( ! label || label.status !== LABEL_PURCHASE_STATUS.PURCHASED ) {
		return null;
	}
	return label;
};
