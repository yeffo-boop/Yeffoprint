import {
	createReducer,
	getCurrentOrderShipTo,
	getFirstSelectableOriginAddress,
	getIsDestinationVerified,
	getOriginAddresses,
	getStoreOrigin,
	isOriginAddress,
} from 'utils';
import {
	getFormattedStoreAddress,
	isMainOriginInSyncWithStore,
	getStoreAddressDraft,
} from 'utils/config';
import {
	ADD_ORIGIN_ADDRESS,
	ADD_ORIGIN_ADDRESS_FAILED,
	ADDRESS_NORMALIZATION,
	ADDRESS_NORMALIZATION_FAILED,
	DELETE_ORIGIN_ADDRESS,
	FETCH_ORIGIN_ADDRESSES,
	RESET_ADDRESS_NORMALIZATION,
	SET_DESTINATION_RESIDENTIAL,
	SET_STORE_ADDRESS_SYNC_STATUS,
	STATE_RESET,
	UPDATE_SHIPMENT_ADDRESS,
	UPDATE_SHIPMENT_ADDRESS_FAILED,
	VERIFY_ORDER_SHIPPING_ADDRESS,
	VERIFY_ORDER_SHIPPING_ADDRESS_FAILED,
	VERIFY_ORDER_SHIPPING_ADDRESS_START,
} from './action-types';
import {
	AddOriginAddressAction,
	AddOriginAddressFailedAction,
	AddressActions,
	DeleteOriginAddressAction,
	FetchOriginAddressesAction,
	NormalizationAddressAction,
	NormalizationAddressFailedAction,
	SetDestinationResidentialAction,
	SetStoreAddressSyncStatusAction,
	ShippingAddressVerifyAction,
	ShippingAddressVerifyFailedAction,
	ShippingAddressVerifyStartAction,
	UpdateShipmentAddressAction,
	UpdateShipmentAddressFailedAction,
} from './types.d';
import { resetAddressNormalizationResponse } from './actions';
import { AddressState } from '../types';

