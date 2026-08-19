import { Destination, OriginAddress } from 'types';
import { ShipmentAddressState } from './shipment-address-state';
import { ADDRESS_TYPES } from 'data/constants';

export interface AddressState extends object {
	[ ADDRESS_TYPES.DESTINATION ]?: ShipmentAddressState< Destination >;
	[ ADDRESS_TYPES.ORIGIN ]: ShipmentAddressState & {
		addresses: OriginAddress[];
	};
	storeOrigin: Pick< OriginAddress, 'country' | 'state' >;
	isMainOriginInSyncWithStore: boolean;
	formattedStoreAddress: string;
	storeAddressDraft?: OriginAddress | null;
}
