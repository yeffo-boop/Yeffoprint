import { AddressNormalization, OriginAddress } from '../../types';

export interface ShippingSettingsState {
	locations: {
		originAddresses: OriginAddress[];
		normalization?: AddressNormalization< OriginAddress >;
		errors?: AddressNormalization< OriginAddress >[ 'errors' ];
	};
}
