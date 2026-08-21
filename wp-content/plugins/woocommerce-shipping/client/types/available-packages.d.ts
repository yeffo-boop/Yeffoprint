import { Package } from './package';

export interface AvailablePackages {
	[ carrierId: string ]: {
		[ groupId: string ]: {
			title: string;
			definitions: Package[];
		};
	};
}
