import {
	ADDRESS_NORMALIZATION,
	ADDRESS_NORMALIZATION_FAILED,
	DELETE_ORIGIN_ADDRESS,
	DELETE_ORIGIN_ADDRESS_FAILED,
	FETCH_ORIGIN_ADDRESSES,
	INVALIDATE_ADDRESS_STORE,
	SET_DESTINATION_RESIDENTIAL,
	SET_STORE_ADDRESS_SYNC_STATUS,
	UPDATE_SHIPMENT_ADDRESS,
	UPDATE_SHIPMENT_ADDRESS_FAILED,
	VERIFY_ORDER_SHIPPING_ADDRESS,
	VERIFY_ORDER_SHIPPING_ADDRESS_FAILED,
	VERIFY_ORDER_SHIPPING_ADDRESS_START,
} from './action-types';
import {
	Action,
	AddressTypes,
	Destination,
	OriginAddress,
	SimpleAction,
} from 'types';
import { resetAddressNormalizationResponse } from './actions';

export interface ShippingAddressVerifyAction extends Action {
	type: VERIFY_ORDER_SHIPPING_ADDRESS;
	payload: {
		addressType: AddressTypes;
		success: boolean;
		normalizedAddress: Destination | OriginAddress;
		isTrivialNormalization: boolean;
		isVerified: boolean;
	};
}

export interface ShippingAddressVerifyFailedAction extends Action {
	type: VERIFY_ORDER_SHIPPING_ADDRESS_FAILED;
	payload: {
		addressType: AddressTypes;
	};
}

export interface ShippingAddressVerifyStartAction extends Action {
	type: VERIFY_ORDER_SHIPPING_ADDRESS_START;
	payload: {
		addressType: AddressTypes;
	};
}

export interface NormalizationAddressAction extends Action {
	type: ADDRESS_NORMALIZATION;
	payload: {
		addressType: AddressTypes;
		success: boolean;
		isTrivialNormalization: boolean;
		address: Destination | OriginAddress;
		normalizedAddress: Destination | OriginAddress;
		warnings?: Array< Record< string, string > >;
	};
}

export interface NormalizationAddressFailedAction extends Action {
	type: ADDRESS_NORMALIZATION_FAILED;
	payload: {
		addressType: AddressTypes;
		address: Destination | OriginAddress;
		errors?: Record< string, string > & {
			general?: string;
		};
		message?: string;
	};
}

export interface UpdateShipmentAddressAction extends Action {
	type: UPDATE_SHIPMENT_ADDRESS;
	payload: {
		addressType: AddressTypes;
		address: Destination | OriginAddress;
		isVerified: boolean;
	};
}

export interface UpdateShipmentAddressFailedAction extends Action {
	type: UPDATE_SHIPMENT_ADDRESS_FAILED;
	payload: {
		addressType: AddressTypes;
		message: string;
	};
}

export interface SetDestinationResidentialAction extends Action {
	type: SET_DESTINATION_RESIDENTIAL;
	payload: {
		residential: boolean | undefined;
	};
}

export interface AddOriginAddressAction extends Action {
	type: ADD_ORIGIN_ADDRESS;
	payload: {
		address: OriginAddress;
	};
}

export interface AddOriginAddressFailedAction extends Action {
	type: ADD_ORIGIN_ADDRESS_FAILED;
	payload: {
		error: {
			general: string;
		};
	};
}

export interface DeleteOriginAddressAction extends Action {
	type: DELETE_ORIGIN_ADDRESS;
	payload: {
		deletedId: string;
	};
}

export interface FetchOriginAddressesAction extends Action {
	type: FETCH_ORIGIN_ADDRESSES;
	payload: {
		addresses: OriginAddress[];
		isMainOriginInSyncWithStore?: boolean;
		formattedStoreAddress?: string;
		storeAddressDraft?: OriginAddress | null;
	};
}

export interface StateResetAction extends SimpleAction {
	type: typeof INVALIDATE_ADDRESS_STORE;
}

export interface SetStoreAddressSyncStatusAction extends Action {
	type: SET_STORE_ADDRESS_SYNC_STATUS;
	payload: {
		isMainOriginInSyncWithStore: boolean;
		formattedStoreAddress: string;
		storeAddressDraft?: OriginAddress | null;
	};
}

export type AddressActions =
	| ShippingAddressVerifyAction
	| ShippingAddressVerifyFailedAction
	| NormalizationAddressFailedAction
	| ReturnType< typeof resetAddressNormalizationResponse >
	| UpdateShipmentAddressAction
	| UpdateShipmentAddressFailedAction
	| NormalizationAddressAction
	| ShippingAddressVerifyStartAction
	| SetDestinationResidentialAction
	| SetStoreAddressSyncStatusAction;
