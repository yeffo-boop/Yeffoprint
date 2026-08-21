import { OriginAddress } from 'types';

export interface ShipmentAddressState< T = OriginAddress > {
	address: T;
	isVerified: boolean;
	normalizedAddress: T | null;
	submittedAddress: T | null;
	isTrivialNormalization: boolean | null;
	addressNeedsConfirmation: boolean;
	isAddressVerificationInProgress?: boolean;
	formErrors: Record< string, string >;
	warnings?: Array< Record< string, string > >;
}
