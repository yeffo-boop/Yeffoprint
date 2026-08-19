import { CUSTOM_PACKAGE_TYPES } from 'components/label-purchase/packages';

export type CustomPackageType =
	( typeof CUSTOM_PACKAGE_TYPES )[ keyof typeof CUSTOM_PACKAGE_TYPES ];
