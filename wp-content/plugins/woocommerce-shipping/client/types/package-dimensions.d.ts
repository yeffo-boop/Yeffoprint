import { WeightUnit } from './weight-unit';
import { DimensionUnit } from './dimension-unit';
/**
 * Package dimensions and weight information from a shipment
 */
export interface PackageDimensions {
	/** Package weight value */
	package_weight?: number;
	/**
	 * Legacy unit field - was hardcoded to 'oz' regardless of actual unit.
	 * @deprecated Use package_weight_unit_snapshot instead
	 */
	package_weight_unit?: WeightUnit;
	/**
	 * Actual unit the weight was stored in (snapshot at purchase time).
	 * New data uses this field for accurate unit tracking.
	 */
	package_weight_unit_snapshot?: WeightUnit;
	/** Package length */
	package_length?: number;
	/** Package width */
	package_width?: number;
	/** Package height */
	package_height?: number;
	/**
	 * Legacy unit field - was hardcoded to 'in' regardless of actual unit.
	 * @deprecated Use package_dimensions_unit_snapshot instead
	 */
	package_dimensions_unit?: DimensionUnit;
	/**
	 * Actual unit the dimensions were stored in (snapshot at purchase time).
	 * New data uses this field for accurate unit tracking.
	 */
	package_dimensions_unit_snapshot?: DimensionUnit;
}
