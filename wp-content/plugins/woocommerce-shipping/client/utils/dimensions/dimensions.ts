import { DimensionUnit } from 'types';
import { DIMENSION_UNITS } from './constants';

export const convertDimensionToUnit = (
	dimension: number,
	oldUnit: DimensionUnit,
	newUnit: DimensionUnit
) => {
	if ( oldUnit === newUnit ) {
		return dimension;
	}

	// Conversion factors to millimeters (base unit)
	const toMillimeters: Record< DimensionUnit, number > = {
		[ DIMENSION_UNITS.MM ]: 1,
		[ DIMENSION_UNITS.CM ]: 10,
		[ DIMENSION_UNITS.M ]: 1000,
		[ DIMENSION_UNITS.IN ]: 25.4,
		[ DIMENSION_UNITS.YD ]: 914.4,
	};

	// Convert to millimeters then to target unit
	return ( dimension * toMillimeters[ oldUnit ] ) / toMillimeters[ newUnit ];
};
