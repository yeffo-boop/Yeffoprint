import type { Package } from './package';
import type { CustomPackage } from './custom-package';
import { CamelCaseType } from './helpers';
import { PackageDimensions } from './package-dimensions';

export interface ReturnShipmentInfo {
	isReturn: boolean;
	parentShipmentId?: string;
	preservedPackage?: CamelCaseType< PackageDimensions > & {
		totalWeight?: number;
		isCustomPackageTab?: boolean;
		customPackage?: CustomPackage;
		selectedPackage?: Package;
		packageName?: string;
		packageId?: string | number;
	};
	/**
	 * Package dimensions and weight from the original shipment,
	 * used to pre-populate return label creation
	 */
	originalPackageDetails?: PackageDimensions;
}
