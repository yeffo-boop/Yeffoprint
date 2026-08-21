import { LabelPurchaseState } from '../../types';
import type { Label } from 'types';
import { mapKeys, mapValues } from 'lodash';
import { camelCaseKeysRecursive } from 'utils';
import { LABEL_PURCHASE_STATUS } from '../../constants';

/**
 * Helper to find the best label from an array of labels.
 * Prioritizes labels that are PURCHASED or PURCHASE_IN_PROGRESS over PURCHASE_ERROR.
 * This ensures that after a retry purchase, the new label is returned instead of the old error label.
 */
const findBestLabel = ( labels: Label[] ): Label | undefined => {
	// First, try to find a non-refunded label that is PURCHASED or PURCHASE_IN_PROGRESS
	const activeLabel = labels.find(
		( l ) =>
			! l.refund &&
			( l.status === LABEL_PURCHASE_STATUS.PURCHASED ||
				l.status === LABEL_PURCHASE_STATUS.PURCHASE_IN_PROGRESS )
	);
	if ( activeLabel ) {
		return activeLabel;
	}

	// Fallback: return the first non-refunded label (could be PURCHASE_ERROR or other status)
	return labels.find( ( l ) => ! l.refund );
};

export const getLabelByLabelId = (
	state: LabelPurchaseState,
	labelId: string | number
): Label | undefined => {
	const labels = state.labels;
	if ( ! labels ) {
		return undefined;
	}

	return Object.values( labels )
		.flat()
		.find( ( l ) => l.labelId === labelId );
};

export const getPurchasedLabel = (
	state: LabelPurchaseState,
	shipmentId: string | number
): Label | undefined => {
	const labels = state.labels?.[ shipmentId ] ?? [];
	return findBestLabel( labels );
};
export const getPurchasedLabels = ( state: LabelPurchaseState ) =>
	mapValues( state.labels, ( labels ) =>
		labels ? findBestLabel( labels ) : undefined
	);

export const getSelectedRates = ( state: LabelPurchaseState ) =>
	state.selectedRates
		? camelCaseKeysRecursive(
				mapKeys(
					state.selectedRates,
					( value, key ) => key.replace( 'shipment_', '' ) ?? key
				)
		  )
		: undefined;

export const getSelectedHazmatConfig = ( state: LabelPurchaseState ) =>
	state.selectedHazmatConfig
		? mapKeys(
				state.selectedHazmatConfig,
				( value, key ) => key.replace( 'shipment_', '' ) ?? key
		  )
		: undefined;

export const getPurchaseAPIError = (
	state: LabelPurchaseState,
	shipmentId: string | number
) => state.purchaseAPIErrors?.[ shipmentId ];

export const getRefundedLabel = (
	state: LabelPurchaseState,
	shipmentId: string | number
): Label | undefined => {
	const labels = state.labels?.[ shipmentId ] ?? [];
	return labels.find( ( l ) => l.refund );
};

export const getLabelOrigins = (
	state: LabelPurchaseState,
	shipmentId: string
) => state.selectedOrigins?.[ shipmentId ];

export const getLabelDestinations = (
	state: LabelPurchaseState,
	shipmentId: string
) => state.selectedDestinations?.[ shipmentId ];
