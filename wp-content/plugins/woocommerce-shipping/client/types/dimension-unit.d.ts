import { DIMENSION_UNITS } from '../utils';

export type DimensionUnit =
	( typeof DIMENSION_UNITS )[ keyof typeof DIMENSION_UNITS ];