export const getReducer = ( withDestination: boolean ) => {
	const getDefaultState = (): AddressState => {
		const originAddresses = getOriginAddresses();
		const firstSelectableAddress = getFirstSelectableOriginAddress();
		const storeAddressDraft = getStoreAddressDraft();

		// Provide a default empty address if none exist
		const defaultOriginAddress = firstSelectableAddress ?? {
			id: '',
			name: '',
			address: '',
			address1: '',
			address2: '',
			city: '',
			state: '',
			postcode: '',
			country: getStoreOrigin().country,
			phone: '',
			email: '',
			firstName: '',
			lastName: '',
			company: '',
			isVerified: false,
			defaultAddress: false,
		};

		const defaultState: AddressState = {
			origin: {
				addresses: originAddresses,
				address: defaultOriginAddress,
				isVerified: defaultOriginAddress.isVerified ?? false,
				isAddressVerificationInProgress: false,
				normalizedAddress: null,
				submittedAddress: null,
				isTrivialNormalization: null,
				addressNeedsConfirmation: false,
				formErrors: {},
			},
			storeOrigin: getStoreOrigin(),
			isMainOriginInSyncWithStore: isMainOriginInSyncWithStore(),
			formattedStoreAddress: getFormattedStoreAddress(),
			storeAddressDraft,
		} as const;

		if ( withDestination ) {
			defaultState.destination = {
				address: getCurrentOrderShipTo(),
				isVerified: getIsDestinationVerified(),
				isAddressVerificationInProgress: false,
				normalizedAddress: null,
				submittedAddress: null,
				isTrivialNormalization: null,
				addressNeedsConfirmation: false,
				formErrors: {},
			};
		}

		return defaultState;
	};

	const addressReducer = createReducer( getDefaultState() )
		.on(
			ADDRESS_NORMALIZATION,
			(
				state,
				{
					payload: {
						address,
						normalizedAddress,
						isTrivialNormalization,
						addressType,
						warnings,
					},
				}: NormalizationAddressAction
			) => ( {
				...state,
				[ addressType ]: {
					...state[ addressType ],
					normalizedAddress,
					isTrivialNormalization,
					addressNeedsConfirmation: true,
					submittedAddress: address,
					formErrors: {},
					isAddressVerificationInProgress: false,
					warnings,
				},
			} )
		)
		.on(
			ADDRESS_NORMALIZATION_FAILED,
			( state, { payload }: NormalizationAddressFailedAction ) => {
				const normalizationErrors: Record< string, string > = {
					...( payload.errors ?? {} ),
				};
				if ( payload.errors?.general ) {
					normalizationErrors.general = payload.errors.general;
				}
				if ( payload.message ) {
					normalizationErrors.general = payload.message;
				}
				return {
					...state,
					[ payload.addressType ]: {
						...state[ payload.addressType ],
						addressNeedsConfirmation: false,
						normalizedAddress: null,
						submittedAddress: payload.address,
						formErrors: normalizationErrors,
						isAddressVerificationInProgress: false,
					},
				};
			}
		)
		.on(
			RESET_ADDRESS_NORMALIZATION,
			(
				state,
				{
					payload: { addressType },
				}: ReturnType< typeof resetAddressNormalizationResponse >
			) => ( {
				...state,
				[ addressType ]: {
					...state[ addressType ],
					addressNeedsConfirmation: false,
				},
			} )
		)
		.on(
			UPDATE_SHIPMENT_ADDRESS,
			(
				state,
				{
					payload: { address, isVerified, addressType },
				}: UpdateShipmentAddressAction
			) => {
				let addressesMerger = {};
				if ( addressType === 'origin' && isOriginAddress( address ) ) {
					const addresses = state.origin.addresses.map(
						( originAddress ) => {
							if ( originAddress.id === address.id ) {
								return address;
							}
							// Clear defaultAddress from other addresses if the updated address is being set as default
							if (
								address.defaultAddress &&
								originAddress.defaultAddress
							) {
								return {
									...originAddress,
									defaultAddress: false,
								};
							}
							// Clear defaultReturnAddress from other addresses if the updated address is being set as default return
							if (
								address.defaultReturnAddress &&
								originAddress.defaultReturnAddress
							) {
								return {
									...originAddress,
									defaultReturnAddress: false,
								};
							}
							return originAddress;
						}
					);
					addressesMerger = { addresses };
				}

				return {
					...state,
					[ addressType ]: {
						...state[ addressType ],
						formErrors: {},
						isVerified,
						address,
						addressNeedsConfirmation: false,
						isAddressVerificationInProgress: false,
						...addressesMerger,
					},
				};
			}
		)
		.on(
			UPDATE_SHIPMENT_ADDRESS_FAILED,
			(
				state,
				{
					payload: { addressType, message },
				}: UpdateShipmentAddressFailedAction
			) => ( {
				...state,
				[ addressType ]: {
					...state[ addressType ],
					isVerified: false,
					addressNeedsConfirmation: false,
					formErrors: { general: message },
					isAddressVerificationInProgress: false,
				},
			} )
		)
		.on(
			ADD_ORIGIN_ADDRESS,
			( state, { payload: { address } }: AddOriginAddressAction ) => {
				let existingAddresses = state.origin.addresses;

				// Clear defaultAddress from other addresses if the new address is being set as default
				if ( address.defaultAddress ) {
					existingAddresses = existingAddresses.map( ( origin ) => ( {
						...origin,
						defaultAddress: false,
					} ) );
				}

				// Clear defaultReturnAddress from other addresses if the new address is being set as default return
				if ( address.defaultReturnAddress ) {
					existingAddresses = existingAddresses.map( ( origin ) => ( {
						...origin,
						defaultReturnAddress: false,
					} ) );
				}

				return {
					...state,
					origin: {
						...state.origin,
						formErrors: {},
						isVerified: true,
						address,
						addressNeedsConfirmation: false,
						addresses: [ ...existingAddresses, address ],
					},
				};
			}
		)
		.on(
			ADD_ORIGIN_ADDRESS_FAILED,
			( state, { payload: { error } }: AddOriginAddressFailedAction ) => {
				return {
					...state,
					origin: {
						...state.origin,
						formErrors: error,
					},
				};
			}
		)
		.on(
			VERIFY_ORDER_SHIPPING_ADDRESS_START,
			(
				state,
				{ payload: { addressType } }: ShippingAddressVerifyStartAction
			) => {
				return {
					...state,
					[ addressType ]: {
						...state[ addressType ],
						isAddressVerificationInProgress: true,
					},
				};
			}
		)
		.on(
			VERIFY_ORDER_SHIPPING_ADDRESS,
			(
				state,
				{
					payload: {
						normalizedAddress,
						isTrivialNormalization,
						isVerified,
						addressType,
					},
				}: ShippingAddressVerifyAction
			) => ( {
				...state,
				[ addressType ]: {
					...state[ addressType ],
					isVerified,
					normalizedAddress,
					isTrivialNormalization,
					isAddressVerificationInProgress: false,
				},
			} )
		)
		.on(
			VERIFY_ORDER_SHIPPING_ADDRESS_FAILED,
			(
				state,
				{ payload: { addressType } }: ShippingAddressVerifyFailedAction
			) => {
				return {
					...state,
					[ addressType ]: {
						...state[ addressType ],
						isVerified: false,
						isAddressVerificationInProgress: false,
					},
				};
			}
		)
		.on(
			DELETE_ORIGIN_ADDRESS,
			(
				state,
				{ payload: { deletedId } }: DeleteOriginAddressAction
			) => {
				return {
					...state,
					origin: {
						...state.origin,
						addresses: state.origin.addresses.filter(
							( originAddress ) => originAddress.id !== deletedId
						),
					},
				};
			}
		)
		.on(
			FETCH_ORIGIN_ADDRESSES,
			(
				state,
				{
					payload: {
						addresses,
						isMainOriginInSyncWithStore: inSync,
						formattedStoreAddress,
						storeAddressDraft,
					},
				}: FetchOriginAddressesAction
			) => ( {
				...state,
				origin: {
					...state.origin,
					addresses,
				},
				...( inSync !== undefined && {
					isMainOriginInSyncWithStore: inSync,
				} ),
				...( formattedStoreAddress !== undefined && {
					formattedStoreAddress,
				} ),
				...( storeAddressDraft !== undefined && {
					storeAddressDraft,
				} ),
			} )
		)
		.on(
			SET_STORE_ADDRESS_SYNC_STATUS,
			(
				state,
				{
					payload: {
						isMainOriginInSyncWithStore: inSync,
						formattedStoreAddress,
						storeAddressDraft,
					},
				}: SetStoreAddressSyncStatusAction
			) => ( {
				...state,
				isMainOriginInSyncWithStore: inSync,
				formattedStoreAddress,
				storeAddressDraft,
			} )
		)
		.on(
			SET_DESTINATION_RESIDENTIAL,
			(
				state,
				{ payload: { residential } }: SetDestinationResidentialAction
			) => {
				if ( ! state.destination ) {
					return state;
				}
				return {
					...state,
					destination: {
						...state.destination,
						address: {
							...state.destination.address,
							residential,
						},
					},
				};
			}
		)
		.on( STATE_RESET, () => ( {
			...getDefaultState(),
		} ) )
		.bind< AddressActions >();

	return addressReducer;
};
