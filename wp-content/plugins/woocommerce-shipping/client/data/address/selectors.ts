import { AddressTypes, Destination } from 'types';
import { camelCaseKeys, composeAddress, composeName, getConfig } from 'utils';
import { AddressState } from '../types';

export const getOrderDestination = ( state: AddressState ): Destination =>
	camelCaseKeys(
		state.destination?.address ??
			getConfig().shippingLabelData.storedData.destination
	);

export const getOriginAddresses = ( state: AddressState ) =>
	state.origin.addresses;

export const getIsAddressVerified = (
	state: AddressState,
	type: AddressTypes
) => {
	return state[ type ]?.isVerified ?? false;
};

export const getAddressVerifcationWarnings = (
	state: AddressState,
	type: AddressTypes
) => {
	return state[ type ]?.warnings ?? [];
};

export const getFormErrors = ( state: AddressState, type: AddressTypes ) => {
	return state[ type ]?.formErrors ?? {};
};

export const getNormalizedAddress = (
	state: AddressState,
	type: AddressTypes
) => {
	return state[ type ]?.normalizedAddress;
};

export const getSubmittedAddress = (
	state: AddressState,
	type: AddressTypes
) => {
	return state[ type ]?.submittedAddress;
};

export const getIsAddressTrivialNormalization = (
	state: AddressState,
	type: AddressTypes
) => {
	return state[ type ]?.isTrivialNormalization;
};

export const getAddressNeedsConfirmation = (
	state: AddressState,
	type: AddressTypes
) => {
	return state[ type ]?.addressNeedsConfirmation;
};

export const getPreparedDestination = ( state: AddressState ) => {
	/* eslint-disable camelcase */
	const { firstName, lastName, address1, address2, ...rawDestination } =
		getOrderDestination( state );
	const destination = {
		...rawDestination,
		name: composeName( {
			first_name: firstName,
			last_name: lastName,
			name: rawDestination.name,
		} ),
		address: composeAddress( {
			address_1: address1,
			address_2: address2,
			address: rawDestination.address,
		} ),
		// Explicit user override wins; otherwise infer from company
		// presence. Drives FedEx rate accuracy and service availability
		// (e.g., Ground Home Delivery).
		residential: rawDestination.residential ?? ! rawDestination.company,
	};
	// eslint-disable-next-line no-unused-vars
	return destination;
};

export function getIsAddressVerificationInProgress(
	state: AddressState,
	type: AddressTypes
) {
	return state[ type ]?.isAddressVerificationInProgress ?? false;
}

export const getIsMainOriginInSyncWithStore = ( state: AddressState ) =>
	state.isMainOriginInSyncWithStore;

export const getFormattedStoreAddress = ( state: AddressState ) =>
	state.formattedStoreAddress;

export const getStoreAddressDraft = ( state: AddressState ) =>
	state.storeAddressDraft;
