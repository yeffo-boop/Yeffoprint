import { RATES_FETCH_FAILED, RATES_FETCHED } from './action-types';
import {
	Action,
	CamelCaseType,
	CustomPackageResponse,
	Label,
	LabelPurchaseError,
	LabelShipmentIdMap,
	Order,
	RateWithParent,
	RequestAddress,
	SimpleAction,
	RatesResponse,
} from 'types';
import { LABEL_PURCHASE_SUCCESS, LABEL_STATUS_RESOLVED } from './label';
import { PACKAGES_UPDATE, PACKAGES_UPDATE_ERROR } from './packages';
import { getPreparedDestination } from '../address/selectors';

export interface LabelPurchaseSuccessAction extends Action {
	type: LABEL_PURCHASE_SUCCESS;
	payload: {
		label: Record< string, Label[] >;
		selectedRates: Record< string, RateWithParent >;
		selectedHazmat: Record<
			string,
			{
				isHazmat: boolean;
				category: string;
			}
		>;
		selectedOrigins: Record< string, CamelCaseType< RequestAddress > >;
		selectedDestinations: Record<
			string,
			ReturnType< typeof getPreparedDestination >
		>;
		packageDimensions?: Record< string, unknown >;
	};
	error?: Record< string, LabelPurchaseError >;
}

export interface LabelStatusResolvedAction extends Action {
	type: LABEL_STATUS_RESOLVED;
	payload?: Label;
	error?: unknown;
}

export interface RatesFetchedAction extends Action {
	type: RATES_FETCHED;
	payload: RatesResponse;
}

export interface RatesFetchFailedAction extends Action {
	type: RATES_FETCH_FAILED;
	payload: Record< string, string >;
}

export interface RatesFetchAbortedAction extends Action {
	type: RATES_FETCH_ABORTED;
}

export interface PackageUpdateAction extends Action {
	type: PACKAGES_UPDATE;
	payload: {
		custom: CamelCaseType< CustomPackageResponse >[];
		predefined: Record< string, string[] >;
	};
}

export interface PackageUpdateFailedAction< ET = Record< string, string > >
	extends Action {
	type: PACKAGES_UPDATE_ERROR;
	payload: ET;
}

export interface StageLabelsNewShipmentIdsAction extends Action {
	type: LABEL_STAGE_NEW_SHIPMENT_IDS;
	payload: LabelShipmentIdMap;
}

export interface OrderStatusUpdatedAction extends Action {
	type: ORDER_STATUS_UPDATED;
	payload: Order;
}

export interface OrderStatusUpdatedFailedAction extends Action {
	type: ORDER_STATUS_UPDATE_FAILED;
	payload: Record< string, string >;
}

export interface RatesResetAction extends SimpleAction {
	type: RATES_RESET;
}

export interface StateResetAction extends SimpleAction {
	type: typeof STATE_RESET;
}

export type LabelPurchaseActions =
	| ReturnType< typeof resetAddressNormalizationResponse >
	| LabelPurchaseSuccessAction
	| RatesFetchFailedAction
	| PackageUpdateAction
	| PackageUpdateFailedAction
	| RatesFetchedAction
	| StageLabelsNewShipmentIdsAction
	| OrderStatusUpdatedAction
	| OrderStatusUpdatedFailedAction
	| RatesResetAction
	| RatesFetchAbortedAction;
