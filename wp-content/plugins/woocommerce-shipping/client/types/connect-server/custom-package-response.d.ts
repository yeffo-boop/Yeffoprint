import { PACKAGE_TYPES } from 'components/label-purchase/packages';

export interface CustomPackageResponse {
	name: string;
	boxWeight: string;
	id: string;
	type: PACKAGE_TYPES;
	isLetter: false;
	dimensions: string;
	is_user_defined: boolean;
}
