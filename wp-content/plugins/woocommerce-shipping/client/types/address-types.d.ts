import { ADDRESS_TYPES } from 'data/constants';
export type AddressTypes =
	( typeof ADDRESS_TYPES )[ keyof typeof ADDRESS_TYPES ];
