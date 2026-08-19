import { ShipmentRecord } from './helpers';
import { RateWithParent } from './rate-with-parent';

export type SelectedRates = ShipmentRecord<
	RateWithParent & {
		extra_options?: RateExtraOptions;
	}
>;
