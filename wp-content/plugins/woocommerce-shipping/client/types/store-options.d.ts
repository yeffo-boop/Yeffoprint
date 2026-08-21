import { WeightUnit } from './weight-unit.d';
import { DimensionUnit } from './dimension-unit.d';

export interface StoreOptions {
	weight_unit: WeightUnit;
	currency_symbol: string;
	dimension_unit: DimensionUnit;
	origin_country: string;
}
