import { WeightUnit } from 'types';
import { WEIGHT_UNITS } from './constants';

export const convertWeightToUnit = (
	weight: number,
	oldUnit: WeightUnit,
	newUnit: WeightUnit
) => {
	if ( oldUnit === newUnit ) {
		return weight;
	}

	// Conversion factors to grams
	const toGrams: Record< WeightUnit, number > = {
		[ WEIGHT_UNITS.OZ ]: 28.3495,
		[ WEIGHT_UNITS.LBS ]: 453.592,
		[ WEIGHT_UNITS.KG ]: 1000,
		[ WEIGHT_UNITS.G ]: 1,
	};

	// Convert to grams then to target unit
	return ( weight * toGrams[ oldUnit ] ) / toGrams[ newUnit ];
};

export const minWeightThresholds: Record< WeightUnit, number > = {
	[ WEIGHT_UNITS.G ]: 1.5,
	[ WEIGHT_UNITS.OZ ]: 0.05,
	// We accept up to 2 decimal places for weight, so 0.01 is the minimum
	[ WEIGHT_UNITS.LBS ]: 0.01,
	[ WEIGHT_UNITS.KG ]: 0.01,
};
