import { apiFetch } from '@wordpress/data-controls';
import { select } from '@wordpress/data';
import { mapValues } from 'lodash';
import {
	getLabelPurchasePath,
	getLabelRefundPath,
	getLabelsStatusPath,
} from 'data/routes';
import { addressStore } from 'data/address';
import {
	LABEL_PURCHASE_SUCCESS,
	LABEL_STAGE_NEW_SHIPMENT_IDS,
	LABEL_STATUS_RESOLVED,
} from './action-types';
import {
	camelCaseKeys,
	camelCaseKeysRecursive,
	mapAddressForRequest,
	normalizeSelectionKey,
	syncPackageDimensionsToGlobalConfig,
} from 'utils';
import {
	CustomsState,
	HazmatState,
	LabelRequestPackages,
	LabelShipmentIdMap,
	OriginAddress,
	RateExtraOptions,
	RateWithParent,
	RefundResponse,
	RequestExtraOptions,
	RequestPackageWithCustoms,
	ResponseLabel,
	SelectedDestination,
	SelectedOrigin,
	SelectedRates,
	ShipmentRecord,
	UserMeta,
} from 'types';
import {
	LabelPurchaseSuccessAction,
	LabelStatusResolvedAction,
} from '../types.d';

/**
 * Purchase label or throw an error if it fails.
 */
export function* purchaseLabel(
	orderId: number,
	packages:
		| RequestPackageWithCustoms< LabelRequestPackages >[]
		| LabelRequestPackages[],
	shipmentId: string,
	selectedRate: RateWithParent,
	selectedRateOptions: RateExtraOptions,
	hazmatState: HazmatState,
	originAddress: OriginAddress,
	customsState: ShipmentRecord< CustomsState >,
	userMeta: Partial< UserMeta >,
	shipmentOptions?: RequestExtraOptions,
	isReturn = false,
	parentShipmentId?: string
): Generator<
	ReturnType< typeof apiFetch >,
	LabelPurchaseSuccessAction,
	{
		success: boolean;
		labels: ResponseLabel[];
		selected_rates: SelectedRates;
		selected_hazmat: HazmatState;
		selected_origin: SelectedOrigin;
		selected_destination: SelectedDestination;
		package_dimensions?: Record< string, unknown >;
	}
> {
	const origin = mapAddressForRequest( originAddress );

	const destination = select( addressStore ).getPreparedDestination();

	const {
		labels,
		selected_rates,
		selected_hazmat,
		selected_origin,
		selected_destination,
		package_dimensions,
	} = yield apiFetch( {
		path: getLabelPurchasePath( orderId ),
		method: 'POST',
		data: {
			async: true,
			// Todo: To be updated via  woocommerce-shipping/issues/859
			origin,
			destination,
			packages,
			selected_rate_options: selectedRateOptions,
			hazmat: hazmatState,
			customs: customsState,
			user_meta: userMeta,
			features_supported_by_client: [ 'upsdap', 'fedex' ],
			selected_rate: selectedRate,
			is_return: isReturn,
			shipment_options: shipmentOptions,
			parent_shipment_id: parentShipmentId,
		},
	} );

	const selectedOrigins = mapValues(
		normalizeSelectionKey( selected_origin ),
		( value ) => camelCaseKeys( value )
	);
	const selectedDestinations = mapValues(
		normalizeSelectionKey( selected_destination ),
		( value ) => camelCaseKeys( value )
	);

	// Sync package dimensions to global config as side effect
	syncPackageDimensionsToGlobalConfig( package_dimensions );

	return {
		type: LABEL_PURCHASE_SUCCESS,
		payload: {
			label: {
				[ shipmentId ]: labels.map( ( label ) =>
					camelCaseKeys( label )
				),
			},
			selectedRates: mapValues( selected_rates, camelCaseKeysRecursive ),
			selectedHazmat: normalizeSelectionKey( selected_hazmat ),
			selectedOrigins,
			selectedDestinations,
			packageDimensions: package_dimensions,
		},
	};
}

/**
 * Fetch label status or throw an error if it fails.
 */
export function* fetchLabelStatus(
	orderId: number,
	labelId: number
): Generator<
	unknown,
	LabelStatusResolvedAction,
	{
		success: boolean;
		label: ResponseLabel;
	}
> {
	const { label, success } = yield apiFetch( {
		path: getLabelsStatusPath( orderId, labelId ),
		method: 'GET',
	} );

	if ( ! success || ! label ) {
		throw new Error( "Can't properly fetch label status" );
	}
	return {
		type: LABEL_STATUS_RESOLVED,
		payload: camelCaseKeys( label ),
	};
}

/**
 * Refund label or throw an error if it fails.
 * @param orderId
 * @param labelId
 */
export function* refundLabel(
	orderId: number,
	labelId: number
): Generator<
	unknown,
	LabelStatusResolvedAction,
	{
		success: boolean;
		refund: RefundResponse;
		label: ResponseLabel;
	}
> {
	const { refund, success, label } = yield apiFetch( {
		path: getLabelRefundPath( orderId, labelId ),
		method: 'POST',
	} );

	if ( ! success || ! refund ) {
		throw new Error( 'There was a problem refunding the label' );
	}

	return {
		type: LABEL_STATUS_RESOLVED,
		payload: camelCaseKeys( label ),
	};
}

export const stageLabelsNewShipmentIds = (
	shipmentIdsToUpdate: LabelShipmentIdMap
) => ( {
	type: LABEL_STAGE_NEW_SHIPMENT_IDS,
	payload: shipmentIdsToUpdate,
} );
