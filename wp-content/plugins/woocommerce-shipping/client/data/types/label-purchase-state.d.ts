import {
	CamelCaseType,
	Carrier,
	CustomPackageResponse,
	CustomsState,
	HazmatState,
	Label,
	LabelPurchaseError,
	Rate,
	RequestAddress,
	ShipmentRecord,
	SelectedRates,
} from 'types';
import { getPreparedDestination } from '../address/selectors';

export interface LabelPurchaseState extends object {
	rates?: Record<
		string,
		Record< keyof typeof LABEL_RATE_TYPE, Record< Carrier, Rate[] > >
	>;
	labels: Record< string, Label[] > | null;
	purchaseAPIErrors: Record< string, LabelPurchaseError >;
	selectedRates: SelectedRates | '';
	selectedHazmatConfig: HazmatState | '';
	selectedOrigins: Record< string, CamelCaseType< RequestAddress > > | null;
	selectedDestinations: Record<
		string,
		ReturnType< typeof getPreparedDestination >
	> | null;
	customsInformation: ShipmentRecord< CustomsState > | '';
	packages: {
		custom: CamelCaseType< CustomPackageResponse >[];
		predefined: Record< string, string[] >;
		errors: Record< string, string >;
	};
	order?: {
		status?: string;
		error?: string;
	};
	selectedRateOptions: ShipmentRecord< RateExtraOptions >;
}
